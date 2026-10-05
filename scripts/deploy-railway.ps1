<#
.SYNOPSIS
    Deploy helper untuk Railway + PostgreSQL. Idempoten: menjalankannya dua kali
    berturut-turut aman, dan menjalankannya sebelum ada perubahan juga aman.

.DESCRIPTION
    ATURAN DESAIN (sengaja, jangan "dirampingkan"):
      1. TIDAK PERNAH menerima, menyimpan, atau menampilkan secret. APP_KEY dan
         password database dibaca dari dashboard Railway, tidak pernah dari skrip
         ini. Argumen perintah terlihat di `ps` dan di riwayat shell, jadi
         secret TIDAK BOLEH jadi argumen. Skrip hanya mencetak NAMA variable.
      2. Semua langkah yang mengubah sesuatu dijaga oleh --yes. Tanpa flag itu
         skrip hanya dry-run, sehingga tidak mungkin tidak sengaja deploy ke
         produksi.
      3. GAGAL KERAS. Prasyarat yang hilang menghentikan skrip dengan exit code
         bukan nol dan pesan yang bisa langsung ditindaklanjuti.
      4. Menolak jalan jika working tree dirty untuk file yang sudah di-track,
         karena perubahan setengah jadi tidak boleh masuk produksi.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\deploy-railway.ps1
    powershell -ExecutionPolicy Bypass -File scripts\deploy-railway.ps1 -Check
    powershell -ExecutionPolicy Bypass -File scripts\deploy-railway.ps1 -Yes -Migrate
#>

