#!/bin/sh
# ---------------------------------------------------------------------------
# Container entrypoint. Runs on EVERY container start/restart, never at image
# build time.
#
# Everything environment-dependent happens HERE, never baked into the image:
#   * port nginx          -> Render injects a RANDOM $PORT and routes public
#     traffic there, so the listen directive must be rewritten at start
#   * wait for PostgreSQL  -> Render starts the app container and the database
#     in parallel; the database needs a few seconds to accept connections
#   * migrate (opt-in)     -> gated by RUN_MIGRATIONS, guarded against
#     concurrent boots with an atomic lock
#   * config/route/view    -> cached at RUNTIME, because config:cache embeds
#     env() values and baking it at build time would freeze the BUILD machine's
#     env (no DB, APP_DEBUG=true, no APP_KEY) into a production image
#   * fix ownership        -> storage/ and bootstrap/cache/ must be writable by
#     the non-root user
#
# Finally: php-fpm in the BACKGROUND, nginx in the FOREGROUND. The foreground
# process is the one that receives SIGTERM from the orchestrator, so it is the
# one that must be PID 1. If nginx were backgrounded, SIGTERM would land on a
# shell that cannot forward it and every deploy would burn the full grace
# period before the container is SIGKILLed.
#
# POSIX sh on purpose: Alpine ships ash (and BusyBox sed), not bash.
# ---------------------------------------------------------------------------

set -eu

APP_DIR="${APP_DIR:-/var/www/html}"
RUN_USER="${RUN_USER:-www-data}"
RUN_GROUP="${RUN_GROUP:-www-data}"

# Port tempat nginx WAJIB listen.
#   Di Render: di-inject platform sebagai angka acak, lalu traffic publik
#              diarahkan ke angka itu. JANGAN di-hardcode.
#   Lokal (`docker compose`): tidak ada yang meng-inject PORT, jadi default 8080
#              dipakai dan perilakunya sama seperti sebelum ada perubahan ini.
PORT="${PORT:-8080}"

# Konfigurasi nginx BAWAAN hasil COPY di image build (root:root, 0644).
NGINX_CONF="${NGINX_CONF:-/etc/nginx/nginx.conf}"
# Salinan runtime yang boleh ditulis user non-root (lihat configure_nginx_port).
NGINX_RUNTIME_CONF="${NGINX_RUNTIME_CONF:-/tmp/nginx-runtime.conf}"

# Batas tolerable: platform biasanya SIGTERM lalu SIGKILL setelah grace period.
DB_WAIT_SECONDS="${DB_WAIT_SECONDS:-60}"
DB_WAIT_INTERVAL="${DB_WAIT_INTERVAL:-2}"

# Cache saat boot. Set false HANYA untuk debug: config:cache menyembunyikan nilai
# env, jadi salah ketik nama variable tidak terlihat sampai cache di-clear.
CACHE_CONFIG="${CACHE_CONFIG:-true}"
CACHE_ROUTES="${CACHE_ROUTES:-true}"
CACHE_VIEWS="${CACHE_VIEWS:-true}"

# `php artisan migrate --force` saat boot.
#   false -> aman; migrasi dijalankan manual lewat `render ssh` / `render jobs`
#   true  -> DATABASE_URL wajib ada. Wajib set true kalau service ini satu-satunya
#            cara menjalankan migrasi.
RUN_MIGRATIONS="${RUN_MIGRATIONS:-false}"

# Seed data demo. HATI-HATI: seeder klinis tidak idempoten total; men-seed di
# produksi yang sudah berisi data nyata berisiko menduplikasi baris.
SEED_DEMO_DATA="${SEED_DEMO_DATA:-false}"

log() { printf '[entrypoint] %s\n' "$*"; }
die() { printf '[entrypoint] FATAL: %s\n' "$*" >&2; exit 1; }

cd "$APP_DIR"

