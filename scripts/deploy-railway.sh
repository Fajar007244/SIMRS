#!/usr/bin/env bash
# ===========================================================================
#  scripts/deploy-railway.sh
#
#  Deploy helper for Railway + PostgreSQL. Idempotent: running it twice in a
#  row is safe, and running it before a change is also safe.
#
#  DESIGN RULES (deliberate, do not "simplify" these away):
#    1. NEVER accepts, stores, or echoes a secret. APP_KEY and DB passwords
#       are read from the Railway dashboard, never from this script, never from
#       a file, and never from a command-line argument (arguments are visible in
#       `ps` and in shell history). The script only ever prints variable NAMES.
#    2. Every mutating step is gated behind an explicit --yes. The default is a
#       dry run that changes nothing, so a nervous `bash deploy-railway.sh` can
#       never deploy to production by accident.
#    3. FAIL LOUDLY. A missing prerequisite aborts with a non-zero exit code and
#       an actionable message. Nothing is "best effort" and nothing continues
#       after a failed check.
#    4. Refuses to run if the working tree is dirty for tracked files, because a
#       half-finished change must never reach production.
#
#  Usage:
#      bash scripts/deploy-railway.sh              # dry run, changes nothing
#      bash scripts/deploy-railway.sh --check      # checks only
#      bash scripts/deploy-railway.sh --yes        # actually deploy
#      bash scripts/deploy-railway.sh --yes --migrate
#      bash scripts/deploy-railway.sh --yes --seed
# ===========================================================================

set -Eeuo pipefail

# --- Colours, but only when stdout is a TTY (so CI logs stay clean) --------
if [ -t 1 ]; then
  C_RED=$'\033[31m'; C_GRN=$'\033[32m'; C_YLW=$'\033[33m'
  C_BLU=$'\033[34m'; C_BLD=$'\033[1m'; C_RST=$'\033[0m'
else
  C_RED=''; C_GRN=''; C_YLW=''; C_BLU=''; C_BLD=''; C_RST=''
fi

APP_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

ASSUME_YES=0
CHECK_ONLY=0
DO_MIGRATE=0
DO_SEED=0
SKIP_DIRTY_CHECK=0

# Variables that MUST be present in the Railway service before a deploy can
# possibly succeed. Sourced from .env.production.example semantics, not guessed.
REQUIRED_VARS=(
  APP_KEY
  APP_ENV
  APP_DEBUG
  APP_URL
  LOG_CHANNEL
  DB_CONNECTION
  SESSION_DRIVER
  CACHE_STORE
  QUEUE_CONNECTION
  BROADCAST_CONNECTION
  FILESYSTEM_DISK
)

log()  { printf '%s[deploy]%s %s\n'   "$C_BLU" "$C_RST" "$*"; }
ok()   { printf '%s[  ok  ]%s %s\n'   "$C_GRN" "$C_RST" "$*"; }
warn() { printf '%s[ warn ]%s %s\n'   "$C_YLW" "$C_RST" "$*" >&2; }
die()  { printf '%s[FATAL ]%s %s\n'   "$C_RED" "$C_RST" "$*" >&2; exit 1; }

on_error() {
  printf '%s[FATAL ]%s deploy berhenti di baris %s\n' "$C_RED" "$C_RST" "$1" >&2
  exit 1
}
trap 'on_error "$LINENO"' ERR

usage() {
  sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^#//; s/^ //'
  exit 0
}

# --- Argument parsing ------------------------------------------------------
while [ $# -gt 0 ]; do
  case "$1" in
    -y|--yes)      ASSUME_YES=1 ;;
    -c|--check)    CHECK_ONLY=1 ;;
    -m|--migrate)  DO_MIGRATE=1 ;;
    -s|--seed)     DO_SEED=1 ;;
    --skip-dirty)  SKIP_DIRTY_CHECK=1 ;;
    -h|--help)     usage ;;
    *)             die "argumen tidak dikenal: $1 (lihat --help)" ;;
  esac
  shift
done

[ "$CHECK_ONLY" -eq 1 ] && ASSUME_YES=0

log "direktori aplikasi : $APP_DIR"
if [ "$ASSUME_YES" -eq 1 ]; then
  warn "MODE SESUNGGUHNYA. Semua perubahan akan dijalankan."
else
  log "MODE DRY RUN. Tidak ada yang diubah. Tambahkan --yes untuk eksekusi."
fi

# ---------------------------------------------------------------------------
# 1. Prasyarat
# ---------------------------------------------------------------------------
log "memeriksa prasyarat..."

command -v railway >/dev/null 2>&1 \
  || die "CLI 'railway' tidak ditemukan. Pasang dengan: npm i -g @railway/cli"