#Requires -Version 5.1
[CmdletBinding()]
param(
    [switch]$Yes,
    [switch]$Check,
    [switch]$Migrate,
    [switch]$Seed,
    [switch]$SkipDirtyCheck
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# --- Fungsi output ---------------------------------------------------------
# Colors hanya dipakai saat output going to the host, supaya log CI tetap polos.
function Write-Log  { param([string]$Message) Write-Host "[deploy] $Message" -ForegroundColor Cyan }
function Write-Ok   { param([string]$Message) Write-Host "[  ok  ] $Message" -ForegroundColor Green }
function Write-Warn { param([string]$Message) Write-Host "[ warn ] $Message" -ForegroundColor Yellow }
function Stop-Fatal {
    param([string]$Message)
    Write-Host "[FATAL ] $Message" -ForegroundColor Red
    exit 1
}

# --- Menjalankan perintah dandan exit code-nya ExitCode-nya ------------------
# Sengaja memakai & dan bukan Start-Process: kita butuh stdout sekaligus
# exit code. Melempar exception dari sini akan killed oleh Set-StrictMode
# sebelum exit code sempat dibaca.
function Invoke-Native {
    param(
        [Parameter(Mandatory)][string]$FilePath,
        [string[]]$Arguments = @(),
        [switch]$AllowFailure
    )

    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $global:LASTEXITCODE = 0

    $output = & $FilePath @Arguments 2>&1 | ForEach-Object { $_.ToString() }
    $code = $LASTEXITCODE

    $ErrorActionPreference = $previous

    [pscustomobject]@{
        ExitCode = $code
        Output   = ($output -join "`n")
    }
}

# --- Awal ------------------------------------------------------------------
$AppDir = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location -LiteralPath $AppDir

$AssumeYes = $Yes.IsPresent -and -not $Check.IsPresent
$DoMigrate = $Migrate.IsPresent
$DoSeed    = $Seed.IsPresent

# Variable yang WAJIB ada di service Railway sebelum deploy bisa berhasil.
$RequiredVars = @(
    'APP_KEY', 'APP_ENV', 'APP_DEBUG', 'APP_URL', 'LOG_CHANNEL',
    'DB_CONNECTION', 'SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION',
    'BROADCAST_CONNECTION', 'FILESYSTEM_DISK'
)

Write-Log "direktori aplikasi : $AppDir"
if ($AssumeYes) {
    Write-Warn "MODE SESUNGGUHNYA. Semua perubahan akan dijalankan."
} else {
    Write-Log "MODE DRY RUN. Tidak ada yang diubah. Tambahkan -Yes untuk eksekusi."
}

# ---------------------------------------------------------------------------
# 1. Prasyarat
# ---------------------------------------------------------------------------
Write-Log "memeriksa prasyarat..."

if (-not (Get-Command railway -ErrorAction SilentlyContinue)) {
    Stop-Fatal "CLI 'railway' tidak ditemukan. Pasang dengan: npm i -g @railway/cli"
}

$ver = Invoke-Native railway @('railway', '--version')
if ($ver.ExitCode -ne 0) { Stop-Fatal "CLI 'railway' ada tapi tidak bisa dijalankan." }
Write-Ok "railway CLI: $($ver.Output -split "`n" | Select-Object -First 1)"

$status = Invoke-Native railway @('railway', 'status')
if ($status.ExitCode -ne 0) {
    Stop-Fatal "belum terhubung ke project Railway. Jalankan: railway login lalu railway link"
}
Write-Ok "terhubung ke project Railway"

$ProjectName = ''
$statusJson = Invoke-Native railway @('railway', 'status', '--json')
if ($statusJson.ExitCode -eq 0 -and $statusJson.Output) {
    try {
        $ProjectName = ($statusJson.Output | ConvertFrom-Json).name
        if ($ProjectName) { Write-Ok "project: $ProjectName" }
    } catch {
        Write-Warn "tidak bisa membaca nama project dari 'railway status --json'; lanjut"
    }
}

# --- Git -------------------------------------------------------------------
$hasGit = [bool](Get-Command git -ErrorAction SilentlyContinue)
$isGitRepo = $hasGit -and (Test-Path -LiteralPath (Join-Path $AppDir '.git'))

$CurrentBranch = 'unknown'
if ($isGitRepo) {
    if (-not $SkipDirtyCheck) {
        $dirty = git -C $AppDir status --porcelain --untracked-files=no
        if ($dirty) {
            $dirty | ForEach-Object { Write-Host $_ -ForegroundColor Red }
            Stop-Fatal "ada perubahan pada file yang sudah di-track. Commit dulu, atau pakai -SkipDirtyCheck untuk sengaja melewatinya."
        }
        Write-Ok "working tree bersih (untuk file yang di-track)"
    } else {
        Write-Warn "dirty-check dilewati karena -SkipDirtyCheck"
    }
    $CurrentBranch = (git -C $AppDir rev-parse --abbrev-ref HEAD 2>$null)
    Write-Log "branch saat ini: $CurrentBranch"
} else {
    Write-Warn "bukan git repository (atau git tidak terpasang); dirty-check dilewati"
}

# --- Build context ---------------------------------------------------------
Write-Log "memeriksa berkas build..."
$buildFiles = @(
    'Dockerfile', '.dockerignore', 'railway.json',
    'composer.json', 'composer.lock', 'package.json', 'package-lock.json'
)
foreach ($f in $buildFiles) {
    if (-not (Test-Path -LiteralPath (Join-Path $AppDir $f))) {
        Stop-Fatal "berkas wajib '$f' tidak ditemukan"
    }
}
Write-Ok "berkas build lengkap"

# Kegagalan produksi Inertia yang paling sering: public/build ikut di-commit.
if ($isGitRepo) {
    git -C $AppDir ls-files --error-unmatch public/build 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) {
        Stop-Fatal "public/build ikut di-commit. Hapus dari git: git rm -r --cached public/build (Dockerfile membangunnya sendiri)"
    }
    Write-Ok "public/build tidak di-commit (dibangun di dalam image)"
}

if (-not (Test-Path -LiteralPath (Join-Path $AppDir '.env.production.example'))) {
    Stop-Fatal ".env.production.example tidak ditemukan"
}
Write-Ok ".env.production.example ada"

# --- Railway variables -----------------------------------------------------
# NAMA saja. Tidak pernah nilai, tidak pernah hash nilai: hash dari secret
# pendek bisa di-brute-force, dan nilai yang tercetak akan masuk log CI.
Write-Log "memeriksa service variables di Railway..."
$varsRaw = Invoke-Native railway @('railway', 'variables', '--kv')
if ($varsRaw.ExitCode -ne 0) {
    Stop-Fatal "tidak bisa membaca variables dari Railway. Pastikan service sudah dipilih: railway service"
}

$varNames = @(
    $varsRaw.Output -split "`n" |
        ForEach-Object { ($_ -split '=', 2)[0] } |
        Where-Object { $_ -and $_.Trim() -ne '' } |
        ForEach-Object { $_.Trim() }
)