# ---------------------------------------------------------------------------
# 0. Guard APP_KEY. Tanpa ini, EncryptionServiceProvider melempar
#    MissingAppKeyException pada SETIAP request -> 500 di seluruh aplikasi,
#    dan karena APP_DEBUG=false pesan aslinya tidak pernah terlihat. Dicek di
#    sini supaya log deploy menyebut penyebabnya dengan kata yang jelas.
# ---------------------------------------------------------------------------
if [ -z "${APP_KEY:-}" ]; then
    die "APP_KEY kosong. Set di Render > service > Environment > Environment Variables, atau buat dengan: php artisan key:generate --show"
fi

# ---------------------------------------------------------------------------
# 1. Samakan port nginx dengan $PORT milik platform.  HARUS SEBELUM nginx start.
#
#    Kenapa `sed -i` in-place di /etc/nginx/nginx.conf TIDAK bisa dipakai:
#      * `sed -i` menulis file SEMENTARA di direktori yang SAMA dengan file
#        input, lalu me-rename di atasnya. Jadi yang harus writable bukan hanya
#        file-nya, tapi juga DIRECTORY /etc/nginx. Container berjalan sebagai
#        www-data (non-root) dan /etc/nginx milik root -> tidak bisa.
#      * Melonggarkan izin /etc/nginx (mis. chown root:www-data + chmod 0775)
#        AKAN membuat www-data bisa menimpa konfigurasi nginx secara permanen.
#        Itu hak yang tidak perlu diberikan ke user web.
#
#    Solusi yang dipakai: salin ke /tmp (world-writable), sed di sana, lalu
#    jalankan nginx dengan `-c <path salinan>`. Konsekuensinya:
#      * tidak butuh chmod/chown apa pun di dalam image, dan tidak perlu
#        menebak uid/gid www-data di base image,
#      * file asli di /etc/nginx tetap pristine, jadi tiap boot selalu mulai
#        dari nilai default yang sama (idempoten, tidak ada state "sudah
#        disubstitusi" dari boot sebelumnya),
#      * semua path di nginx.conf absolut, jadi memindahkan file konfigurasi
#        tidak mengubah apa pun yang di-resolve nginx.
#
#    Pola sed sengaja memakai BRE POSIX \( \) dan \1, yang diimplementasikan
#    baik oleh BusyBox sed (Alpine) maupun GNU sed. Polanya mengikat pada
#    "listen" + spasi + angka 8080 yang ada di baked-in config.
#
#    Kegagalan TIDAK boleh diam: setiap langkah punya `die`, dan hasilnya
#    diverifikasi ulang dengan grep sebelum nginx dijalankan. Jadi kalau port
#    tidak benar-benar berubah, container berhenti dengan pesan jelas - bukan
#    diam-diam tetap listen di 8080 sementara Render menunggu port acak.
# ---------------------------------------------------------------------------
configure_nginx_port() {
    # 1a. PORT harus angka dan masuk rentang port. Render selalu mengirim
    #     angka; nilai lain berarti ada yang salah konfigurasi lebih dalam dan
    #     lebih baik gagal sekarang daripada nginx menolak start.
    case "$PORT" in
        ''|*[!0-9]*)
            die "PORT=\"$PORT\" bukan angka. Platform harus menyuntikkan PORT numerik. Cek environment variable service."
            ;;
    esac
    if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
        die "PORT=$PORT di luar rentang yang valid (1-65535)."
    fi

    # 1b. File baked-in harus ada. Kalau hilang, image-nya rusak.
    [ -f "$NGINX_CONF" ] || die "konfigurasi nginx tidak ditemukan di $NGINX_CONF. Cek baris COPY docker/nginx.conf di Dockerfile."

    # 1c. Salin ke path writable. `cp` preserving mode; chmod supaya pasti
    #     terbaca nginx dan bisa ditulis user non-root.
    cp "$NGINX_CONF" "$NGINX_RUNTIME_CONF" \
        || die "gagal menyalin $NGINX_CONF ke $NGINX_RUNTIME_CONF (cek /tmp writable)."
    chmod 0644 "$NGINX_RUNTIME_CONF" 2>/dev/null || true

    # 1d. Substitusi port. Pertahankan baris default_server dan sisa baris.
    sed -i "s/^\( *listen *\)8080/\1${PORT}/" "$NGINX_RUNTIME_CONF" \
        || die "sed gagal menulis $NGINX_RUNTIME_CONF. Container tidak akan start dengan port yang salah."

    # 1e. Verifikasi: kalau direktif listen tidak berubah ke $PORT,
    #     JANGAN lanjut: nginx akan listen di tempat yang salah.
    grep -Eq "^[[:space:]]*listen[[:space:]]+${PORT}([[:space:]]|;)" "$NGINX_RUNTIME_CONF" \
        || die "substitusi port gagal: $NGINX_RUNTIME_CONF tidak punya 'listen ${PORT}'. Periksa baris 'listen' di docker/nginx.conf (harus tetap berawalan 8080)."

    # 1f. Guard tambahan: hanya boleh ada SATU listen. Dua listener (mis. IPv6)
    #     berarti hanya satu yang berubah dan binds bisa bentrok.
    listen_count="$(grep -cE '^[[:space:]]*listen[[:space:]]' "$NGINX_RUNTIME_CONF" || true)"
    [ "$listen_count" -eq 1 ] \
        || die "ditemukan ${listen_count} direktif 'listen' di $NGINX_RUNTIME_CONF, harus tepat 1. Jalur ini hanya mengubah satu port."

    # 1g. Uji sintaks config SEBELUM nginx start, supaya error config tampil
    #     sebagai pesan bootstrap yang jelas, bukan sebagai 502 saat deploy.
    nginx -t -c "$NGINX_RUNTIME_CONF" >/dev/null 2>&1 \
        || die "nginx -t gagal untuk $NGINX_RUNTIME_CONF. Lihat detail dengan: nginx -t -c $NGINX_RUNTIME_CONF"

    log "nginx akan listen di port ${PORT} (dari ${NGINX_CONF}, disalin ke ${NGINX_RUNTIME_CONF})"
}