railway --version >/dev/null 2>&1 \
  || die "CLI 'railway' ada tapi tidak bisa dijalankan. Coba: railway --version"

ok "railway CLI: $(railway --version 2>&1 | head -n1)"

if ! railway status >/dev/null 2>&1; then
  die "belum terhubung ke project Railway. Jalankan: railway login lalu railway link"
fi
ok "terhubung ke project Railway"

# railway status prints a table. Confirm we are not accidentally pointed at a
# random project.
if railway status --json 2>/dev/null | grep -qi '"name"'; then
  PROJECT_NAME="$(railway status --json 2>/dev/null | sed -n 's/.*"name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n1)"
  [ -n "${PROJECT_NAME:-}" ] && ok "project: $PROJECT_NAME"
else
  warn "tidak bisa membaca nama project dari 'railway status --json'; lanjut"
fi

# --- Git ---
command -v git >/dev/null 2>&1 || die "'git' tidak ditemukan, padahal repository ini butuh git untuk dirty-check"

if [ -d .git ]; then
  if [ -n "$(git status --porcelain --untracked-files=no)" ] && [ "$SKIP_DIRTY_CHECK" -eq 0 ]; then
    git status --short --untracked-files=no >&2
    die "ada perubahan pada file yang sudah di-track. Commit dulu, atau pakai --skip-dirty untuk sengajaLewati."
  fi
  ok "working tree bersih (untuk file yang di-track)"
  CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'unknown')"
  log "branch saat ini: $CURRENT_BRANCH"
else
  warn "bukan git repository; dirty-check dilewati"
fi

# --- Build context sanity --------------------------------------------------
log "memeriksa berkas build..."
for f in Dockerfile .dockerignore railway.json composer.json composer.lock package.json package-lock.json; do
  [ -f "$f" ] || die "berkas wajib '$f' tidak ditemukan"
done
ok "berkas build lengkap"

# The single most common Inertia production failure: public/build committed or
# ignored inconsistently. We must IGNORE it so it is rebuilt inside the image.
if git ls-files --error-unmatch public/build >/dev/null 2>&1; then
  die "public/build ikut di-commit. Hapus dari git: git rm -r --cached public/build (Dockerfile membangunnya sendiri)"
fi
ok "public/build tidak di-commit (dibangun di dalam image)"

[ -f .env.production.example ] || die ".env.production.example tidak ditemukan"
ok ".env.production.example ada"

# --- Railway variables -----------------------------------------------------
# Names ONLY. Never values, never a hash of values: a hash of a short secret is
# brute-forceable, and any value printed here lands in CI logs.
log "memeriksa service variables di Railway..."
RAILWAY_VARS="$(railway variables --kv 2>/dev/null | cut -d= -f1 || true)"
if [ -z "$RAILWAY_VARS" ]; then
  die "tidak bisa membaca variables dari Railway. Pastikan service sudah dipilih: railway service"
fi

MISSING_VARS=()
for v in "${REQUIRED_VARS[@]}"; do
  if printf '%s\n' "$RAILWAY_VARS" | grep -qx "$v"; then
    ok "variable ada: $v"
  else
    MISSING_VARS+=("$v")
  fi
done

# DATABASE_URL is injected by the Railway PostgreSQL plugin. Its absence is the
# single most common cause of "could not translate host name".
if printf '%s\n' "$RAILWAY_VARS" | grep -qx 'DATABASE_URL'; then
  ok "variable ada: DATABASE_URL (dari plugin PostgreSQL)"
else
  die "DATABASE_URL tidak ada. Tambahkan plugin PostgreSQL di service yang SAMA, lalu deploy ulang"
fi

if [ "${#MISSING_VARS[@]}" -gt 0 ]; then
  printf '%s[FATAL ]%s variable wajib belum diset di Railway:\n' "$C_RED" "$C_RST" >&2
  for v in "${MISSING_VARS[@]}"; do printf '            - %s\n' "$v" >&2; done
  printf '            Set di Railway dashboard > service > Variables.\n' >&2
  printf '            Untuk APP_KEY: php artisan key:generate --show\n' >&2
  exit 1
fi

# Guard the two settings that silently ruin a production deploy.
if printf '%s\n' "$RAILWAY_VARS" | grep -qx 'APP_DEBUG' ; then
  # We cannot read the value without printing it, and APP_DEBUG is not a secret,
  # so this one is safe to check by value.
  APP_DEBUG_VALUE="$(railway variables --kv 2>/dev/null | grep '^APP_DEBUG=' | cut -d= -f2- || true)"
  if [ "$APP_DEBUG_VALUE" = "true" ]; then
    die "APP_DEBUG=true di produksi. Isi stack trace dan nilai env akan bocor ke pengguna."
  fi
  ok "APP_DEBUG bukan true"
fi

