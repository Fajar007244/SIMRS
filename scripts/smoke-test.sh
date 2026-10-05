#!/usr/bin/env bash
# =============================================================================
# smoke-test.sh - uji end-to-end untuk SIMRS Observasi EWS (Laravel 12 +
# Inertia 2 + Vue 3). Padanan POSIX dari scripts/smoke-test.ps1.
#
# Menjalankan bagian yang sama:
#   1. reset database      : php artisan migrate:fresh --seed --force
#   2. boot check          : menyalakan server sendiri bila belum jalan
#   3. login               : perawat / bidan / dokter
#   4. halaman baca        : /pasien + 6 modul Inertia untuk 2 encounter
#   5. kasus negatif       : 404 encounter, 302 tamu, query string rusak
#   6. jalur tulis         : penunjang (lab/mikro/AGD/hapus/reset) dan
#                            observasi (simpan/upsert/tolak/hapus/obat/bundle)
#   7. kasus tulis negatif : _token basi (419), session logout (419 / 302)
#   8. audit EWS           : hitung ulang dari config/ews.php, tanpa memakai
#                            EwsScoringService, lalu bandingkan dengan nilai
#                            tersimpan dan dengan EwsScoringService::calculate()
#
# Aman dijalankan berulang kali: database selalu di-reset lebih dulu.
# Keluar dengan kode bukan nol bila ada satu saja check yang gagal.
#
# Contoh:
#   bash scripts/smoke-test.sh
#   BASE_URL=http://127.0.0.1:8123 bash scripts/smoke-test.sh
#   NO_RESET=1 bash scripts/smoke-test.sh
# =============================================================================

set -uo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8123}"
NO_RESET="${NO_RESET:-0}"
KEEP_SERVER="${KEEP_SERVER:-0}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

# PHP adalah executable Windows, jadi ia memerlukan path gaya Windows. Di Git Bash
# `pwd -W` give Dokumentasi path itu; di Linux/macOS `pwd` sudah cukup.
ROOT_WIN="$(cd "$ROOT" && pwd -W 2>/dev/null || pwd)"

# ------------------------------------------------------------------ alat

if command -v php >/dev/null 2>&1; then
    PHP="$(command -v php)"
else
    PHP=""
    for c in /c/xampp/php/php.exe /usr/local/bin/php /usr/bin/php; do
        [ -x "$c" ] && PHP="$c" && break
    done
fi
if [ -z "$PHP" ]; then
    echo "php tidak ditemukan. Setel PHP_BIN=/path/ke/php lalu jalankan ulang." >&2
    exit 2
fi
command -v curl >/dev/null 2>&1 || { echo "curl tidak ditemukan." >&2; exit 2; }

WORK="$(mktemp -d "${TMPDIR:-/tmp}/ewssmoke-XXXXXX")"
DBQ="$WORK/dbq.php"
EWSAUDIT="$WORK/ews_audit.php"
STARTED_PID=""

FAILURES=0
TOTAL=0
SECTION_NAME=""

# Helper PHP untuk membaca sqlite langsung; dibuat lalu dihapus oleh skrip ini.
cat >"$DBQ" <<'PHPEOF'
<?php
define('BASE', getenv('SMOKE_ROOT'));
require BASE . '/vendor/autoload.php';
$app = require BASE . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$sql = isset($argv[1]) ? $argv[1] : '';
if ($sql === '') { fwrite(STDERR, "no sql\n"); exit(2); }
try {
    echo json_encode(Illuminate\Support\Facades\DB::select($sql),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(3); }
PHPEOF
export SMOKE_ROOT="$ROOT_WIN"

dbq()   { SMOKE_ROOT="$ROOT_WIN" "$PHP" "$DBQ" "$1"; }
dbscalar() { dbq "$1" | "$PHP" -r '$j=trim(stream_get_contents(STDIN)); $r=$j===""?[]:json_decode($j,true); echo is_array($r)&&count($r)?reset($r[0]):0;'; }
dbcount() { dbscalar "$1"; }
section() {
    SECTION_NAME="$1"
    printf '\n%s\n' "================================================================================================"
    printf '  %s\n' "$1"
    printf '%s\n' "================================================================================================"
    printf '%-58s %-8s %s\n' "CHECK" "STATUS" "RESULT"
    printf '%s\n' "------------------------------------------------------------------------------------------------"
}

check() {
    TOTAL=$((TOTAL + 1))
    local name="$1" ok="$2" detail="${3:-}"
    if [ "$ok" = "1" ]; then
        printf '%-58s %-8s %s\n' "$(trunc "$name" 58)" "PASS" "$detail"
    else
        FAILURES=$((FAILURES + 1))
        printf '%-58s %-8s %s\n' "$(trunc "$name" 58)" "FAIL" "$detail"
    fi
}

trunc() {
    printf '%s' "$1" | tr '\n\r\t' '   ' | cut -c1-"${2:-58}"
}

# HTTP: echoes "<code>|<redirect_url>", badan disimpan ke $WORK/body.txt
http() {
    local method="$1" path="$2" jar="${3:-}" data="${4:-}" referer="${5:-}" follow="${6:-1}"
    local body="$WORK/body.txt"
    local args=(-s -o "$body" -w '%{http_code}|%{redirect_url}')
    # PENTING: jangan paksa -X saat mengikuti redirect. Kalau -X POST dipaksa pada
    # permintaan yang juga -L, curl mengirim POST lagi ke tujuan redirect (halaman baca)
    # dan jawabannya 405, bukan halaman yang memuat pesan error. Saat -L, curl sudah
    # otomatis memakai POST karena ada --data-urlencode.
    if [ "$follow" != "1" ] || [ "$method" = "GET" ] || [ "$method" = "HEAD" ]; then
        args+=(-X "$method")
    fi
    [ -n "$jar" ] && args+=(-b "$jar" -c "$jar")
    [ -n "$referer" ] && args+=(-e "$referer")
    if [ -n "$data" ]; then
        # data dipisah ';' menjadi pasangan k=v
        local IFS=';'
        for pair in $data; do
            [ -n "$pair" ] && args+=(--data-urlencode "$pair")
        done
    fi
    [ "$follow" = "1" ] && args+=(-L)
    args+=("$BASE_URL$path")
    curl "${args[@]}" 2>/dev/null
}

http_code()    { http "$@" | cut -d'|' -f1; }
http_redirect() { http "$@" | cut -d'|' -f2-; }

# Ambil body respons terakhir ke stdout.
http_body() { cat "$WORK/body.txt"; }

# Ambil _token. Setelah login, /login dialihkan sehingga form login tidak ada;
# untuk sesi yang sudah login, token diambil dari form logout di /pasien.
get_token() {
    local jar="$1" p body m
    for p in /login /pasien; do
        http GET "$p" "$jar" "" "" 1 >/dev/null
        body="$(http_body)"
        m="$(printf '%s' "$body" | grep -o 'name="_token"[[:space:]]*value="[^"]*"' | head -n1 | sed 's/.*value="//; s/"$//')"
        [ -n "$m" ] && { printf '%s' "$m"; return 0; }
    done
    return 1
}

login() {
    local role="$1" pass="$2" jar="$WORK/jar-$role.txt" tok
    rm -f "$jar"
    tok="$(get_token "$jar")" || return 1
    local r
    r="$(http POST /login "$jar" "_token=$tok;username=$role;password=$pass" "" 0)"
    case "$r" in
        302*) return 0 ;;
        *)    return 1 ;;
    esac
}