# ---------------------------------------------------------------------------
# 2. Kepemilikan direktori yang harus bisa ditulis runtime.
#
#    Build image sudah chown ke www-data, jadi di sini normalnya NO-OP. Yang
#    perlu di-chown ulang hanya volume eksternal (kalau suatu saat dipasang).
# ---------------------------------------------------------------------------
fix_ownership() {
    for dir in storage bootstrap/cache; do
        [ -d "$dir" ] || mkdir -p "$dir"
    done

    if [ "$(id -u)" = "0" ]; then
        chown -R "${RUN_USER}:${RUN_GROUP}" storage bootstrap/cache
        # Bit "2" (setgid) menjaga file baru tetap milik group yang sama, dan
        # group-writable (7) wajib karena Laravel menghapus config.php,
        # routes-*.php, events.php setiap kali cache di-clear.
        find storage bootstrap/cache -type d -exec chmod 2775 {} +
        find storage bootstrap/cache -type f -exec chmod 664 {} +
        log "ownership storage/ + bootstrap/cache/ -> ${RUN_USER}:${RUN_GROUP}"
    else
        # Sudah non-root: chown mustahil dan memang tidak boleh dicoba. Cukup
        # verifikasi writable, lalu GAGAL KERAS kalau tidak, supaya penyebabnya
        # muncul di log deploy dan bukan jadi 500 "permission denied" di request
        # pertama.
        for dir in storage bootstrap/cache; do
            [ -w "$dir" ] || die "non-root (uid $(id -u)) tapi $dir tidak writable. Cek Dockerfile: chown -R ${RUN_USER}:${RUN_GROUP} ${dir} di stage runtime."
        done
        log "non-root: storage/ + bootstrap/cache/ sudah writable, chown dilewati"
    fi
}

