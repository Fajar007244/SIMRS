# syntax=docker/dockerfile:1.7
# ===========================================================================
# SIMRS RSP Rotinsulu - production image (Laravel 12 + Inertia 2 + Vue 3).
#
# THREE stages, deliberately separate, because the two build toolschains are
# mutually incompatible in one image:
#
#   1. assets  (node:22-alpine)  -> compiles public/build/**
#   2. vendor  (composer:2)      -> composer install --no-dev, optimised classmap
#   3. runtime (php:8.2-fpm-alpine + nginx) -> the only stage that ships.
#
# WHY NOT ONE STAGE: a single stage would leave the ~450 MB Node toolchain and
# the dev Composer packages inside the final image. Splitting stages also means
# the runtime layer cache survives code-only changes: editing app/ does not
# re-run npm ci or composer install.
#
# WHY node:22-alpine AND NOT node:24 (the local version):
#   * Vite 7 requires Node ^20.19 || >=22.12. Node 22.12+ satisfies that; the
#     newest 22.x alpine tag does too. So 22 is fully supported, not a downgrade.
#   * node:22-alpine is 6.21-alpine based => ~55 MB, versus node:24-alpine
#     (Alpine 3.22) at ~90 MB. Since the assets stage result is just a few MB of
#     hashed JS/CSS, the base image is thrown away immediately, so size only
#     affects BUILD TIME and cache validity, not the shipped image.
#   * Even-numbered Node lines have a longer LTS support window, so a surprise
#     runtime break in this build stage is less likely.
#   * The build toolchain does NOT need to match the local Node: it compiles
#     ES2020-compatible output either way. Node 24 locally is fine.
#   * Pinned, NOT :latest. An unpinned build stage means a routine upstream
#     release can change your build output without a single commit from you.
#
# Build:  docker build -t simrs-ews .
# Run:    docker run --rm -p 8080:8080 --env-file .env.production simrs-ews
# ===========================================================================

# ----------------------------------------------------------------------------
# STAGE 1 - assets
# ----------------------------------------------------------------------------
FROM node:22.21-alpine AS assets

WORKDIR /build

# libc6-compat is required by esbuild (Vite 7 bundles it) on Alpine: without
# it esbuild's linux binary aborts with "not found" even though the file exists.
RUN apk add --no-cache libc6-compat

# Copy manifests ONLY, so this layer is reused whenever dependencies are
# unchanged and only resources/ or vite.config.js changed.
COPY package.json package-lock.json ./

# `npm ci` (not `npm install`): it installs the exact tree in package-lock.json
# and fails loudly if package.json and the lockfile disagree. `npm install` would
# silently update the lockfile and produce a build that nobody can reproduce.
RUN npm ci --no-audit --no-fund

COPY resources/ ./resources/
COPY vite.config.js postcss.config.js tailwind.config.js ./

# The real build. Produces public/build/manifest.json, which is the file Laravel
# reads at runtime to resolve @vite() to a hashed filename.
#
# vite.config.js declares `base` via laravel-vite-plugin, so manifest.json is
# written to public/build/. Vite empties its outDir first, which is exactly what
# we want: no stale hashed assets survive into the image.
RUN npm run build

# Sanity gate. A missing manifest.json is THE classic production failure for
# Inertia: the app boots fine, then every page 500s with
# "Vite manifest not found at .../build/manifest.json". Failing here in the
# build log is far better than failing in production traffic.
RUN test -f public/build/manifest.json \
    || (echo "FATAL: public/build/manifest.json tidak dihasilkan oleh vite build" >&2; exit 1)

# ----------------------------------------------------------------------------
# STAGE 2 - vendor
# ----------------------------------------------------------------------------
FROM composer:2.8 AS vendor

WORKDIR /build

# composer:2.8 is based on PHP 8.3/8.4. The app requires php ^8.2, so resolution
# is fine, and the platform check runs against the RUNTIME image's 8.2 when the
# app boots. We deliberately do NOT add "platform": {"php": "8.2.12"} to
# composer.json, because composer.json is owned by another agent and adding a
# platform pin would silently make a future PHP bump look like a conflict.
COPY composer.json composer.lock ./