# Baca prop Inertia dari atribut data-page.
#
# Atributnya berbentuk data-page="{JSON ter-escape-HTML}". Karakter pertama
# setelah "data-page=" adalah kutip pembuka, jadi nilainya mulai di $i+11 dan
# panjangnya $j-$i-1, dengan $j = posisi kutip PENUTUP.
props() { "$PHP" -r '
    $h = file_get_contents("php://stdin");
    $i = strpos($h, "data-page=");
    if ($i === false) { exit(1); }
    $i += 10;
    $j = strpos($h, "\"", $i + 1);
    if ($j === false) { exit(1); }
    $decoded = json_decode(html_entity_decode(substr($h, $i + 1, $j - $i - 1), ENT_QUOTES, "UTF-8"), true);
    if ($decoded === null) { exit(1); }
    $p = $decoded["props"] ?? [];
    unset($p["errors"]);
    $tab = $p["activeTab"] ?? "-";
    $empty = 0; $cnt = 0;
    foreach ($p as $v) { $cnt++; if ($v === null || $v === "") { $empty++; } }
    echo $tab, "|", $cnt, "|", $empty, "|", ($p["activeGroup"] ?? "-");
    '; }

cleanup() {
    if [ -n "$STARTED_PID" ] && [ "$KEEP_SERVER" != "1" ]; then
        kill "$STARTED_PID" 2>/dev/null || true
        echo "Server yang dibuat skrip sudah dihentikan."
    fi
    rm -rf "$WORK" 2>/dev/null || true
}
trap cleanup EXIT
# ================================================================ 1. RESET DB

section "1. RESET DATABASE"

if [ "$NO_RESET" = "1" ]; then
    check "reset DB (migrate:fresh --seed)" 1 "dilewati (NO_RESET=1)"
else
    OUT="$("$PHP" artisan migrate:fresh --seed --force 2>&1)"; RC=$?
    if [ $RC -eq 0 ] && ! printf '%s' "$OUT" | grep -qE 'FAIL|Exception|RuntimeException'; then
        check "php artisan migrate:fresh --seed --force" 1 "exit 0"
    else
        check "php artisan migrate:fresh --seed --force" 0 "exit $RC"
        printf '%s\n' "$OUT" | tail -n 20
    fi
fi

for pair in users:3 patients:6 encounters:6 observations:60 \
            observation_medications:218 observation_bundle_answers:780 \
            invasive_devices:23 support_results:123 abg_results:15; do
    t="${pair%%:*}"; want="${pair##*:}"
    got="$(dbcount "select count(*) as c from $t")"
    [ "$got" = "$want" ] && check "row count $t" 1 "$got (expected $want)" \
                         || check "row count $t" 0 "$got (expected $want)"
done

ENC_LIST="$(dbq 'select encounter_id from encounters order by encounter_id' \
    | "$PHP" -r '$r=json_decode(trim(stream_get_contents(STDIN)),true); echo implode(",", array_column($r ?: [],"encounter_id"));')"
ENC_COUNT="$(printf '%s' "$ENC_LIST" | tr ',' '\n' | grep -c . )"
check "enam encounter tersedia" "$([ "$ENC_COUNT" = "6" ] && echo 1 || echo 0)" "$ENC_LIST"

ENC0="enc-159853-icu-20260906"
ENC0_ID="$(dbcount "select id from encounters where encounter_id = '$ENC0'")"
check "encounter uji ada (string -> FK integer)" "$([ "${ENC0_ID:-0}" -gt 0 ] && echo 1 || echo 0)" "$ENC0 = encounters.id $ENC0_ID"
ENC1="$(printf '%s' "$ENC_LIST" | cut -d, -f1)"

# ================================================================= 2. BOOT

section "2. BOOT CHECK"

PORT="${BASE_URL##*:}"
CODE="$(http_code GET /login "" "" "" 0)"
if [ "$CODE" = "200" ]; then
    check "HTTP server menyala di $BASE_URL" 1 "login page 200"
else
    echo "  [boot] server tidak aktif, menyalakan php artisan serve --port=$PORT ..."
    "$PHP" artisan serve --host=127.0.0.1 --port="$PORT" >"$WORK/serve.log" 2>&1 &
    STARTED_PID=$!
    OK=0
    for _ in $(seq 1 40); do
        sleep 0.5
        CODE="$(http_code GET /login "" "" "" 0)"
        [ "$CODE" = "200" ] && { OK=1; break; }
    done
    check "menyalakan server di $BASE_URL" "$OK" "pid $STARTED_PID"
    [ "$OK" = "1" ] || { echo "Server tidak bisa dinyalakan. Log: $WORK/serve.log" >&2; exit 2; }
fi

check "GET /login -> 200" "$([ "$(http_code GET /login "" "" "" 0)" = "200" ] && echo 1 || echo 0)" \
      "HTTP $(http_code GET /login "" "" "" 0)"

TOK="$(get_token "$WORK/probe.txt")"
check "halaman login punya _token" "$([ -n "$TOK" ] && echo 1 || echo 0)" "csrf ok"

# ================================================================= 3. LOGIN

section "3. LOGIN (3 ROLE)"

PRIMARY="$WORK/jar-perawat.txt"
for role in perawat bidan dokter; do
    if login "$role" "${role}123"; then
        check "login $role" 1 "HTTP 302"
    else
        check "login $role" 0 "gagal"
    fi
done
[ -f "$PRIMARY" ] || { echo "Login perawat gagal; test dihentikan." >&2; exit 2; }
# ====================================================== 4. HALAMAN READ-ONLY

section "4. HALAMAN READ-ONLY"

MODULES="profil cppt penunjang farmasi observasi bundles"

for role in perawat bidan dokter; do
    jar="$WORK/jar-$role.txt"
    C="$(http_code GET /pasien "$jar")"
    check "$role : GET /pasien" "$([ "$C" = "200" ] && echo 1 || echo 0)" "HTTP $C"

    http GET /pasien "$jar" "" "" 1 >/dev/null
    B="$(http_body)"
    H1=0; H2=0; H3=0
    printf '%s' "$B" | grep -q 'Rotinsulu'            && H1=1
    printf '%s' "$B" | grep -qE 'No\.?[[:space:]]*RM' && H2=1
    printf '%s' "$B" | grep -qE 'rel="next"|pagination' && H3=1
    [ "$H1$H2$H3" = "111" ] && check "$role : /pasien isi (nama RS, No. RM, paginasi)" 1 "RS=$H1 RM=$H2 page=$H3" \
                              || check "$role : /pasien isi (nama RS, No. RM, paginasi)" 0 "RS=$H1 RM=$H2 page=$H3"

    C="$(http_code GET "/encounters/$ENC1/cppt" "$jar")"
    check "$role : modul klinis cppt" "$([ "$C" = "200" ] && echo 1 || echo 0)" "HTTP $C"
done

for enc in "$ENC1" "$ENC0"; do
    for m in $MODULES; do
        C="$(http_code GET "/encounters/$enc/$m" "$PRIMARY")"
        http GET "/encounters/$enc/$m" "$PRIMARY" "" "" 1 >/dev/null
        RES="$(props < "$WORK/body.txt" 2>/dev/null)"
        TAB="${RES%%|*}"; REST="${RES#*|}"; CNT="${REST%%|*}"; REST2="${REST#*|}"; EMPTY="${REST2%%|*}"
        OKTAB=0; [ "$TAB" = "$m" ] && OKTAB=1
        OKP=0; [ "${CNT:-0}" -ge 3 ] && [ "${EMPTY:-1}" = "0" ] && OKP=1
        if [ "$C" = "200" ] && [ "$OKTAB" = "1" ] && [ "$OKP" = "1" ]; then
            check "$enc / $m" 1 "HTTP $C activeTab=$TAB props=$CNT nonEmpty=$EMPTY"
        else
            check "$enc / $m" 0 "HTTP $C activeTab=$TAB props=$CNT nonEmpty=$EMPTY"
        fi
    done
done

section "4b. FILTER /pasien"
for q in "/pasien?search=a" "/pasien?unit=ICU" "/pasien?status=aktif"; do
    C="$(http_code GET "$q" "$PRIMARY")"
    check "GET $q" "$([ "$C" = "200" ] && echo 1 || echo 0)" "HTTP $C"
done

section "4c. VARIAN FILTER MODUL"
for q in "/encounters/$ENC1/penunjang?group=abg" \
         "/encounters/$ENC1/farmasi?category=Antibiotik" \
         "/encounters/$ENC1/farmasi?search=a" \
         "/encounters/$ENC1/bundles?group=vap" \
         "/encounters/$ENC1/observasi?risk=high"; do
    C="$(http_code GET "$q" "$PRIMARY")"
    check "GET $(trunc "$q" 40)" "$([ "$C" = "200" ] && echo 1 || echo 0)" "HTTP $C"
done

http GET "/encounters/$ENC1/penunjang?group=abg" "$PRIMARY" "" "" 1 >/dev/null
AG="$(props < "$WORK/body.txt" | awk -F'|' '{print $4}')"
check "penunjang?group=abg -> activeGroup=abg" "$([ "$AG" = "abg" ] && echo 1 || echo 0)" "activeGroup=$AG"

# ========================================================= 5. KASUS NEGATIF

section "5. KASUS NEGATIF"

for m in $MODULES; do
    C="$(http_code GET "/encounters/BOGUS/$m" "$PRIMARY")"
    check "404: /encounters/BOGUS/$m" "$([ "$C" = "404" ] && echo 1 || echo 0)" "HTTP $C"
done
for m in $MODULES; do
    C="$(http_code GET "/encounters/1/$m" "$PRIMARY")"
    check "404: /encounters/1/$m (pk numerik)" "$([ "$C" = "404" ] && echo 1 || echo 0)" "HTTP $C"
done

GUEST="$WORK/jar-guest.txt"; rm -f "$GUEST"
for pth in /pasien "/encounters/$ENC1/profil" "/encounters/$ENC1/cppt" \
           "/encounters/$ENC1/penunjang" "/encounters/$ENC1/farmasi" \
           "/encounters/$ENC1/observasi" "/encounters/$ENC1/bundles"; do
    R="$(http GET "$pth" "$GUEST" "" "" 0)"
    C="${R%%|*}"; RD="${R#*|}"
    case "$RD" in */login) LOK=1 ;; *) LOK=0 ;; esac
    if [ "$C" = "302" ] && [ "$LOK" = "1" ]; then
        check "tamu: GET $pth -> 302 /login" 1 "HTTP $C -> $RD"
    else
        check "tamu: GET $pth -> 302 /login" 0 "HTTP $C -> $RD"
    fi