# ---------------------------------------------------------------------------
# 3. Tunggu PostgreSQL.
#
#    Platform menjalankan container app dan container Postgres secara paralel.
#    Postgres butuh beberapa detik, jadi retry loop WAJIB. Tanpa ini, migrate
#    dan request pertama gagal dengan "Connection refused" atau
#    "SQLSTATE[08006] could not translate host name".
#
#    Timeout 60 detik: Postgres normalnya siap dalam < 5 detik. 60 detik gagal
#    berarti konfigurasinya salah, jadi lebih baik gagal cepat dan terbaca.
# ---------------------------------------------------------------------------
wait_for_database() {
    if [ "${DB_CONNECTION:-pgsql}" = "sqlite" ]; then
        log "DB_CONNECTION=sqlite, lewati tunggu DB"
        return 0
    fi

    if [ -z "${DATABASE_URL:-}" ] && [ -z "${DB_HOST:-}" ]; then
        log "tidak ada DATABASE_URL / DB_HOST, lewati tunggu DB"
        return 0
    fi

    attempts=$(( DB_WAIT_SECONDS / DB_WAIT_INTERVAL ))
    [ "$attempts" -ge 1 ] || attempts=1

    i=1
    warned=0
    while [ "$i" -le "$attempts" ]; do
        # migrate:status adalah cek paling jujur: benar-benar membuka koneksi
        # TCP + melakukan autentikasi ke Postgres.
        if php artisan migrate:status --no-ansi >/dev/null 2>&1; then
            log "PostgreSQL siap pada percobaan ke-${i}"
            return 0
        fi
        [ "$warned" -eq 1 ] || { log "PostgreSQL belum siap, menunggu (maks ${DB_WAIT_SECONDS}s)..."; warned=1; }
        sleep "$DB_WAIT_INTERVAL"
        i=$(( i + 1 ))
    done

    return 1
}

# ---------------------------------------------------------------------------
# 4. Migrasi, diamankan terhadap boot bersamaan.
#
#    `mkdir` bersifat ATOMIK di filesystem POSIX: succeeds untuk satu proses
#    saja, proses lain gagal. Itulah lock yang benar, dan tidak butuh ekstensi
#    PHP tambahan. Timeout 8 detik: kalau instance lain sedang migrate, tunggu,
#    lalu lewati saja karena migrate instance itu juga akan menerapkan seluruh
#    migrasi yang tertinggal.
#
#    Catatan jujur: lock ini hanya berlaku DI DALAM satu container. Untuk dua
#    container berbeda, pengaman sebenarnya datang dari tabel `migrations`
#    PostgreSQL itu sendiri. Karena itu numInstances dikunci 1 (lihat
#    render.yaml).
# ---------------------------------------------------------------------------
acquire_migrate_lock() {
    lock_dir="/tmp/.laravel-migrate.lock"
    mkdir -p /tmp

    i=0
    while [ "$i" -lt 8 ]; do
        if mkdir -m 700 "$lock_dir" 2>/dev/null; then
            log "migrate lock diambil"
            return 0
        fi
        sleep 1
        i=$(( i + 1 ))
    done

    log "WARN: lock migrate tidak didapat dalam 8s, lanjut tanpa lock"
    return 1
}

run_migrations() {
    [ "${RUN_MIGRATIONS}" = "true" ] || return 0

    if [ -z "${DATABASE_URL:-}" ] && [ -z "${DB_HOST:-}" ]; then
        die "RUN_MIGRATIONS=true tapi tidak ada DATABASE_URL maupun DB_HOST"
    fi

    if acquire_migrate_lock; then
        log "menjalankan: php artisan migrate --force"
        php artisan migrate --force --no-ansi
        log "migrate SELESAI"
    else
        log "migrate dilewati, instance lain sedang menjalankannya"
    fi
}

seed_demo_data() {
    [ "${SEED_DEMO_DATA}" = "true" ] || return 0

    log "menjalankan: php artisan db:seed --force"
    php artisan db:seed --force --no-ansi
    log "seed SELESAI"
}