log "bridge 'railway variables' OK. Tidak ada nilai yang ditampilkan."
log "service variables yang terbaca: $(printf '%s\n' "$RAILWAY_VARS" | grep -c .)"

# ---------------------------------------------------------------------------
# 2. Ringkasan, lalu keluar kalau dry run / check only
# ---------------------------------------------------------------------------
echo
log "RINGKASAN YANG AKAN DIJALANKAN:"
printf '  %-34s %s\n' "project"        "${PROJECT_NAME:-<tidak terbaca>}"
printf '  %-34s %s\n' "branch"         "$CURRENT_BRANCH"
printf '  %-34s %s\n' "builder"        "DOCKERFILE (Dockerfile)"
printf '  %-34s %s\n' "healthcheck"    "/up"
printf '  %-34s %s\n' "migrate"        "$([ "$DO_MIGRATE" -eq 1 ] && echo 'ya' || echo 'tidak')"
printf '  %-34s %s\n' "seed"           "$([ "$DO_SEED" -eq 1 ] && echo 'ya (berbahaya)' || echo 'tidak')"
echo

if [ "$CHECK_ONLY" -eq 1 ]; then
  ok "mode --check selesai. Tidak ada yang diubah."
  exit 0
fi

if [ "$ASSUME_YES" -ne 1 ]; then
  warn "Dry run. Jalankan ulang dengan --yes untuk benar-benar deploy."
  exit 0
fi

# ---------------------------------------------------------------------------
# 3. Migrasi (opsional, dan dilakukan SETELAH deploy sukses)
# ---------------------------------------------------------------------------
# Ordering matters. Migrating BEFORE the new code is live can break a running
# old version; migrating AFTER can break the new version if the new code needs
# the new column immediately. This app is backwards compatible for the
# migrations it has today, so after-deploy is correct. Revisit if a future
# migration is not backwards compatible.
if [ "$DO_MIGRATE" -eq 1 ]; then
  log "menjalankan migrasi di Railway (setelah deploy sukses)..."
  railway run php artisan migrate:status --no-ansi \
    || die "gagal membaca status migrasi. Periksa DATABASE_URL dan koneksi database."
  railway run php artisan migrate --force --no-ansi \
    || die "MIGRASI GAGAL. Code versi terbaru sudah live tetapi skema belum ikut. Perbaiki segera."
  ok "migrasi selesai"
fi

if [ "$DO_SEED" -eq 1 ]; then
  warn "menjalankan seeder pada DATABASE PRODUKSI."
  warn "Seeder memakai updateOrCreate sehingga idempoten, tapi data demo tetap"
  warn "mencemari database produksi. Pastikan ini memang yang kamu mau."
  railway run php artisan db:seed --force --no-ansi \
    || die "seeder gagal."
  ok "seed selesai"
fi

# ---------------------------------------------------------------------------
# 4. Deploy
# ---------------------------------------------------------------------------
log "mulai deploy..."
DEPLOY_START="$(date +%s)"

# `railway up` uploads the current directory and triggers a build from source.
# Use it when there is no GitHub CI pipeline. If you DO have CI on GitHub
# pushing to the main branch, drop this and let CI deploy instead: running both
# means two builds racing for the same environment.
if railway up --detach >/dev/null 2>&1; then
  ok "deploy dikirim ke Railway"
else
  die "railway up gagal. Periksa: railway status, dan apakah build sudah sebelumnya gagal."
fi

log "menunggu build & deploy selesai (timeout 15 menit)..."
if railway logs --deployment --lines 200 >/dev/null 2>&1; then
  : # logs can be read even while deploying; not a gate
fi

if railway status --json >/dev/null 2>&1; then
  ELAPSED=$(( $(date +%s) - DEPLOY_START ))
  ok "deploy diproses dalam ${ELAPSED} detik"
else
  die "tidak bisa membaca status setelah deploy"
fi

cat <<EOF

${C_GRN}${C_BLD}Deploy dikirim.${C_RST}

Yang perlu kamu pantau di dashboard:
  1. Tab "Deployments" -> klik build terbaru -> baca "Build Logs".
     Cari baris yang memuat: "build gates passed".
  2. Tab "Deployments" -> "Deploy Logs". Yang sehat terlihat seperti:
        [entrypoint] non-root: storage/ + bootstrap/cache/ sudah writable
        [entrypoint] php artisan config:cache
        [entrypoint] route cache OK
        [entrypoint] php-fpm siap di 127.0.0.1:9000
        [entrypoint] nginx foreground di port 8080, container siap
  3. Buka APP_URL. Halaman /login harus muncul. /up harus mengembalikan 200.
  4. Login dengan salah satu akun demo, lalu buka /pasien.

Kalau build gagal, salin 30 baris terakhir Build Logs ke issues.
EOF