done

LONGX="$(head -c 3000 < /dev/zero | tr '\0' 'x')"
LONGY="$(head -c 3000 < /dev/zero | tr '\0' 'y')"
LONGZ="$(head -c 5000 < /dev/zero | tr '\0' 'z')"
GARBAGE=(
    "/encounters/$ENC1/observasi?dateFrom=bukan-tanggal&dateTo=99-99-99"
    "/encounters/$ENC1/observasi?risk=meltdown"
    "/encounters/$ENC1/observasi?page=-5"
    "/encounters/$ENC1/penunjang?group=<script>alert(1)</script>"
    "/encounters/$ENC1/bundles?group=zzz&page=-1"
    "/encounters/$ENC1/farmasi?search=$LONGX"
    "/pasien?page=-3&search=$LONGY"
    "/encounters/$ENC1/profil?x=$LONGZ"
)
for g in "${GARBAGE[@]}"; do
    C="$(http_code GET "$g" "$PRIMARY")"
    check "query rusak -> 200" "$([ "$C" = "200" ] && echo 1 || echo 0)" "HTTP $C $(trunc "$g" 30)"
done
# ======================================================= 6. JALUR TULIS

REFPEN="$BASE_URL/encounters/$ENC0/penunjang"
REFOBS="$BASE_URL/encounters/$ENC0/observasi"
TOK="$(get_token "$PRIMARY")"