# ---------------------------------------------------------------------------
# 5. Cache konfigurasi / rute / view, DI RUNTIME.
#
#    Kenapa runtime, bukan build:
#      * `config:cache` MENYERTAKAN nilai env() ke dalam bootstrap/cache/config.php.
#        Kalau dibake saat build, image berisi APP_DEBUG=true, DB_HOST=127.0.0.1,
#        dan tanpa APP_KEY -> produksi benar-benar rusak tanpa error yang jujur.
#      * `route:cache` meng-serialize seluruh rute. Loader routes/pages.*.php di
#        web.php memakai glob() + require, jadi hasil cache adalah snapshot yang
#        BENAR selama image tidak berubah. Menambah rute = deploy image baru.
#
#    Kenapa `config:clear` DULUAN: cache dari deploy sebelumnya mungkin holding
#    APP_KEY/db lama. Kalau tidak dihapus, env baru tidak akan pernah terbaca.
# ---------------------------------------------------------------------------
cache_artifacts() {
    php artisan config:clear --no-ansi
    php artisan optimize:clear --no-ansi

    if [ "${CACHE_CONFIG}" = "true" ]; then
        log "php artisan config:cache"
        php artisan config:cache --no-ansi
    fi

    if [ "${CACHE_ROUTES}" = "true" ]; then
        log "php artisan route:cache"
        # Toleran: kalau ada rute Closure yang tidak bisa diserialisasi, app
        # TETAP jalan (router membaca ulang file rute tiap request). route:cache
        # yang gagal tidak boleh menjatuhkan seluruh container.
        if php artisan route:cache --no-ansi; then
            log "route cache OK"
        else
            log "WARN: route:cache gagal, lanjut tanpa route cache (app tetap jalan)"
        fi
    fi

    if [ "${CACHE_VIEWS}" = "true" ]; then
        log "php artisan view:cache"
        php artisan view:cache --no-ansi
    fi
}

# ---------------------------------------------------------------------------
# 6. Supervisor sederhana: php-fpm di belakang, nginx di depan.
# ---------------------------------------------------------------------------
start_fpm() {
    log "menjalankan php-fpm (background)"
    php-fpm --nodaemonize --fpm-config /usr/local/etc/php-fpm.conf &
    FPM_PID=$!

    # Tunggu php-fpm benar-benar siap menerima FastCGI, kalau tidak request
    # pertama bisa mendarat di "connect() failed (111)" lalu 502.
    i=0
    while [ "$i" -lt 30 ]; do
        if php -r '$f=@fsockopen("127.0.0.1", 9000, $e, $s, 1); if ($f) { fclose($f); exit(0); } exit(1);' 2>/dev/null; then
            log "php-fpm siap di 127.0.0.1:9000"
            return 0
        fi
        if ! kill -0 "${FPM_PID}" 2>/dev/null; then
            die "php-fpm mati saat start. Cek: php-fpm -t"
        fi
        sleep 1
        i=$(( i + 1 ))
    done

    log "WARN: php-fpm belum siap di 30s, tetap lanjut (nginx akan retry)"
    return 0
}

# Trap ini hanya berlaku selama fase setup. Setelah `exec nginx` di bawah,
# shell ini DIGANTI oleh nginx, jadi nginx sendiri yang jadi PID 1 dan yang
# menerima serta menangani SIGTERM (fast shutdown). Setelah nginx berhenti,
# container runtime mereap php-fpm.
trap 'log "sinyal diterima saat startup, keluar"; exit 0' TERM INT

# Port duluan: kalau salah, tidak ada gunanya menunggu database atau
# menjalankan migrasi lebih dulu - tidak akan ada yang bisa menghubungi
# service ini sama sekali.
configure_nginx_port

fix_ownership
cache_artifacts

if [ "${RUN_MIGRATIONS}" = "true" ]; then
    wait_for_database || die "PostgreSQL tidak siap dalam ${DB_WAIT_SECONDS}s. Cek DATABASE_URL dan status service PostgreSQL di Render."
    run_migrations
    seed_demo_data
fi

start_fpm

log "nginx foreground di port ${PORT}, container siap"
exec nginx -c "$NGINX_RUNTIME_CONF" -g 'daemon off;'