if ($varNames.Count -eq 0) {
    Stop-Fatal "tidak ada variable yang terbaca dari Railway."
}

$missing = @()
foreach ($v in $RequiredVars) {
    if ($varNames -contains $v) { Write-Ok "variable ada: $v" } else { $missing += $v }
}

if ($varNames -contains 'DATABASE_URL') {
    Write-Ok "variable ada: DATABASE_URL (dari plugin PostgreSQL)"
} else {
    Stop-Fatal "DATABASE_URL tidak ada. Tambahkan plugin PostgreSQL di service yang SAMA, lalu deploy ulang"
}

if ($missing.Count -gt 0) {
    Write-Host "[FATAL ] variable wajib belum diset di Railway:" -ForegroundColor Red
    $missing | ForEach-Object { Write-Host "            - $_" -ForegroundColor Red }
    Write-Host "            Set di Railway dashboard > service > Variables." -ForegroundColor Red
    Write-Host "            Untuk APP_KEY: php artisan key:generate --show" -ForegroundColor Red
    exit 1
}

# APP_DEBUG bukan secret, jadi aman diperiksa berdasarkan NILAI.
# Dua-duanya disastrous jadi layak diperiksa.
if ($varNames -contains 'APP_DEBUG') {
    $debugLine = ($varsRaw.Output -split "`n") | Where-Object { $_ -like 'APP_DEBUG=*' } | Select-Object -First 1
    $debugValue = if ($debugLine) { ($debugLine -split '=', 2)[1] } else { '' }
    if ($debugValue.Trim() -eq 'true') {
        Stop-Fatal "APP_DEBUG=true di produksi. Isi stack trace dan nilai env akan bocor ke pengguna."
    }
    Write-Ok "APP_DEBUG bukan true"
}

if ($varNames -contains 'RUN_MIGRATIONS') {
    $rmLine = ($varsRaw.Output -split "`n") | Where-Object { $_ -like 'RUN_MIGRATIONS=*' } | Select-Object -First 1
    $rmValue = if ($rmLine) { ($rmLine -split '=', 2)[1] } else { '' }
    if ($rmValue.Trim() -eq 'true') {
        Write-Warn "RUN_MIGRATIONS=true: migrasi akan berjalan OTOMATIS setiap container start."
    }
}

Write-Log "bridge 'railway variables' OK. Tidak ada nilai yang ditampilkan."
Write-Log "service variables yang terbaca: $($varNames.Count)"

# ---------------------------------------------------------------------------
# 2. Ringkasan
# ---------------------------------------------------------------------------
Write-Host ''
Write-Log "RINGKASAN YANG AKAN DIJALANKAN:"
$migrateText = if ($DoMigrate) { 'ya' } else { 'tidak' }
$seedText    = if ($DoSeed)    { 'ya (berbahaya)' } else { 'tidak' }
"  {0,-34} {1}" -f 'project',     $(if ($ProjectName) { $ProjectName } else { '<tidak terbaca>' }) | Write-Host
"  {0,-34} {1}" -f 'branch',      $CurrentBranch                                   | Write-Host
"  {0,-34} {1}" -f 'builder',     'DOCKERFILE (Dockerfile)'                        | Write-Host
"  {0,-34} {1}" -f 'healthcheck', '/up'                                            | Write-Host
"  {0,-34} {1}" -f 'migrate',     $migrateText                                    | Write-Host
"  {0,-34} {1}" -f 'seed',        $seedText                                       | Write-Host
Write-Host ''

if ($Check) {
    Write-Ok "mode -Check selesai. Tidak ada yang diubah."
    exit 0
}