# Flash error Inertia: POST lalu baca props errors dari halaman tujuan.
flash_err() {
    http POST "$1" "$PRIMARY" "$2" "$3" 1 >/dev/null
    "$PHP" -r '
    $h = file_get_contents("php://stdin");
    $i = strpos($h, "data-page=");
    if ($i === false) { exit; }
    $i += 10;
    $j = strpos($h, "\"", $i + 1);
    if ($j === false) { exit; }
    $decoded = json_decode(html_entity_decode(substr($h, $i + 1, $j - $i - 1), ENT_QUOTES, "UTF-8"), true);
    foreach (($decoded["props"]["errors"] ?? []) as $v) {
        foreach ((array) $v as $m) { if ($m !== "") { echo $m, "\n"; } }
    }
    ' < "$WORK/body.txt"
}

section "6. JALUR TULIS - PENUNJANG"

B1="$(dbcount "select count(*) as c from support_results where encounter_id = $ENC0_ID")"
B2="$(dbcount "select count(*) as c from abg_results where encounter_id = $ENC0_ID")"

C="$(http_code POST "/encounters/$ENC0/penunjang/results" "$PRIMARY" \
    "_token=$TOK;group=lab;key=leukosit;value=13.4;date=2026-09-07;time=08:00;by=Perawat Uji" "$REFPEN" 0)"
A1="$(dbcount "select count(*) as c from support_results where encounter_id = $ENC0_ID")"
V="$(dbscalar "select value from support_results where encounter_id = $ENC0_ID and result_key = 'leukosit' order by id desc limit 1")"
if [ "$C" = "302" ] && [ "$A1" = "$((B1 + 1))" ] && [ "$V" = "13.4" ]; then
    check "POST hasil LAB tersimpan" 1 "HTTP $C rows $B1->$A1 value=$V"
else
    check "POST hasil LAB tersimpan" 0 "HTTP $C rows $B1->$A1 value=$V"
fi

C="$(http_code POST "/encounters/$ENC0/penunjang/results" "$PRIMARY" \
    "_token=$TOK;group=micro;key=kultur_darah;value=Escherichia coli;date=2026-09-07;time=09:00;by=Perawat Uji" "$REFPEN" 0)"
A2="$(dbcount "select count(*) as c from support_results where encounter_id = $ENC0_ID")"
if [ "$C" = "302" ] && [ "$A2" = "$((A1 + 1))" ]; then
    check "POST hasil MIKROBIOLOGI tersimpan" 1 "HTTP $C rows $A1->$A2"
else
    check "POST hasil MIKROBIOLOGI tersimpan" 0 "HTTP $C rows $A1->$A2"
fi

C="$(http_code POST "/encounters/$ENC0/penunjang/abg" "$PRIMARY" \
    "_token=$TOK;ph=7.28;pco2=38;po2=88;hco3=19;be=-4;sao2=93;fio2=40;method=ABG;date=2026-09-07;time=10:00;by=Perawat Uji" "$REFPEN" 0)"
