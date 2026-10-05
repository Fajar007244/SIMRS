# ===========================================================================
#  Procfile  -  process declarations for PaaS platforms (Railway, Heroku, etc.)
#
#  Only `web` is declared. That is a deliberate statement, not an oversight:
#  this application has NO queue jobs, NO scheduled tasks, and NO websocket or
#  realtime broadcasting, so there is nothing else to run. Adding a `worker:`
#  line for a queue that nothing dispatches to would only waste RAM.
#
#  ---------------------------------------------------------------------------
#  WHICH PATH DOES THIS FILE ACTUALLY DRIVE?
#  ---------------------------------------------------------------------------
#  railway.json sets build.builder = "DOCKERFILE", which means Railway uses the
#  Dockerfile and IGNORES this Procfile and nixpacks.toml entirely. The
#  Dockerfile's ENTRYPOINT is what actually starts the web process.
#
#  This Procfile matters when:
#    * you delete railway.json (or switch build.builder to "NIXPACKS"), or
#    * you deploy the same repo to Heroku / a Heroku-compatible PaaS.
#
#  See docs/RAILWAY.md section "Docker (disarankan) vs Nixpacks".
#
#  NOTE ABOUT router.php
#  The `web` line passes a router script to the PHP built-in server. That file
#  is GENERATED AT START TIME by the first command in [phases.start] of
#  nixpacks.toml, because the built-in server needs a front-controller fallback
#  (it answers `/` and 404s for every other path) and committing a second
#  router file would be a second source of truth to keep in sync with
#  docker/nginx.conf. If you deploy to a Heroku-compatible PaaS WITHOUT
#  nixpacks.toml, you must create an equivalent router yourself, or switch the
#  web process to nginx + php-fpm.
# ===========================================================================

release: php artisan migrate --force --no-interaction
web: PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:${PORT:-8080} -t public router.php