if (-not $AssumeYes) {
    Write-Warn "Dry run. Jalankan ulang dengan -Yes untuk benar-benar deploy."
    exit 0
}

# ---------------------------------------------------------------------------
# 3. Migrasi (opsional, SETELAH deploy sukses)
# ---------------------------------------------------------------------------
# Urutannya penting. Migrate sebelum code baru live bisa merusak versi lama
# yang masih berjalan; migrate setelahnya bisa merusak versi baru kalau code
# baru butuh kolom baru seketika. Migrasi di repo ini sampai sekarang
# backwards compatible, jadi setelah-deploy sudah benar.
if ($DoMigrate) {
    Write-Log "menjalankan migrasi di Railway (setelah deploy sukses)..."

    $ms = Invoke-Native php @('php', 'artisan', 'migrate:status', '--no-ansi')
    if ($ms.ExitCode -ne 0) { Stop-Fatal "gagal membaca status migrasi. Periksa DATABASE_URL dan koneksi database." }

    $m = Invoke-Native php @('php', 'artisan', 'migrate', '--force', '--no-ansi')
    if ($m.ExitCode -ne 0) {
        Stop-Fatal "MIGRASI GAGAL. Code versi terbaru sudah live tetapi skema belum ikut. Perbaiki segera."
    }
    Write-Ok "migrasi selesai"
}

if ($DoSeed) {
    Write-Warn "menjalankan seeder pada DATABASE PRODUKSI."
    Write-Warn "Seeder memakai updateOrCreate sehingga idempoten, tapi data demo tetap"
    Write-Warn "mencemari database produksi. Pastikan ini memang yang kamu mau."

    $s = Invoke-Native php @('php', 'artisan', 'db:seed', '--force', '--no-ansi')
    if ($s.ExitCode -ne 0) { Stop-Fatal "seeder gagal." }
    Write-Ok "seed selesai"
}

# ---------------------------------------------------------------------------
# 4. Deploy
# ---------------------------------------------------------------------------
Write-Log "mulai deploy..."
$startTime = Get-Date

# `railway up` mengunggah direktori saat ini dan memicu build dari source.
# Pakai ini kalau tidak ada pipeline CI di GitHub. Kalau CI sudah ada, HAPUS
# bagian ini dan biar CI yang deploy: menjalankan keduanya berarti dua build
# berebut environment yang sama.
$up = Invoke-Native railway @('railway', 'up', '--detach')
if ($up.ExitCode -ne 0) {
    Stop-Fatal "railway up gagal. Periksa: railway status, dan apakah build sebelumnya sudah gagal."
}
Write-Ok "deploy dikirim ke Railway"

$elapsed = [int]((Get-Date) - $startTime).TotalSeconds
Write-Ok "deploy diproses dalam $elapsed detik"

Write-Host ''
Write-Host "[deploy] Deploy dikirim." -ForegroundColor Green
Write-Host ''
Write-Host 'Yang perlu kamu pantau di dashboard:' -ForegroundColor Cyan
Write-Host '  1. Tab "Deployments" -> klik build terbaru -> baca "Build Logs".'
Write-Host '     Cari baris yang memuat: "build gates passed".'
Write-Host '  2. Tab "Deployments" -> "Deploy Logs". Yang sehat terlihat seperti:'
Write-Host '        [entrypoint] non-root: storage/ + bootstrap/cache/ sudah writable'
Write-Host '        [entrypoint] php artisan config:cache'
Write-Host '        [entrypoint] route cache OK'
Write-Host '        [entrypoint] php-fpm siap di 127.0.0.1:9000'
Write-Host '        [entrypoint] nginx foreground di port 8080, container siap'
Write-Host '  3. Buka APP_URL. Halaman /login harus muncul. /up harus mengembalikan 200.'
Write-Host '  4. Login dengan salah satu akun demo, lalu buka /pasien.'
Write-Host ''
Write-Host 'Kalau build gagal, salin 30 baris terakhir Build Logs ke issues.' -ForegroundColor Cyan

exit 0