A3="$(dbcount "select count(*) as c from abg_results where encounter_id = $ENC0_ID")"
PH="$(dbscalar "select ph from abg_results where encounter_id = $ENC0_ID order by id desc limit 1")"
if [ "$C" = "302" ] && [ "$A3" = "$((B2 + 1))" ] && [ "$PH" = "7.28" ]; then
    check "POST hasil AGD tersimpan" 1 "HTTP $C rows $B2->$A3 pH=$PH"
else
    check "POST hasil AGD tersimpan" 0 "HTTP $C rows $B2->$A3 pH=$PH"
fi

C="$(http_code POST "/encounters/$ENC0/penunjang/results/delete" "$PRIMARY" \
    "_token=$TOK;group=lab;key=leukosit" "$REFPEN" 0)"
A4="$(dbcount "select count(*) as c from support_results where encounter_id = $ENC0_ID")"
if [ "$C" = "302" ] && [ "$A4" = "$((A2 - 1))" ]; then
    check "POST hapus hasil LAB" 1 "HTTP $C rows $A2->$A4"
else
    check "POST hapus hasil LAB" 0 "HTTP $C rows $A2->$A4"
fi

D1="$(dbcount "select count(*) as c from support_results where encounter_id = $ENC0_ID")"
MSG="$(flash_err "/encounters/$ENC0/penunjang/results" "_token=$TOK;group=lab;key=leukosit;value=bukan-angka" "$REFPEN" | tr '\n' '|')"
D2="$(dbcount "select count(*) as c from support_results where encounter_id = $ENC0_ID")"
if [ "$D2" = "$D1" ] && printf '%s' "$MSG" | grep -q 'angka'; then
    check "nilai LAB non-numerik ditolak + pesan Indonesia" 1 "rows $D1->$D2 msg=$MSG"
else
    check "nilai LAB non-numerik ditolak + pesan Indonesia" 0 "rows $D1->$D2 msg=$MSG"
fi

MSG2="$(flash_err "/encounters/$ENC0/penunjang/results" "_token=$TOK;group=abg;key=ph;value=7.4" "$REFPEN" | tr '\n' '|')"
if printf '%s' "$MSG2" | grep -q 'Kelompok penunjang tidak dikenal'; then
    check "group=abg ditolak di endpoint hasil generik" 1 "msg=$MSG2"
else
    check "group=abg ditolak di endpoint hasil generik" 0 "msg=$MSG2"
fi

# "group" adalah kata kunci SQL, jadi disaring lewat result_key.
R1="$(dbcount "select count(*) as c from support_results where encounter_id = $ENC0_ID and result_key like 'kultur%'")"
C="$(http_code POST "/encounters/$ENC0/penunjang/reset" "$PRIMARY" "_token=$TOK;group=micro" "$REFPEN" 0)"
R2="$(dbcount "select count(*) as c from support_results where encounter_id = $ENC0_ID and result_key like 'kultur%'")"
if [ "$C" = "403" ] && [ "$R2" = "$R1" ]; then
    check "reset tanpa confirm=1 -> 403" 1 "HTTP $C rows mikro $R1->$R2"
else
    check "reset tanpa confirm=1 -> 403" 0 "HTTP $C rows mikro $R1->$R2"
fi

C="$(http_code POST "/encounters/$ENC0/penunjang/reset" "$PRIMARY" "_token=$TOK;group=micro;confirm=1" "$REFPEN" 0)"
R3="$(dbcount "select count(*) as c from support_results where encounter_id = $ENC0_ID and result_key like 'kultur%'")"
if [ "$C" = "302" ] && [ "$R3" = "0" ]; then
    check "reset dengan confirm=1 -> 302 + baris terhapus" 1 "HTTP $C rows mikro $R1->$R3"
else
    check "reset dengan confirm=1 -> 302 + baris terhapus" 0 "HTTP $C rows mikro $R1->$R3"
fi
# ---------------------------------------------------------------- OBSERVASI

section "7. JALUR TULIS - OBSERVASI"

OBS_DATE="2026-09-07"
OBS_TIME="11:00"
# RR 26 -> 3 | HR 120 -> 2 | SBP 88 -> 1 | SpO2 92 -> 2 | Temp 39.2 -> 2 | Kesadaran Voice -> 1
# Total = 11 => emergency
VITALS="sys=88;dia=55;hr=120;rr=26;spo2=92;suhu=39.2;kesadaran=Voice"
BASE_POST="_token=$TOK;observation_date=$OBS_DATE;observation_time=$OBS_TIME;status=final;notes=Smoke test;map=66;gcs=15;intake=500;output=400;medications[0][name]=Noradrenalin;medications[0][dose]=0.1 mcg/kg/mnt;medications[0][category]=Inotropik / Vasopressor;medications[0][volume]=20;medications[0][route]=IV;medications[0][status]=Diberi;bundles[vap_1]=Ya;bundles[vap_2]=Tidak;bundles[clabsi_1]=Ya;devices[0][key]=ett;devices[0][present]=1;devices[0][startDate]=2026-09-06"

O1="$(dbcount "select count(*) as c from observations where encounter_id = $ENC0_ID")"
C="$(http_code POST "/encounters/$ENC0/observasi" "$PRIMARY" "$BASE_POST;$VITALS" "$REFOBS" 0)"
O2="$(dbcount "select count(*) as c from observations where encounter_id = $ENC0_ID")"
SLOT="$(dbcount "select count(*) as c from observations where encounter_id = $ENC0_ID and date(observation_date) = '$OBS_DATE' and observation_time = '$OBS_TIME'")"

if [ "$C" = "302" ] && [ "$O2" = "$((O1 + 1))" ]; then
    check "POST observasi baru tersimpan" 1 "HTTP $C rows $O1->$O2"