# --no-scripts is MANDATORY here, not an optimisation.
#   composer.json declares `post-autoload-dump: @php artisan package:discover`.
#   At this point there is no .env and no vendor/../bootstrap cache, so running
#   artisan would either fail outright or, worse, write a package manifest
#   generated without APP_KEY -- and that file is then shipped in the image.
#   The scripts are run deliberately LATER, from a real runtime environment,
#   by the entrypoint.
#
# --no-autoloader: we install files only, then generate the classmap after the
#   application source is in place (see the second RUN below). The classmap must
#   contain App\ classes, so it cannot be built in this step.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

# The application source, needed so the authoritative autoloader can be dumped
# against the REAL App\ tree.
COPY . .

# Now that app/, config/, and all packages are present, build the classmap and
# run package discovery. `package:discover` writes
# bootstrap/cache/packages.php, which Laravel reads on every request to know
# which providers to register; without it the container still boots but the
# package manifest is built at runtime on the first request.
#
# The --no-scripts flag is intentionally NOT repeated: this is the one place
# where the composer script SHOULD run, and .env is not needed for
# `package:discover`.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ----------------------------------------------------------------------------
# STAGE 3 - runtime
# ----------------------------------------------------------------------------
FROM php:8.2.31-fpm-alpine AS runtime

# nginx serves /build/ and proxies PHP; curl is for HEALTHCHECK; tzdata so the
# Indonesian timezone can be selected; postgresql-libs/client for pdo_pgsql;
# libzip + oniguruma for the zip and mbstring extensions.
# Runtime libraries AND the -dev headers.
#
# The -dev packages are needed at BUILD time and are kept on purpose rather than
# removed in a later layer: dropping them would require a second `apk del` RUN
# and a `docker-php-clean` pass, and the result is only ~40 MB smaller on an
# image that already carries a full PHP + nginx toolchain. Reliability wins.
#
#   libpq / postgresql-dev -> pdo_pgsql   (libpq-fe.h, libpq.so)
#   oniguruma-dev         -> mbstring    (needs the regex engine, not just a lib)
#   libzip-dev            -> zip         (zip headers)
#   icu-dev               -> intl        (ICU data headers, not just icu-libs)
RUN apk add --no-cache \
        nginx \
        curl \
        ca-certificates \
        tzdata \
        postgresql-libs \
        postgresql-dev \
        oniguruma \
        oniguruma-dev \
        libzip \
        libzip-dev \
        icu-libs \
        icu-dev \
        $PHPIZE_DEPS

# PHP extensions. Installed as separate apk packages where possible because
# they come from the distro and are smaller and better tested than compiling
# from source in phpize.
#
# REQUIRED, mapped to what breaks when missing:
#   pdo_pgsql    -> "could not find driver" on every query in production
#   pdo_sqlite   -> `php artisan migrate` / `db:wipe` breaks for local parity runs
#   mbstring     -> boot fails: "Please provide a valid cache path" is a different
#                   error; the real one is Composer platform req + Str helpers and
#                   Inertia's JSON handling on UTF-8 Indonesian text
#   openssl      -> app key / cookie encryption needs it
#   tokenizer    -> Blade compilation fails
#   xml, ctype   -> PHPUnit and DOM-dependent packages
#   json         -> always compiled in on PHP 8, listed for explicitness
#   bcmath       -> not used by the EWS maths (plain int sums) but a common
#                   transitive need for future modules
#   fileinfo     -> uploaded file mime detection; keep it for parity with local
#   curl         -> HTTP client for future integrations and for health tooling
#   zip          -> Composer itself prefers dist zips over git clones
RUN docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pdo_sqlite \
        mbstring \
        bcmath \
        opcache \
        zip

# intl and pcntl are PECL (not bundled) extensions.
#
#   intl  -> locale-aware Indonesian date/number formatting
#   pcntl -> process control: lets a queue worker be stopped/restarted cleanly
#            instead of being SIGKILLed, and gives artisan proper signal handling
RUN pecl install intl-1.1.0 \
    && docker-php-ext-enable intl \
    && pecl install pcntl-1.1.1 \
    && docker-php-ext-enable pcntl