else
    check "POST observasi baru tersimpan" 0 "HTTP $C rows $O1->$O2"
fi
check "  slot jam Upsert: hanya 1 baris untuk slot itu" "$([ "$SLOT" = "1" ] && echo 1 || echo 0)" "baris=$SLOT"

read -r ST SC SR <<<"$(dbscalar "select ews_total from observations where encounter_id = $ENC0_ID and date(observation_date) = '$OBS_DATE' and observation_time = '$OBS_TIME'") $(dbscalar "select ews_scores from observations where encounter_id = $ENC0_ID and date(observation_date) = '$OBS_DATE' and observation_time = '$OBS_TIME'") $(dbscalar "select ews_risk from observations where encounter_id = $ENC0_ID and date(observation_date) = '$OBS_DATE' and observation_time = '$OBS_TIME'")"

SUM="$("$PHP" -r '$j=json_decode($argv[1],true); echo array_sum(array_map("intval",$j));' "$SC" 2>/dev/null)"
[ "$ST" = "$SUM" ] && check "  ews_total = jumlah 6 ews_scores" 1 "total=$ST jumlah=$SUM" \
                  || check "  ews_total = jumlah 6 ews_scores" 0 "total=$ST jumlah=$SUM"

EXPR="low"; T="${ST:-0}"
if [ "$T" -ge 7 ]; then EXPR="emergency"; elif [ "$T" -ge 5 ]; then EXPR="high"; elif [ "$T" -ge 3 ]; then EXPR="medium"; fi
[ "$SR" = "$EXPR" ] && check "  ews_risk cocok dengan ambang eskalasi" 1 "risk=$SR (hitung ulang $EXPR)" \
                     || check "  ews_risk cocok dengan ambang eskalasi" 0 "risk=$SR (hitung ulang $EXPR)"

OID="$(dbscalar "select id from observations where encounter_id = $ENC0_ID and date(observation_date) = '$OBS_DATE' and observation_time = '$OBS_TIME'")"
MED="$(dbcount "select count(*) as c from observation_medications where observation_id = $OID")"
BUN="$(dbcount "select count(*) as c from observation_bundle_answers where observation_id = $OID")"
DEV="$(dbcount "select count(*) as c from invasive_devices where encounter_id = $ENC0_ID and device_code = 'ett'")"
check "  baris obat tersimpan" "$([ "$MED" = "1" ] && echo 1 || echo 0)" "medications=$MED"
check "  jawaban bundle tersimpan" "$([ "$BUN" = "3" ] && echo 1 || echo 0)" "bundle answers=$BUN"
check "  perangkat invasif tersimpan" "$([ "$DEV" -ge 1 ] && echo 1 || echo 0)" "devices=$DEV"

# -- simpan slot yang sama lagi (upsert)
C="$(http_code POST "/encounters/$ENC0/observasi" "$PRIMARY" "${BASE_POST/notes=Smoke test/notes=Smoke test slot kedua};$VITALS" "$REFOBS" 0)"
SLOT2="$(dbcount "select count(*) as c from observations where encounter_id = $ENC0_ID and date(observation_date) = '$OBS_DATE' and observation_time = '$OBS_TIME'")"
if [ "$C" = "302" ] && [ "$SLOT2" = "1" ]; then
    check "slot jam duplikat -> UPSERT (baris tetap 1)" 1 "HTTP $C slot=$SLOT2"
else
    check "slot jam duplikat -> UPSERT (baris tetap 1)" 0 "HTTP $C slot=$SLOT2"
fi

# -- vital di luar rentang
E1="$(dbcount "select count(*) as c from observations where encounter_id = $ENC0_ID")"
MSG3="$(flash_err "/encounters/$ENC0/observasi" "_token=$TOK;observation_date=$OBS_DATE;observation_time=23:00;sys=9999;dia=55;hr=120;rr=26;spo2=92;suhu=39.2;kesadaran=Voice" "$REFOBS" | tr '\n' '|')"
E2="$(dbcount "select count(*) as c from observations where encounter_id = $ENC0_ID")"
if [ "$E2" = "$E1" ] && printf '%s' "$MSG3" | grep -qE 'rentang|wajar'; then
    check "vital di luar rentang ditolak, tanpa baris baru" 1 "rows $E1->$E2 msg=$MSG3"
else
    check "vital di luar rentang ditolak, tanpa baris baru" 0 "rows $E1->$E2 msg=$MSG3"
fi

# -- klien mencoba menimpa skor EWS
C="$(http_code POST "/encounters/$ENC0/observasi" "$PRIMARY" "${BASE_POST/observation_time=11:00/observation_time=12:00};ews_total=99;ews_risk=low;$VITALS" "$REFOBS" 0)"
FT="$(dbscalar "select ews_total from observations where encounter_id = $ENC0_ID and date(observation_date) = '$OBS_DATE' and observation_time = '12:00'")"
FR="$(dbscalar "select ews_risk from observations where encounter_id = $ENC0_ID and date(observation_date) = '$OBS_DATE' and observation_time = '12:00'")"
FS="$(dbscalar "select ews_scores from observations where encounter_id = $ENC0_ID and date(observation_date) = '$OBS_DATE' and observation_time = '12:00'")"
FC="$("$PHP" -r '$j=json_decode($argv[1],true); echo array_sum(array_map("intval",$j));' "$FS" 2>/dev/null)"
FCR="low"; [ "${FC:-0}" -ge 7 ] && FCR="emergency" || { [ "${FC:-0}" -ge 5 ] && FCR="high" || { [ "${FC:-0}" -ge 3 ] && FCR="medium"; }; }
if [ "$C" = "302" ] && [ "$FT" != "99" ] && [ "$FR" != "low" ] && [ "$FT" = "$FC" ] && [ "$FR" = "$FCR" ]; then
    check "klien tidak bisa menimpa ews_total/ews_risk" 1 "tersimpan total=$FT risk=$FR (hitung ulang $FC/$FCR)"
else
    check "klien tidak bisa menimpa ews_total/ews_risk" 0 "tersimpan total=$FT risk=$FR (hitung ulang $FC/$FCR)"
fi

# -- hapus observasi
C="$(http_code POST "/encounters/$ENC0/observasi/delete" "$PRIMARY" "_token=$TOK;date=$OBS_DATE;time=$OBS_TIME" "$REFOBS" 0)"
GONE="$(dbcount "select count(*) as c from observations where encounter_id = $ENC0_ID and date(observation_date) = '$OBS_DATE' and observation_time = '$OBS_TIME'")"
if [ "$C" = "302" ] && [ "$GONE" = "0" ]; then
    check "POST hapus observasi (slot dihapus)" 1 "HTTP $C sisa=$GONE"
else
    check "POST hapus observasi (slot dihapus)" 0 "HTTP $C sisa=$GONE"
fi

# ================================================ 8. KASUS NEGATIF LAIN

section "8. KASUS NEGATIF LAIN"

C="$(http_code POST "/encounters/$ENC0/observasi" "$PRIMARY" \
    "_token=token-palsu-1234567890;observation_date=$OBS_DATE;observation_time=13:00;sys=120;dia=70;hr=80;rr=18;spo2=98;suhu=36.8;kesadaran=Alert" "$REFOBS" 0)"
check "_token basi -> 419" "$([ "$C" = "419" ] && echo 1 || echo 0)" "HTTP $C"

C="$(http_code POST "/encounters/$ENC0/observasi" "$PRIMARY" \
    "observation_date=$OBS_DATE;observation_time=13:00;sys=120;dia=70;hr=80;rr=18;spo2=98;suhu=36.8;kesadaran=Alert" "$REFOBS" 0)"
check "_token tidak ada -> 419" "$([ "$C" = "419" ] && echo 1 || echo 0)" "HTTP $C"

# Session sudah logout: _token lama -> 419 (CSRF jalan sebelum auth),
# _token baru dari /login -> 302 ke /login.
LJ="$WORK/jar-dokter.txt"
STOK="$(get_token "$LJ")"
LO="$(http_code POST /logout "$LJ" "_token=$STOK" "" 0)"
check "logout dokter berhasil" "$([ "$LO" = "302" ] && echo 1 || echo 0)" "HTTP $LO"
C="$(http_code POST "/encounters/$ENC0/observasi" "$LJ" \
    "_token=$STOK;observation_date=$OBS_DATE;observation_time=14:00;sys=120;dia=70;hr=80;rr=18;spo2=98;suhu=36.8;kesadaran=Alert" "$REFOBS" 0)"
check "logout + _token lama -> 419 (CSRF, bukan 500)" "$([ "$C" = "419" ] && echo 1 || echo 0)" "HTTP $C"
NTOK="$(get_token "$LJ")"
R="$(http POST "/encounters/$ENC0/observasi" "$LJ" \
    "_token=$NTOK;observation_date=$OBS_DATE;observation_time=14:00;sys=120;dia=70;hr=80;rr=18;spo2=98;suhu=36.8;kesadaran=Alert" "$REFOBS" 0)"
C="${R%%|*}"; RD="${R#*|}"
case "$RD" in */login) LOK=1 ;; *) LOK=0 ;; esac
if [ "$C" = "302" ] && [ "$LOK" = "1" ]; then
    check "logout + _token baru -> 302 /login (bukan 500)" 1 "HTTP $C -> $RD"
else
    check "logout + _token baru -> 302 /login (bukan 500)" 0 "HTTP $C -> $RD"
fi
# ================================================= 9. AUDIT EWS KESELURUHAN

section "9. AUDIT EWS (SELURUH BARIS)"

# Hitung ulang skor EWS LANGSUNG dari band config('ews') - TIDAK memakai
# EwsScoringService - lalu bandingkan dengan nilai tersimpan dan dengan
# hasil EwsScoringService::calculate() untuk setiap baris observations.
cat >"$EWSAUDIT" <<'PHPEOF'
<?php
define('BASE', getenv('SMOKE_ROOT'));
require BASE . '/vendor/autoload.php';
$app = require BASE . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function independentEws(array $v): array {
    $cfg = config('ews');
    $scores = []; $total = 0;
    foreach ($cfg['parameters'] as $name => $p) {
        if ($p['type'] === 'map') {
            $raw = $v['kesadaran'] ?? null;
            $s = array_key_exists((string) $raw, $p['values']) ? (int) $p['values'][$raw] : 0;
        } else {
            $raw = $v[$p['field']] ?? null;
            if ($raw === null || $raw === '') { $s = 0; }
            else {
                $n = (float) $raw; $s = 0;
                foreach ($p['bands'] as $b) {
                    if ($n >= (float) $b['min'] && $n <= (float) $b['max']) { $s = (int) $b['score']; break; }
                }
            }
        }
        $scores[$name] = $s; $total += $s;
    }
    $risk = 'low';
    foreach ($cfg['escalation'] as $e) {
        $hi = $e['max'] === null ? PHP_INT_MAX : (int) $e['max'];
        if ($total >= (int) $e['min'] && $total <= $hi) { $risk = $e['level']; break; }
    }
    return ['total' => $total, 'scores' => $scores, 'risk' => $risk];
}