# Extensions that are ALREADY present in the official image, but whose
# shared-vs-static status differs between the Debian and Alpine variants and
# between upstream releases. Enabling one that is already static is harmless,
# but enabling one that is BUILT INTO THE CORE (json) is not: it writes
# `extension=json.so` into a conf.d file and every PHP process then logs
# "Failed loading json.so". So probe for the .so before enabling it.
#
# NEVER put `json` in this list. Since PHP 8, json is always compiled in and
# cannot be disabled (PHP 7.0 removed --disable-json).
RUN set -eu; \
    ext_dir="$(php -r 'echo ini_get("extension_dir");')"; \
    for ext in ctype fileinfo tokenizer xml openssl; do \
        if [ -f "${ext_dir}/${ext}.so" ]; then \
            docker-php-ext-enable "${ext}"; \
        else \
            echo "info: ${ext} sudah compiled-in, tidak perlu di-enable"; \
        fi; \
    done



# Runtime configuration.
COPY docker/php.ini           /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm.d/*.conf  /usr/local/etc/php-fpm.d/zz-app.conf
# nginx.conf lands at /etc/nginx/nginx.conf - the MAIN nginx config, NOT a
# conf.d/default.conf server fragment. It carries its own http {} block and
# includes nothing from conf.d or http.d, so no distro default config is layered
# on top of it.
#
# The file stays root:root 0644, i.e. READ-ONLY for the www-data user. The
# entrypoint never edits it in place (that would need write access to the file
# AND to the /etc/nginx directory, which a non-root user cannot have). Instead it
# copies the file to /tmp, rewrites the listen port there, and starts nginx
# with -c /tmp/nginx-runtime.conf.
COPY docker/nginx.conf        /etc/nginx/nginx.conf
COPY docker/entrypoint.sh     /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

# App code and static skeleton. `public/build` is deliberately NOT copied from
# the build context (.dockerignore excludes it) -- it arrives from the assets
# stage in the very next line, so the hashed filenames always match the source
# in THIS image.
COPY --chown=www-data:www-data . .
COPY --from=assets --chown=www-data:www-data /build/public/build ./public/build

# vendor/ is overwritten with the optimised copy from the vendor stage. Using
# COPY --from (not a bind volume) keeps vendor/ inside the image, so the running
# container never needs the repository.
COPY --from=vendor --chown=www-data:www-data /build/vendor ./vendor
# composer.json/lock come from the vendor stage too, because
# `composer dump-autoload --classmap-authoritative` bakes the resolved package
# versions into the autoloader; the manifest must describe the SAME tree.
COPY --from=vendor --chown=www-data:www-data /build/composer.json ./composer.json
COPY --from=vendor --chown=www-data:www-data /build/composer.lock ./composer.lock

# These two must exist and be writable BEFORE the entrypoint runs artisan, which
# writes config.php, routes-v*.php, and compiled Blade views into them.
#
# --chown=www-data on COPY already set ownership for the context files. The
# explicit chown + chmod below is what makes it survive for the files created
# at RUNTIME (config cache, session files, compiled views) -- nothing else in
# the build ever touches them again.
RUN mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chmod -R g+s storage bootstrap/cache

# Sanity gate: fail the BUILD, not production, if a required extension or a
# required directory is missing. This is the difference between a failed
# Railway build and a failed Railway deploy.
RUN set -eu; \
    php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);' \
        || { echo "FATAL: pdo_pgsql tidak termuat" >&2; exit 1; }; \
    php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' \
        || { echo "FATAL: pdo_sqlite tidak termuat" >&2; exit 1; }; \
    php -r 'exit(extension_loaded("mbstring") ? 0 : 1);' \
        || { echo "FATAL: mbstring tidak termuat" >&2; exit 1; }; \
    php -r 'exit(extension_loaded("zip") ? 0 : 1);' \
        || { echo "FATAL: zip tidak termuat" >&2; exit 1; }; \
    test -f public/index.php || { echo "FATAL: public/index.php hilang" >&2; exit 1; }; \
    test -f public/build/manifest.json || { echo "FATAL: public/build/manifest.json hilang" >&2; exit 1; }; \
    test -f vendor/autoload.php || { echo "FATAL: vendor/autoload.php hilang" >&2; exit 1; }; \
    test -w storage || { echo "FATAL: storage/ tidak writable oleh www-data" >&2; exit 1; }; \
    test -w bootstrap/cache || { echo "FATAL: bootstrap/cache/ tidak writable oleh www-data" >&2; exit 1; }; \
    echo "build gates passed"

# nginx is told to listen on 8080 in nginx.conf so that a NON-ROOT process may
# bind it: ports below 1024 need root.
#
# 8080 is NOT the port Render uses. Render injects a RANDOM high port as $PORT
# and routes public traffic to whatever is bound there, so a hardcoded 8080
# would boot a perfectly healthy container that Render can never reach. Every
# container start, docker/entrypoint.sh rewrites the single listen line to
# ${PORT:-8080} BEFORE nginx is launched, which keeps 8080 as the working
# default for local docker compose where nothing injects PORT.
#
# EXPOSE is documentation only: no orchestrator derives the published port from
# it. It is kept at 8080 to document the pre-substitution value.
#
# The user MUST be non-root. A container that runs as root has already lost the
# main containment benefit of containers: a Laravel RCE bug would own the
# container instead of only reaching an unprivileged user.
EXPOSE 8080

USER www-data

# Railway (railway.json healthcheckPath) dan Render (render.yaml healthCheckPath)
# sama-sama mem-probe path ini. Path-nya didaftarkan di bootstrap/app.php
# (`health: '/up'`), tidak butuh autentikasi, dan TIDAK menyentuh database,
# jadi PostgreSQL yang sedang down tidak membuat healthcheck berkedip.
#
# --connect-timeout/--max-time sengaja dibuat ketat: worker PHP yang macet tidak
# boleh bisa membuat container duduk di status "unhealthy" selama ber menit.
# --fail mencegah respons non-2xx dari curl dianggap sukses.
#
# PORT DIAMBIL DARI LINGKUNGAN, BUKAN 8080 YANG DITULIS MATI.
# Render menyuntikkan $PORT berisi ANGKA ACAK lalu mengarahkan trafik publik ke
# angka itu, dan docker/entrypoint.sh menuliskan angka itu ke direktif `listen`
# sebelum nginx dijalankan. Jadi angka yang benar-benar listen di dalam container
# ini baru diketahui saat RUNTIME -- persis seperti catatan pada EXPOSE di atas.
# Kalau 8080 di-hardcode di sini, container yang sebenarnya SEHAT akan dilaporkan
# `unhealthy`, lalu Render akan me-restart-nya berulang kali.
#
# Bentuk CMD di bawah adalah SHELL FORM, jadi Docker menjalankannya sebagai
# `/bin/sh -c "<string>"`. Ekspansi ${PORT:-8080} terjadi di shell pada saat
# healthcheck dijalankan, BUKAN saat image dibangun. Tanda kutip dipakai
# supaya hasil substitusinya menjadi satu argumen utuh milik curl, bukan
# dipecah oleh word splitting. Default 8080 tetap ditulis supaya `docker run`
# tanpa $PORT (dan `docker compose` lokal) tetap memakai nginx default.
HEALTHCHECK --interval=30s --timeout=5s --start-period=45s --retries=3 \
    CMD curl --fail --silent --show-error --connect-timeout 3 --max-time 4 \
        "http://127.0.0.1:${PORT:-8080}/up" || exit 1

# Non-secret defaults. Anything secret (APP_KEY, DB password) is NEVER baked
# here: a value baked into an ENV line is readable by anyone who can pull the
# image, and would also be frozen at build time so it could not be rotated.
# All of these are overridable at runtime by Railway's Variables, which is how
# a value in the dashboard actually takes effect.
#
# `docker run` will NOT read .env.production automatically -- pass
# `--env-file .env.production` explicitly.
ENV APP_NAME="SIMRS RSP Rotinsulu" \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    DB_CONNECTION=pgsql \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=sync \
    BROADCAST_CONNECTION=log \
    FILESYSTEM_DISK=local \
    APP_LOCALE=id \
    APP_FALLBACK_LOCALE=id \
    APP_FAKER_LOCALE=en_US \
    BCRYPT_ROUNDS=12 \
    CACHE_CONFIG=true \
    CACHE_ROUTES=true \
    CACHE_VIEWS=true \
    RUN_MIGRATIONS=false \
    SEED_DEMO_DATA=false \
    DB_WAIT_SECONDS=60 \
    DB_WAIT_INTERVAL=2

# No web server on PATH is PID 1, so PID 1 becomes the entrypoint script. It
# re-execs nginx into the foreground, which is what receives SIGTERM.
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]