$svc = app(App\Services\EwsScoringService::class);
$bad = ['mismatch_sum' => 0, 'mismatch_total' => 0, 'mismatch_scores' => 0, 'mismatch_risk' => 0, 'mismatch_service' => 0];
$checked = 0;

foreach (DB::table('observations')->orderBy('id')->get() as $o) {
    $checked++;
    $exp = independentEws(['rr' => $o->rr, 'hr' => $o->hr, 'sys' => $o->sys,
        'spo2' => $o->spo2, 'suhu' => $o->suhu, 'kesadaran' => $o->kesadaran]);

    $stored = json_decode((string) $o->ews_scores, true) ?: [];
    $sum = 0; foreach ($stored as $s) { $sum += (int) $s; }
    if ($sum !== (int) $o->ews_total) { $bad['mismatch_sum']++; }
    if ((int) $o->ews_total !== $exp['total']) { $bad['mismatch_total']++; }
    foreach ($exp['scores'] as $k => $s) {
        if ((int) ($stored[$k] ?? -1) !== $s) { $bad['mismatch_scores']++; break; }
    }
    if ((string) $o->ews_risk !== $exp['risk']) { $bad['mismatch_risk']++; }

    try {
        $r = $svc->calculate(['rr' => $o->rr, 'hr' => $o->hr, 'sys' => $o->sys,
            'spo2' => $o->spo2, 'suhu' => $o->suhu, 'kesadaran' => $o->kesadaran]);
        if (is_array($r)) {
            $rt = $r['total'] ?? $r['ews_total'] ?? null;
            $rr = $r['risk'] ?? $r['ews_risk'] ?? null;
            if ($rt !== null && (int) $rt !== $exp['total']) { $bad['mismatch_service']++; }
            if ($rr !== null && (string) $rr !== $exp['risk']) { $bad['mismatch_service']++; }
        } else { $bad['mismatch_service']++; }
    } catch (Throwable $e) { $bad['mismatch_service']++; }
}

echo json_encode(['checked' => $checked] + $bad), PHP_EOL;
PHPEOF

AUD="$(SMOKE_ROOT="$ROOT_WIN" "$PHP" "$EWSAUDIT" 2>&1)"
if [ $? -ne 0 ] || [ -z "$AUD" ]; then
    check "audit EWS berjalan" 0 "$AUD"
else
    N="$(printf '%s' "$AUD" | "$PHP" -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["checked"];')"
    get() { printf '%s' "$AUD" | "$PHP" -r "\$j=json_decode(trim(stream_get_contents(STDIN)),true); echo \$j['$1'] ?? '?';"; }
    MS="$(get mismatch_sum)"; MT="$(get mismatch_total)"; MSC="$(get mismatch_scores)"; MR="$(get mismatch_risk)"; MSV="$(get mismatch_service)"
    check "audit EWS: total = jumlah 6 skor" "$([ "$MS" = "0" ] && echo 1 || echo 0)" "checked=$N mismatch=$MS"
    check "audit EWS: ews_total cocok hitungan independen dari config/ews" "$([ "$MT" = "0" ] && echo 1 || echo 0)" "checked=$N mismatch=$MT"
    check "audit EWS: ews_scores cocok band config/ews" "$([ "$MSC" = "0" ] && echo 1 || echo 0)" "checked=$N mismatch=$MSC"
    check "audit EWS: ews_risk cocok ambang eskalasi" "$([ "$MR" = "0" ] && echo 1 || echo 0)" "checked=$N mismatch=$MR"
    check "audit EWS: EwsScoringService::calculate() mereproduksi total+risk" "$([ "$MSV" = "0" ] && echo 1 || echo 0)" "checked=$N mismatch=$MSV"
fi

# ============================================================ 10. RINGKASAN

# ============================================================ 10. RESTORE

section "10. RESTORE DATABASE"

# Uji tulis di atas sengaja mengubah data (buktinya: setiap jalur tulis benar-benar
# menyentuh database). Supaya skrip aman dijalankan berulang kali DAN aplikasi
# ditinggalkan dalam keadaan seed yang bersih, database dikembalikan ke kondisi
# canonical di akhir - apa pun hasil PASS/FAIL.
if [ "$NO_RESET" = "1" ]; then
    check "restore seed (dilewati NO_RESET=1)" 1 "diminta oleh flag NO_RESET"
else
    OUT="$("$PHP" artisan migrate:fresh --seed --force 2>&1)"; RC=$?
    if [ $RC -eq 0 ] && ! printf '%s' "$OUT" | grep -qE 'FAIL|Exception|RuntimeException'; then
        check "restore seed (migrate:fresh --seed --force)" 1 "canonical"
    else
        check "restore seed (migrate:fresh --seed --force)" 0 "exit $RC"
    fi
    for pair in users:3 patients:6 encounters:6 observations:60 \
                observation_medications:218 observation_bundle_answers:780 \
                invasive_devices:23 support_results:123 abg_results:15; do
        t="${pair%%:*}"; want="${pair##*:}"
        got="$(dbcount "select count(*) as c from $t")"
        [ "$got" = "$want" ] && check "  row count $t kembali canonical" 1 "$got" \
                             || check "  row count $t kembali canonical" 0 "$got (expected $want)"
    done
fi

# ============================================================ 11. RINGKASAN

section "11. RINGKASAN"

echo ""
if [ "$FAILURES" -gt 0 ]; then
    echo "TOTAL $TOTAL check | PASS $((TOTAL - FAILURES)) | FAIL $FAILURES"
    exit 1
fi
echo "TOTAL $TOTAL check | PASS $TOTAL | FAIL 0"
echo "SMOKE TEST LULUS."
exit 0
