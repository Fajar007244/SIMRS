<#
.SYNOPSIS
    End-to-end smoke test untuk aplikasi SIMRS Observasi EWS (Laravel 12 + Inertia 2 + Vue 3).

.DESCRIPTION
    Menjalankan Parts 2 dari rencana integrasi secara otomatis:

      1. Reset database        : php artisan migrate:fresh --seed --force
      2. Boot check            : memastikan HTTP server menyala (menyalakan sendiri bila perlu)
      3. Login                 : ketiga role (perawat / bidan / dokter)
      4. Halaman baca          : /pasien + 6 modul Inertia, untuk 2 encounter
      5. Kasus negatif         : 404 encounter, 302 tamu, query string rusak
      6. Jalur tulis           : penunjang (lab/micro/AGD/delete/reset) dan
                                 observasi (simpan/upsert/hapus/obat/bundle)
      7. Kasus negative write  : _token basi (419), session logout (302)

    Aman dijalankan berulang kali: database selalu di-reset lebih dulu dan semua
    baris uji yang dibuat dihapus kembali di akhir (atau di-reset oleh run berikutnya).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\smoke-test.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\smoke-test.ps1 -BaseUrl http://127.00.1:8123 -NoReset
#>
[CmdletBinding()]
param(
    [string] $BaseUrl   = 'http://127.0.0.1:8123',
    [string] $PhpExe    = '',
    [switch] $NoReset,
    [switch] $KeepServer
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

# ------------------------------------------------------------------ alat

function Resolve-Php {
    if ($PhpExe -and (Test-Path -LiteralPath $PhpExe)) { return $PhpExe }
    if (Get-Command php -ErrorAction SilentlyContinue)     { return (Get-Command php).Source }
    foreach ($c in @('C:\xampp\php\php.exe', 'C:\php\php.exe', 'C:\tools\php\php.exe')) {
        if (Test-Path -LiteralPath $c) { return $c }
    }
    throw 'php.exe tidak ditemukan. Jalankan dengan -PhpExe <path-to-php.exe>.'
}

$Php = Resolve-Php

# Folder kerja sementara: cookie jar, body respons, dan helper PHP.
$Work = Join-Path ([System.IO.Path]::GetTempPath()) ('ewssmoke-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Path $Work -Force | Out-Null
$DbHelper = Join-Path $Work 'dbq.php'

# Helper PHP sementara untuk membaca sqlite langsung (dibuat lalu dihapus oleh skrip ini).
$DbHelperSource = @"
<?php
// Helper smoke test: menerima SQL lewat argv dan mencetak JSON.
putenv('APP_ENV=testing');
`$root = 'BASE_DIR_HERE';
require `$root . '/vendor/autoload.php';
`$app = require `$root . '/bootstrap/app.php';
`$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
`$sql = isset(`$argv[1]) ? `$argv[1] : '';
if (`$sql === '') { fwrite(STDERR, "no sql\n"); exit(2); }
try {
    `$rows = Illuminate\Support\Facades\DB::select(`$sql);
    echo json_encode(`$rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable `$e) {
    fwrite(STDERR, `$e->getMessage() . PHP_EOL);
    exit(3);
}
"@
$DbHelperSource = $DbHelperSource.Replace('BASE_DIR_HERE', ($Root -replace '\\', '/'))
[System.IO.File]::WriteAllText($DbHelper, $DbHelperSource, (New-Object System.Text.UTF8Encoding($false)))

function Invoke-DbQuery([string] $Sql) {
    $out = & $Php $DbHelper $Sql 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0) { throw "SQL gagal: $Sql -> $out" }
    $json = $out.Trim()
    if ($json -eq '' -or $json -eq '[]') { return @() }
    $r = $json | ConvertFrom-Json
    if ($null -eq $r) { return ,@() }
    return ,@($r)
}

function Invoke-DbScalar([string] $Sql) {
    # JANGAN bungkus dengan @() : Invoke-DbQuery sudah mengembalikan array
    # (lewat operator koma). @( ) di sini akan membuat array di dalam array
    # sehingga $rows[0] berupa array, bukan objek baris.
    $rows = Invoke-DbQuery $Sql
    $arr = @($rows)
    if ($arr.Count -eq 0) { return 0 }
    foreach ($pr in $arr[0].PSObject.Properties) { return $pr.Value }
    return 0
}
# ------------------------------------------------------- pencollector hasil

$script:Results  = New-Object System.Collections.ArrayList
$script:Failures = 0
$script:Section  = ''

function Section([string] $Name) {
    $script:Section = $Name
    Write-Host ''
    Write-Host ('=' * 96)
    Write-Host ("  $Name")
    Write-Host ('=' * 96)
    Write-Output ('{0,-58} {1,-8} {2}' -f 'CHECK', 'STATUS', 'RESULT')
    Write-Output ('-' * 96)
}

function Check([string] $Name, [bool] $Ok, [string] $Detail = '') {
    $tag = if ($Ok) { 'PASS' } else { 'FAIL' }
    if (-not $Ok) { $script:Failures++ }
    [void] $script:Results.Add([pscustomobject]@{
        Section = $script:Section; Check = $Name; Status = $tag; Detail = $Detail
    })
    Write-Output ('{0,-58} {1,-8} {2}' -f (Truncate $Name 58), $tag, $Detail)
}

function Truncate([string] $S, [int] $N) {
    if ($null -eq $S) { return '' }
    $S = $S -replace '\s+', ' '
    if ($S.Length -le $N) { return $S }
    return $S.Substring(0, $N - 1) + [char]0x2026
}

# ---------------------------------------------------------------- HTTP

function Http {
    param(
        [Parameter(Mandatory = $true)][string] $Method,
        [Parameter(Mandatory = $true)][string] $Path,
        [string] $Jar = '',
        [hashtable] $Data = $null,
        [switch] $NoFollow,
        [switch] $NoWriteCookie,
        [string] $Referer = ''
    )
    $bodyFile = Join-Path $Work ('body-' + [guid]::NewGuid().ToString('N').Substring(0, 8) + '.txt')
    $url = $BaseUrl.TrimEnd('/') + $Path
    $a = @('-s', '-o', $bodyFile, '-w', '%{http_code}|%{redirect_url}')
    # PENTING: hanya paksa metode saat tidak mengikuti redirect. Kalau -X POST
    # dipaksa pada permintaan yang juga -L, curl akan mengirim POST lagi ke
    # tujuan redirect (halaman baca) dan jawabannya 405, bukan halaman aslinya.
    if ($NoFollow -or $Method -in @('GET', 'HEAD')) { $a += @('-X', $Method) }
    if ($Jar) { $a += @('-b', $Jar); if (-not $NoWriteCookie) { $a += @('-c', $Jar) } }
    if ($Referer) { $a += @('-e', $Referer) }
    if ($Data) {
        foreach ($k in $Data.Keys) { $a += @('-d', ('{0}={1}' -f $k, [uri]::EscapeDataString([string] $Data[$k]))) }
    }
    if (-not $NoFollow) { $a += '-L' }
    if ($url.Length -gt 1000) {
        # Batas panjang baris perintah Windows: pakai file konfigurasi curl.
        $cfgFile = Join-Path $Work 'curl-url.cfg'
        [System.IO.File]::WriteAllText($cfgFile, ('url = "' + ($url -replace '\\', '\\\\' -replace '"', '\"') + '"'))
        $a += @('-K', $cfgFile)
    } else {
        $a += $url
    }
    $raw = & curl.exe @a 2>&1 | Out-String
    $parts = $raw.Trim() -split '\|', 2
    $code = 0
    if ($parts[0] -match '^\d{3}$') { $code = [int] $parts[0] }
    $body = ''
    if (Test-Path -LiteralPath $bodyFile) { $body = [System.IO.File]::ReadAllText($bodyFile) }
    return [pscustomobject]@{ Code = $code; Body = $body; Redirect = $(if ($parts.Count -gt 1) { $parts[1] } else { '' }) }
}

function Get-Token([string] $Jar) {
    # PENTING 1: cookie session harus ikut ditulis (-c) di sini, kalau tidak
    # POST berikutnya tidak punya session dan CSRF selalu 419.
    # PENTING 2: setelah login, GET /login dialihkan ke halaman tujuan sehingga
    # tidak ada lagi formulir login. Untuk sesi yang sudah login, _token diambil
    # dari form logout pada halaman Blade /pasien.
    foreach ($p in @('/login', '/pasien')) {
        $r = Http -Method GET -Path $p -Jar $Jar
        $m = [regex]::Match($r.Body, 'name="_token"\s+value="([^"]+)"')
        if ($m.Success) { return $m.Groups[1].Value }
    }
    return ''
}

function Login([string] $Role, [string] $Password) {
    $jar = Join-Path $Work ("jar-$Role.txt")
    Remove-Item -LiteralPath $jar -Force -ErrorAction SilentlyContinue
    $token = Get-Token $jar
    if (-not $token) { return [pscustomobject] @{ Ok = $false; Jar = $jar; Code = 0 } }
    $r = Http -Method POST -Path '/login' -Jar $jar -Data @{
        _token = $token; username = $Role; password = $Password
    } -NoFollow
    $ok = ($r.Code -eq 302) -and ($r.Redirect -notmatch '/login$')
    return [pscustomobject] @{ Ok = $ok; Jar = $jar; Code = $r.Code; Token = $token }
}

function Read-Props([string] $Html) {
    $i = $Html.IndexOf('data-page=')
    if ($i -lt 0) { return $null }
    $j = $Html.IndexOf('"', $i + 11)
    if ($j -lt 0) { return $null }
    $attr = $Html.Substring($i + 11, $j - $i - 11)
    try { return ([System.Net.WebUtility]::HtmlDecode($attr) | ConvertFrom-Json) }
    catch { return $null }
}

function Get-Page([string] $Path, [string] $Jar) {
    $r = Http -Method GET -Path $Path -Jar $Jar
    $page = Read-Props $r.Body
    return [pscustomobject] @{ Code = $r.Code; Body = $r.Body; Page = $page }
}
# ================================================================ 1. RESET DB

Section '1. RESET DATABASE'

if ($NoReset) {
    Check 'reset DB (migrate:fresh --seed)' $true 'dilewati (-NoReset)'
} else {
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    $out = & $Php 'artisan' 'migrate:fresh' '--seed' '--force' 2>&1 | Out-String
    $sw.Stop()
    $ok = ($LASTEXITCODE -eq 0) -and ($out -notmatch 'FAIL|Exception|RuntimeException')
    Check 'php artisan migrate:fresh --seed --force' $ok ("{0} ms" -f $sw.ElapsedMilliseconds)
    if (-not $ok) { Write-Host $out }
}

$expected = [ordered] @{
    users = 3; patients = 6; encounters = 6; observations = 60
    observation_medications = 218; observation_bundle_answers = 780
    invasive_devices = 23; support_results = 123; abg_results = 15
}
$tableList = ($expected.Keys) -join ', '
foreach ($t in $expected.Keys) {
    $got = Invoke-DbScalar "select count(*) as c from $t"
    Check "row count $t" ($got -eq $expected[$t]) ("$got (expected $($expected[$t]))")
}

# Daftar encounter hasil seed.
$encRows = Invoke-DbQuery 'select encounter_id from encounters order by encounter_id'
$Encounters = @($encRows | ForEach-Object { $_.encounter_id })
Check 'enam encounter tersedia' ($Encounters.Count -eq 6) ($Encounters -join ', ')

# Enc0 = encounter yang dipakai semua uji tulis. Dipilih sesuai brief.
$Enc0 = 'enc-159853-icu-20260906'
if ($Encounters -notcontains $Enc0) { $Enc0 = $Encounters[0] }
# Tabel anak (observations/support_results/abg_results) memakai FK INTEGER ke
# encounters.id, BUKAN string encounters.encounter_id. Semua SQL di bawah memakai
# $Enc0Id karena itu.
$Enc0Id = Invoke-DbScalar "select id from encounters where encounter_id = '$Enc0'"
Check 'encounter uji ada (string -> FK integer)' ($Enc0Id -gt 0) "$Enc0 = encounters.id $Enc0Id"

# ================================================================= 2. BOOT

Section '2. BOOT CHECK'

$StartedServer = $null
$port = ([uri] $BaseUrl).Port
$listening = $false
try {
    $probe = Http -Method GET -Path '/login' -NoFollow
    $listening = ($probe.Code -eq 200)
} catch { $listening = $false }

if ($listening) {
    Check "HTTP server menyala di $BaseUrl" $true 'login page 200'
} else {
    Write-Host "  [boot] server tidak aktif, menyalakan php artisan serve --port=$port ..."
    $logFile = Join-Path $Work 'serve.log'
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $Php
    $psi.Arguments = "artisan serve --host=127.0.0.1 --port=$port"
    $psi.WorkingDirectory = $Root
    $psi.UseShellExecute = $false
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $StartedServer = [System.Diagnostics.Process]::Start($psi)
    $ok = $false
    for ($i = 0; $i -lt 40; $i++) {
        Start-Sleep -Milliseconds 500
        try { $probe = Http -Method GET -Path '/login' -NoFollow; if ($probe.Code -eq 200) { $ok = $true; break } } catch { }
    }
    Check "menyalakan server di $BaseUrl" $ok ("pid $($StartedServer.Id)")
    if (-not $ok) { throw "Server tidak bisa dinyalakan di $BaseUrl. Log: $logFile" }
}

$ver = Http -Method GET -Path '/login' -NoFollow
Check 'GET /login -> 200' ($ver.Code -eq 200) "HTTP $($ver.Code)"
Check 'halaman login punya _token' (([regex]::Match($ver.Body, 'name="_token"')).Success) 'csrf ok'

# ================================================================= 3. LOGIN

Section '3. LOGIN (3 ROLE)'

$Sessions = @{}
foreach ($role in @('perawat', 'bidan', 'dokter')) {
    $s = Login $role ("$role" + '123')
    $Sessions[$role] = $s
    Check "login $role" $s.Ok "HTTP $($s.Code)"
}
$Primary = $Sessions['perawat'].Jar
if (-not $Sessions['perawat'].Ok) { throw 'Login perawat gagal; test dihentikan.' }
# ====================================================== 4. HALAMAN READ-ONLY

Section '4. HALAMAN READ-ONLY'

$Modules = @('profil', 'cppt', 'penunjang', 'farmasi', 'observasi', 'bundles')
$TargetEncs = @($Encounters[0], 'enc-159853-icu-20260906' | Select-Object -Unique)

foreach ($role in @('perawat', 'bidan', 'dokter')) {
    $jar = $Sessions[$role].Jar
    $p1 = Get-Page '/pasien' $jar
    Check "$role : GET /pasien" ($p1.Code -eq 200) "HTTP $($p1.Code)"
    $hasName = [bool] ([regex]::Match($p1.Body, 'No\.?\s*RM'))
    $hasHosp = $p1.Body -match 'Rotinsulu'
    $hasPage = $p1.Body -match 'rel="next"|pagination'
    Check "$role : /pasien isi (nama RS, No. RM, paginasi)" ($hasName -and $hasHosp -and $hasPage) "RS=$hasHosp RM=$hasName page=$hasPage"
    $m1 = Get-Page "/encounters/$($Encounters[0])/cppt" $jar
    Check "$role : modul klinis cppt" ($m1.Code -eq 200) "HTTP $($m1.Code)"
}

foreach ($enc in $TargetEncs) {
    foreach ($m in $Modules) {
        $r = Get-Page "/encounters/$enc/$m" $Primary
        $page = $r.Page
        $okCode = ($r.Code -eq 200)
        $okTab = ($null -ne $page -and $page.props.activeTab -eq $m)
        $props = @()
        if ($page) { $props = $page.props.PSObject.Properties.Name | Where-Object { $_ -ne 'errors' } }
        $nonEmpty = 0
        foreach ($k in $props) {
            $v = $page.props.$k
            if ($null -ne $v) {
                if ($v -is [string]) { if ($v.Length -gt 0) { $nonEmpty++ } } else { $nonEmpty++ }
            }
        }
        $okProps = ($props.Count -ge 3 -and $nonEmpty -eq $props.Count)
        $tabName = if ($page) { [string] $page.props.activeTab } else { '-' }
        Check "$enc / $m" ($okCode -and $okTab -and $okProps) "HTTP $($r.Code) activeTab=$tabName props=$($props.Count) nonEmpty=$nonEmpty"
    }
}

# --- /pasien dengan filter
Section '4b. FILTER /pasien'
$pasien = Get-Page '/pasien' $Primary
$firstName = [regex]::Match($pasien.Body, 'href="/encounters/([^"]+)/profil"').Groups[1].Value
$unitName = [regex]::Match($pasien.Body, 'ICU').Value
foreach ($q in @('/pasien?search=a', '/pasien?unit=ICU', '/pasien?status=aktif')) {
    $r = Get-Page $q $Primary
    Check "GET $q" ($r.Code -eq 200) "HTTP $($r.Code) len=$($r.Body.Length)"
}

# --- varian filter modul
Section '4c. VARIAN FILTER MODUL'
$Filters = @(
    "/encounters/$($Encounters[0])/penunjang?group=abg",
    "/encounters/$($Encounters[0])/farmasi?category=Antibiotik",
    "/encounters/$($Encounters[0])/farmasi?search=a",
    "/encounters/$($Encounters[0])/bundles?group=vap",
    "/encounters/$($Encounters[0])/observasi?risk=high"
)
foreach ($q in $Filters) {
    $r = Get-Page $q $Primary
    $tab = if ($r.Page) { $r.Page.props.activeTab } else { '-' }
    Check "GET $q" ($r.Code -eq 200 -and $null -ne $r.Page) "HTTP $($r.Code) activeTab=$tab"
}
$abg = Get-Page "/encounters/$($Encounters[0])/penunjang?group=abg" $Primary
Check 'penunjang?group=abg -> activeGroup=abg' ($abg.Page.props.activeGroup -eq 'abg') "activeGroup=$($abg.Page.props.activeGroup)"

# ========================================================= 5. KASUS NEGATIF

Section '5. KASUS NEGATIF'

foreach ($m in $Modules) {
    $r = Get-Page "/encounters/BOGUS/$m" $Primary
    Check "404: /encounters/BOGUS/$m" ($r.Code -eq 404) "HTTP $($r.Code)"
}
foreach ($m in $Modules) {
    $r = Get-Page "/encounters/1/$m" $Primary
    Check "404: /encounters/1/$m (pk numerik bukan encounter_id)" ($r.Code -eq 404) "HTTP $($r.Code)"
}
$guest = Join-Path $Work 'jar-guest.txt'
Remove-Item -LiteralPath $guest -Force -ErrorAction SilentlyContinue
foreach ($p in @('/pasien', "/encounters/$($Encounters[0])/profil", "/encounters/$($Encounters[0])/cppt",
    "/encounters/$($Encounters[0])/penunjang", "/encounters/$($Encounters[0])/farmasi",
    "/encounters/$($Encounters[0])/observasi", "/encounters/$($Encounters[0])/bundles")) {
    $r = Http -Method GET -Path $p -Jar $guest -NoFollow -NoWriteCookie
    Check "tamu: GET $p -> 302 /login" ($r.Code -eq 302 -and $r.Redirect -match '/login$') "HTTP $($r.Code) -> $($r.Redirect)"
}

$longX = 'x' * 3000
$longY = 'y' * 3000
$longZ = 'z' * 5000
$Garbage = @(
    "/encounters/$($Encounters[0])/observasi?dateFrom=bukan-tanggal&dateTo=99-99-99",
    "/encounters/$($Encounters[0])/observasi?risk=meltdown",
    "/encounters/$($Encounters[0])/observasi?page=-5",
    "/encounters/$($Encounters[0])/penunjang?group=<script>alert(1)</script>",
    "/encounters/$($Encounters[0])/bundles?group=zzz&page=-1",
    "/encounters/$($Encounters[0])/farmasi?search=$longX",
    "/pasien?page=-3&search=$longY",
    "/encounters/$($Encounters[0])/profil?x=$longZ"
)
foreach ($gq in $Garbage) {
    $r = Get-Page $gq $Primary
    Check "query rusak -> 200" ($r.Code -eq 200) ("HTTP $($r.Code) " + (Truncate $gq 34))
}
# ======================================================= 6. JALUR TULIS

$RefPen = "$BaseUrl/encounters/$Enc0/penunjang"
$RefObs = "$BaseUrl/encounters/$Enc0/observasi"

function Flash-Error([string] $Path, [hashtable] $Data, [string] $Referer, [string] $Jar) {
    $r = Http -Method POST -Path $Path -Jar $Jar -Data $Data -Referer $Referer
    $page = Read-Props $r.Body
    $errs = @()
    if ($page -and $page.props.errors) {
        foreach ($pr in $page.props.errors.PSObject.Properties) {
            foreach ($m in @($pr.Value)) { if ($m) { $errs += [string] $m } }
        }
    }
    return [pscustomobject] @{ Code = $r.Code; Errors = $errs; Body = $r.Body }
}

# ---------------------------------------------------------------- PENUNJANG

Section '6. JALUR TULIS - PENUNJANG'

$beforeSupport = Invoke-DbScalar "select count(*) as c from support_results where encounter_id = $Enc0Id"
$beforeAbg = Invoke-DbScalar "select count(*) as c from abg_results where encounter_id = $Enc0Id"

# -- create lab result
$tok = Get-Token $Primary
$r = Http -Method POST -Path "/encounters/$Enc0/penunjang/results" -Jar $Primary -Referer $RefPen -Data @{
    _token = $tok; group = 'lab'; key = 'leukosit'; value = '13.4'; date = '2026-09-07'; time = '08:00'; by = 'Perawat Uji'
} -NoFollow
$afterSupport = Invoke-DbScalar "select count(*) as c from support_results where encounter_id = $Enc0Id"
$row = @(Invoke-DbQuery "select value, numeric_value from support_results where encounter_id = $Enc0Id and result_key = 'leukosit' order by id desc limit 1")
$labOk = ($r.Code -eq 302) -and ($afterSupport -eq $beforeSupport + 1) -and ($row.Count -ge 1) -and ($row[0].value -eq '13.4')
$labVal = if ($row.Count) { [string] $row[0].value } else { "-" }
Check 'POST hasil LAB tersimpan' $labOk "HTTP $($r.Code) rows $beforeSupport->$afterSupport value=$labVal"

# -- create microbiology result (free text)
$r = Http -Method POST -Path "/encounters/$Enc0/penunjang/results" -Jar $Primary -Referer $RefPen -Data @{
    _token = $tok; group = 'micro'; key = 'kultur_darah'; value = 'Escherichia coli'; date = '2026-09-07'; time = '09:00'; by = 'Perawat Uji'
} -NoFollow
$afterSupport2 = Invoke-DbScalar "select count(*) as c from support_results where encounter_id = $Enc0Id"
$row2 = @(Invoke-DbQuery "select value from support_results where encounter_id = $Enc0Id and result_key = 'kultur_darah' order by id desc limit 1")
$microVal = if ($row2.Count) { [string] $row2[0].value } else { "-" }
Check 'POST hasil MIKROBIOLOGI tersimpan' (($r.Code -eq 302) -and ($afterSupport2 -eq $afterSupport + 1) -and ($microVal -eq 'Escherichia coli')) "HTTP $($r.Code) rows $afterSupport->$afterSupport2 value=$microVal"

# -- create AGD
$r = Http -Method POST -Path "/encounters/$Enc0/penunjang/abg" -Jar $Primary -Referer $RefPen -Data @{
    _token = $tok; ph = '7.28'; pco2 = '38'; po2 = '88'; hco3 = '19'; be = '-4'; sao2 = '93'; fio2 = '40'
    method = 'ABG'; date = '2026-09-07'; time = '10:00'; by = 'Perawat Uji'
} -NoFollow
$afterAbg = Invoke-DbScalar "select count(*) as c from abg_results where encounter_id = $Enc0Id"
$abgRow = @(Invoke-DbQuery "select ph, pco2, sao2, fio2 from abg_results where encounter_id = $Enc0Id order by id desc limit 1")
$abgPh  = if ($abgRow.Count) { [string] $abgRow[0].ph } else { "-" }
$abgFiO2 = if ($abgRow.Count) { [string] $abgRow[0].fio2 } else { "-" }
Check 'POST hasil AGD tersimpan' (($r.Code -eq 302) -and ($afterAbg -eq $beforeAbg + 1) -and ($abgPh -eq '7.28')) "HTTP $($r.Code) rows $beforeAbg->$afterAbg pH=$abgPh FiO2=$abgFiO2"

# -- delete one result
$r = Http -Method POST -Path "/encounters/$Enc0/penunjang/results/delete" -Jar $Primary -Referer $RefPen -Data @{
    _token = $tok; group = 'lab'; key = 'leukosit'
} -NoFollow
$afterDel = Invoke-DbScalar "select count(*) as c from support_results where encounter_id = $Enc0Id"
$left = Invoke-DbScalar "select count(*) as c from support_results where encounter_id = $Enc0Id and result_key = 'leukosit'"
Check 'POST hapus hasil LAB' (($r.Code -eq 302) -and ($afterDel -eq $afterSupport2 - 1)) "HTTP $($r.Code) rows $afterSupport2->$afterDel (sisa leukosit=$left)"

# -- non-numeric lab value rejected
$cBefore = Invoke-DbScalar "select count(*) as c from support_results where encounter_id = $Enc0Id"
$e = Flash-Error "/encounters/$Enc0/penunjang/results" @{ _token = $tok; group = 'lab'; key = 'leukosit'; value = 'bukan-angka' } $RefPen $Primary
$cAfter = Invoke-DbScalar "select count(*) as c from support_results where encounter_id = $Enc0Id"
$msg = ($e.Errors -join ' | ')
$indo = $msg -match 'angka'
Check 'nilai LAB non-numerik ditolak + pesan Indonesia' (($e.Code -eq 200) -and ($cAfter -eq $cBefore) -and $indo) "HTTP $($e.Code) rows $cBefore->$cAfter msg=`"$msg`""

# -- group=abg rejected on generic endpoint
$e2 = Flash-Error "/encounters/$Enc0/penunjang/results" @{ _token = $tok; group = 'abg'; key = 'ph'; value = '7.4' } $RefPen $Primary
$msg2 = ($e2.Errors -join ' | ')
Check 'group=abg ditolak di endpoint hasil generik' (($e2.Code -eq 200) -and ($msg2 -match 'Kelompok penunjang tidak dikenal')) "HTTP $($e2.Code) msg=`"$msg2`""

# -- reset without / with confirm
$beforeReset = Invoke-DbScalar "select count(*) as c from support_results where encounter_id = $Enc0Id and result_key like 'kultur%'"
$r = Http -Method POST -Path "/encounters/$Enc0/penunjang/reset" -Jar $Primary -Referer $RefPen -Data @{ _token = $tok; group = 'micro' } -NoFollow
$midReset = Invoke-DbScalar "select count(*) as c from support_results where encounter_id = $Enc0Id and result_key like 'kultur%'"
Check 'reset tanpa confirm=1 -> 403' (($r.Code -eq 403) -and ($midReset -eq $beforeReset)) "HTTP $($r.Code) rows mikro $beforeReset->$midReset"

$r = Http -Method POST -Path "/encounters/$Enc0/penunjang/reset" -Jar $Primary -Referer $RefPen -Data @{ _token = $tok; group = 'micro'; confirm = '1' } -NoFollow
$afterReset = Invoke-DbScalar "select count(*) as c from support_results where encounter_id = $Enc0Id and result_key like 'kultur%'"
Check 'reset dengan confirm=1 -> 302 + baris terhapus' (($r.Code -eq 302) -and ($afterReset -eq 0)) "HTTP $($r.Code) rows mikro $beforeReset->$afterReset"
# ------------------------------------------------------ helper audit EWS

# Hitung ulang skor EWS LANGSUNG dari band config('ews') - TIDAK memakai
# EwsScoringService - lalu bandingkan dengan nilai tersimpan dan dengan
# hasil EwsScoringService::calculate() untuk setiap baris observations.
$EwsAudit = Join-Path $Work 'ews_audit.php'
$EwsSource = @"
<?php
define('BASE', 'BASE_DIR_HERE');
require BASE . '/vendor/autoload.php';
`$app = require BASE . '/bootstrap/app.php';
`$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Perhitungan independen: hanya membaca band di config/ews.php.
function independentEws(array `$_v): array {
    `$cfg = config('ews');
    `$scores = []; `$total = 0;
    foreach (`$cfg['parameters'] as `$name => `$p) {
        if (`$p['type'] === 'map') {
            `$raw = `$_v['kesadaran'] ?? null;
            `$s = array_key_exists((string) `$raw, `$p['values']) ? (int) `$p['values'][`$raw] : 0;
        } else {
            `$f = `$p['field'];
            `$raw = `$_v[`$f] ?? null;
            if (`$raw === null || `$raw === '') { `$s = 0; }
            else {
                `$n = (float) `$raw; `$s = 0;
                foreach (`$p['bands'] as `$b) {
                    if (`$n >= (float) `$b['min'] && `$n <= (float) `$b['max']) { `$s = (int) `$b['score']; break; }
                }
            }
        }
        `$scores[`$name] = `$s; `$total += `$s;
    }
    `$risk = 'low';
    foreach (`$cfg['escalation'] as `$e) {
        `$hi = `$e['max'] === null ? PHP_INT_MAX : (int) `$e['max'];
        if (`$total >= (int) `$e['min'] && `$total <= `$hi) { `$risk = `$e['level']; break; }
    }
    return ['total' => `$total, 'scores' => `$scores, 'risk' => `$risk];
}

`$svc = app(App\Services\EwsScoringService::class);
`$rows = DB::table('observations')->orderBy('id')->get();
`$checked = 0; `$badTotal = 0; `$badScores = 0; `$badRisk = 0; `$badSvc = 0; `$badSum = 0;
`$examples = [];

foreach (`$rows as `$o) {
    `$checked++;
    `$v = ['rr' => `$o->rr, 'hr' => `$o->hr, 'sys' => `$o->sys, 'spo2' => `$o->spo2,
           'suhu' => `$o->suhu, 'kesadaran' => `$o->kesadaran];
    `$exp = independentEws(`$v);

    `$storedScores = json_decode((string) `$o->ews_scores, true) ?: [];
    `$sumStored = 0; foreach (`$storedScores as `$s) { `$sumStored += (int) `$s; }
    if (`$sumStored !== (int) `$o->ews_total) { `$badSum++; }

    if ((int) `$o->ews_total !== `$exp['total']) {
        `$badTotal++;
        if (count(`$examples) < 5) { `$examples[] = 'id=' . `$o->id . ' stored=' . `$o->ews_total . ' expected=' . `$exp['total']; }
    }
    foreach (`$exp['scores'] as `$k => `$s) {
        if ((int) (`$storedScores[`$k] ?? -1) !== `$s) { `$badScores++; break; }
    }
    if ((string) `$o->ews_risk !== `$exp['risk']) { `$badRisk++; }

    try {
        `$r = `$svc->calculate(`$v);
        `$rTotal = is_array(`$r) ? (`$r['total'] ?? `$r['ews_total'] ?? null) : null;
        `$rRisk  = is_array(`$r) ? (`$r['risk']  ?? `$r['ews_risk']  ?? null) : null;
        if (`$rTotal !== null && (int) `$rTotal !== `$exp['total']) { `$badSvc++; }
        if (`$rRisk  !== null && (string) `$rRisk !== `$exp['risk'])  { `$badSvc++; }
    } catch (Throwable `$e) { `$badSvc++; }
}

echo json_encode([
    'checked' => `$checked, 'mismatch_total' => `$badTotal, 'mismatch_scores' => `$badScores,
    'mismatch_risk' => `$badRisk, 'mismatch_sum' => `$badSum, 'mismatch_service' => `$badSvc,
    'examples' => `$examples,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
"@
$EwsSource = $EwsSource.Replace('BASE_DIR_HERE', ($Root -replace '\\', '/'))
[System.IO.File]::WriteAllText($EwsAudit, $EwsSource, (New-Object System.Text.UTF8Encoding($false)))

function Invoke-EwsAudit {
    $out = & $Php $EwsAudit 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0) { throw "audit EWS gagal: $out" }
    return ($out.Trim() | ConvertFrom-Json)
}

# ---------------------------------------------------------------- OBSERVASI

Section '7. JALUR TULIS - OBSERVASI'

$ObsDate = '2026-09-07'
$ObsTime = '11:00'

# Vital yang sengaja dipilih agar exercise beberapa band:
#   RR 26 -> 3 | HR 120 -> 2 | SBP 88 -> 1 | SpO2 92 -> 2 | Temp 39.2 -> 2 | Kesadaran Voice -> 1
# Total = 3+2+1+2+2+1 = 11  => emergency
$Vitals = @{ sys = '88'; dia = '55'; hr = '120'; rr = '26'; spo2 = '92'; suhu = '39.2'; kesadaran = 'Voice' }

$obsBefore = Invoke-DbScalar "select count(*) as c from observations where encounter_id = $Enc0Id"

$Payload = @{ _token = $tok; observation_date = $ObsDate; observation_time = $ObsTime; status = 'final'
    notes = 'Smoke test observasi'; map = '66'; gcs = '15'; intake = '500'; output = '400'
    'medications[0][name]' = 'Noradrenalin'; 'medications[0][dose]' = '0.1 mcg/kg/mnt'
    'medications[0][category]' = 'Inotropik / Vasopressor'; 'medications[0][volume]' = '20'
    'medications[0][route]' = 'IV'; 'medications[0][status]' = 'Diberi'
    'bundles[vap_1]' = 'Ya'; 'bundles[vap_2]' = 'Tidak'; 'bundles[clabsi_1]' = 'Ya'
    'devices[0][key]' = 'ett'; 'devices[0][present]' = '1'; 'devices[0][startDate]' = '2026-09-06'
}
foreach ($k in $Vitals.Keys) { $Payload[$k] = $Vitals[$k] }

$r = Http -Method POST -Path "/encounters/$Enc0/observasi" -Jar $Primary -Referer $RefObs -Data $Payload -NoFollow
$obsAfter = Invoke-DbScalar "select count(*) as c from observations where encounter_id = $Enc0Id"
$obsRow = @(Invoke-DbQuery "select id, ews_total, ews_scores, ews_risk, sys, hr, rr, spo2, suhu, kesadaran from observations where encounter_id = $Enc0Id and date(observation_date) = '$ObsDate' and observation_time = '$ObsTime'")
$okObs = ($r.Code -eq 302) -and ($obsAfter -eq $obsBefore + 1) -and ($obsRow.Count -eq 1)
Check 'POST observasi baru tersimpan' $okObs "HTTP $($r.Code) rows $obsBefore->$obsAfter"
Check '  slot jam Upsert: hanya 1 baris untuk slot itu' ($obsRow.Count -eq 1) "baris=$($obsRow.Count)"

$storedTotal = if ($obsRow.Count) { [int] $obsRow[0].ews_total } else { -1 }
$storedRisk  = if ($obsRow.Count) { [string] $obsRow[0].ews_risk } else { '' }
$storedScores = if ($obsRow.Count) { ($obsRow[0].ews_scores | ConvertFrom-Json) } else { $null }
$expTotal = 0
foreach ($n in @('RR', 'HR', 'SBP', 'SpO2', 'Temp', 'Kesadaran')) { $expTotal += [int] $storedScores.$n }
Check '  ews_total = jumlah 6 ews_scores' ($storedTotal -eq $expTotal) "total=$storedTotal jumlah=$expTotal"
$expRisk = if ($storedTotal -ge 7) { 'emergency' } elseif ($storedTotal -ge 5) { 'high' } elseif ($storedTotal -ge 3) { 'medium' } else { 'low' }
Check '  ews_risk cocok dengan ambang eskalasi' ($storedRisk -eq $expRisk) "risk=$storedRisk (hitung ulang $expRisk)"

$medCount = Invoke-DbScalar "select count(*) as c from observation_medications where observation_id = $($obsRow[0].id)"
Check '  baris obat tersimpan' ($medCount -eq 1) "medications=$medCount"
$bCount = Invoke-DbScalar "select count(*) as c from observation_bundle_answers where observation_id = $($obsRow[0].id)"
Check '  jawaban bundle tersimpan' ($bCount -eq 3) "bundle answers=$bCount"
$dCount = Invoke-DbScalar "select count(*) as c from invasive_devices where encounter_id = $Enc0Id and device_code = 'ett'"
Check '  perangkat invasif tersimpan' ($dCount -ge 1) "devices=$dCount"
# -- simpan slot yang sama lagi (harus UPSERT, jumlah baris tetap 1)
$Payload2 = $Payload.Clone()
$Payload2['_token'] = $tok
$Payload2['notes'] = 'Smoke test observasi (slot kedua)'
$Payload2['medications[0][dose]'] = '0.2 mcg/kg/mnt'
$r2 = Http -Method POST -Path "/encounters/$Enc0/observasi" -Jar $Primary -Referer $RefObs -Data $Payload2 -NoFollow
$slotCount = Invoke-DbScalar "select count(*) as c from observations where encounter_id = $Enc0Id and date(observation_date) = '$ObsDate' and observation_time = '$ObsTime'"
$slotTotal = Invoke-DbScalar "select count(*) as c from observations where encounter_id = $Enc0Id"
Check 'slot jam duplikat -> UPSERT (baris tetap 1)' (($r2.Code -eq 302) -and ($slotCount -eq 1)) "HTTP $($r2.Code) slot=$slotCount total-enc=$slotTotal"

# -- nilai vital di luar rentang harus ditolak
$cBefore = Invoke-DbScalar "select count(*) as c from observations where encounter_id = $Enc0Id"
$e3 = Flash-Error "/encounters/$Enc0/observasi" @{
    _token = $tok; observation_date = '2026-09-07'; observation_time = '23:00'
    sys = '9999'; dia = '55'; hr = '120'; rr = '26'; spo2 = '92'; suhu = '39.2'; kesadaran = 'Voice'
} $RefObs $Primary
$cAfter = Invoke-DbScalar "select count(*) as c from observations where encounter_id = $Enc0Id"
$msg3 = ($e3.Errors -join ' | ')
Check 'vital di luar rentang ditolak, tanpa baris baru' (($e3.Code -eq 200) -and ($cAfter -eq $cBefore) -and ($msg3 -match 'rentang|wajar')) "HTTP $($e3.Code) rows $cBefore->$cAfter msg=`"$msg3`""

# -- klien mencoba memaksakan skor EWS
$Payload3 = $Payload.Clone()
$Payload3['_token'] = $tok
$Payload3['observation_time'] = '12:00'
$Payload3['ews_total'] = '99'; $Payload3['ews_risk'] = 'low'; $Payload3['ews_scores[Rr]'] = '0'
$r3 = Http -Method POST -Path "/encounters/$Enc0/observasi" -Jar $Primary -Referer $RefObs -Data $Payload3 -NoFollow
$forced = @(Invoke-DbQuery "select ews_total, ews_risk from observations where encounter_id = $Enc0Id and date(observation_date) = '$ObsDate' and observation_time = '12:00'")
$forcedScores = $null
if ($forced.Count) {
    $full = @(Invoke-DbQuery "select ews_scores from observations where encounter_id = $Enc0Id and date(observation_date) = '$ObsDate' and observation_time = '12:00'")
    $forcedScores = ($full[0].ews_scores | ConvertFrom-Json)
}
$calcTotal = 0
if ($forcedScores) { foreach ($n in @('RR', 'HR', 'SBP', 'SpO2', 'Temp', 'Kesadaran')) { $calcTotal += [int] $forcedScores.$n } }
$forcedRisk = if ($forced.Count) { [string] $forced[0].ews_risk } else { '' }
$calcRisk = if ($calcTotal -ge 7) { 'emergency' } elseif ($calcTotal -ge 5) { 'high' } elseif ($calcTotal -ge 3) { 'medium' } else { 'low' }
Check 'klien tidak bisa menimpa ews_total/ews_risk' (($r3.Code -eq 302) -and ($forced.Count -eq 1) -and ([int] $forced[0].ews_total -ne 99) -and ($forced[0].ews_risk -ne 'low') -and ([int] $forced[0].ews_total -eq $calcTotal) -and ($forcedRisk -eq $calcRisk)) "tersimpan total=$($forced[0].ews_total) risk=$forcedRisk (hitung ulang $calcTotal/$calcRisk)"

# -- hapus observasi
$tok2 = Get-Token $Primary
$r4 = Http -Method POST -Path "/encounters/$Enc0/observasi/delete" -Jar $Primary -Referer $RefObs -Data @{
    _token = $tok2; date = $ObsDate; time = $ObsTime
} -NoFollow
$gone = Invoke-DbScalar "select count(*) as c from observations where encounter_id = $Enc0Id and date(observation_date) = '$ObsDate' and observation_time = '$ObsTime'"
Check 'POST hapus observasi (slot dihapus)' (($r4.Code -eq 302) -and ($gone -eq 0)) "HTTP $($r4.Code) sisa=$gone"

# ================================================ 8. KASUS NEGATIF LAIN

Section '8. KASUS NEGATIF LAIN'

# _token basi -> 419
$stale = Http -Method POST -Path "/encounters/$Enc0/observasi" -Jar $Primary -Referer $RefObs -Data @{
    _token = 'token-palsu-1234567890'; observation_date = '2026-09-07'; observation_time = '13:00'
    sys = '120'; dia = '70'; hr = '80'; rr = '18'; spo2 = '98'; suhu = '36.8'; kesadaran = 'Alert'
} -NoFollow
Check '_token basi -> 419' ($stale.Code -eq 419) "HTTP $($stale.Code)"

# tanpa _token -> 419
$none = Http -Method POST -Path "/encounters/$Enc0/observasi" -Jar $Primary -Referer $RefObs -Data @{
    observation_date = '2026-09-07'; observation_time = '13:00'
    sys = '120'; dia = '70'; hr = '80'; rr = '18'; spo2 = '98'; suhu = '36.8'; kesadaran = 'Alert'
} -NoFollow
Check '_token tidak ada -> 419' ($none.Code -eq 419) "HTTP $($none.Code)"

# Session sudah logout.
#
# PENTING: dua kasus dibedakan karena urutannya berbeda.
#  1. _token LAMA (session sudah dihancurkan) -> 419. Ini perilaku Laravel yang
#     benar: middleware CSRF berjalan sebelum auth, jadi token yang sudah tidak
#     sah ditolak lebih dulu. 419 = "CSRF gagal", BUKAN 500.
#  2. _token BARU dari /login (session baru, tapi belum login) -> 302 ke /login.
#     Ini kasus yang diminta brief: POST dari sesi yang sudah logout.
$logoutJar = $Sessions['dokter'].Jar
$staleTok = Get-Token $logoutJar
$lo = Http -Method POST -Path '/logout' -Jar $logoutJar -Data @{ _token = $staleTok } -NoFollow
Check 'logout dokter berhasil' ($lo.Code -eq 302) "HTTP $($lo.Code)"

$after = Http -Method POST -Path "/encounters/$Enc0/observasi" -Jar $logoutJar -Data @{
    _token = $staleTok; observation_date = '2026-09-07'; observation_time = '14:00'
    sys = '120'; dia = '70'; hr = '80'; rr = '18'; spo2 = '98'; suhu = '36.8'; kesadaran = 'Alert'
} -NoFollow
Check 'logout + _token lama -> 419 (CSRF, bukan 500)' ($after.Code -eq 419) "HTTP $($after.Code)"

$freshTok = Get-Token $logoutJar
$after2 = Http -Method POST -Path "/encounters/$Enc0/observasi" -Jar $logoutJar -Referer $RefObs -Data @{
    _token = $freshTok; observation_date = '2026-09-07'; observation_time = '14:00'
    sys = '120'; dia = '70'; hr = '80'; rr = '18'; spo2 = '98'; suhu = '36.8'; kesadaran = 'Alert'
} -NoFollow
Check 'logout + _token baru -> 302 /login (bukan 500)' (($after2.Code -eq 302) -and ($after2.Redirect -match '/login$')) "HTTP $($after2.Code) -> $($after2.Redirect)"

# ================================================= 9. AUDIT EWS KESELURUHAN

Section '9. AUDIT EWS (SELURUH BARIS)'

try {
    $audit = Invoke-EwsAudit
    Check "audit EWS: total = jumlah 6 skor" ($audit.mismatch_sum -eq 0) "checked=$($audit.checked) mismatch=$($audit.mismatch_sum)"
    Check 'audit EWS: ews_total cocok hitungan independen dari config/ews' ($audit.mismatch_total -eq 0) "checked=$($audit.checked) mismatch=$($audit.mismatch_total)"
    Check 'audit EWS: ews_scores cocok band config/ews' ($audit.mismatch_scores -eq 0) "checked=$($audit.checked) mismatch=$($audit.mismatch_scores)"
    Check 'audit EWS: ews_risk cocok ambah eskalasi' ($audit.mismatch_risk -eq 0) "checked=$($audit.checked) mismatch=$($audit.mismatch_risk)"
    Check 'audit EWS: EwsScoringService::calculate() mereproduksi total+risk' ($audit.mismatch_service -eq 0) "checked=$($audit.checked) mismatch=$($audit.mismatch_service)"
    if ($audit.examples -and $audit.examples.Count) {
        foreach ($ex in $audit.examples) { Write-Host ("    contoh: " + $ex) }
    }
} catch {
    Check 'audit EWS berjalan' $false $_.Exception.Message
}

# ============================================================ 11. RINGKASAN

# ============================================================ 10. RESTORE

Section '10. RESTORE DATABASE'

# Uji tulis di atas sengaja mengubah data (buktinya: setiap jalur tulis benar-benar
# menyentuh database). Supaya skrip aman dijalankan berulang kali DAN aplikasi
# ditinggalkan dalam keadaan seed yang bersih, database dikembalikan ke kondisi
# canonical di akhir - apa pun hasil PASS/FAIL.
if ($NoReset) {
    Check 'restore seed (dilewati -NoReset)' $true 'diminta oleh flag -NoReset'
} else {
    $out = & $Php 'artisan' 'migrate:fresh' '--seed' '--force' 2>&1 | Out-String
    $ok = ($LASTEXITCODE -eq 0) -and ($out -notmatch 'FAIL|Exception|RuntimeException')
    $det = if ($ok) { 'canonical' } else { $out.Trim() }
    Check 'restore seed (migrate:fresh --seed --force)' $ok $det
    foreach ($t in $expected.Keys) {
        $got = Invoke-DbScalar "select count(*) as c from $t"
        Check "  row count $t kembali canonical" ($got -eq $expected[$t]) ("$got (expected $($expected[$t]))")
    }
}


Section '11. RINGKASAN'

$total = $script:Results.Count
$pass = @($script:Results | Where-Object { $_.Status -eq 'PASS' }).Count
$fail = $script:Failures

Write-Output ''
if ($fail -gt 0) {
    Write-Output 'CHECK YANG GAGAL:'
    $script:Results | Where-Object { $_.Status -eq 'FAIL' } | ForEach-Object {
        Write-Output ('  [{0}] {1}  ->  {2}' -f $_.Section, $_.Check, $_.Detail)
    }
    Write-Output ''
}
Write-Output ('TOTAL {0} check | PASS {1} | FAIL {2}' -f $total, $pass, $fail)

if ($StartedServer -and -not $KeepServer) {
    try { $StartedServer.Kill() } catch { }
    Write-Output 'Server yang dibuat skrip sudah dihentikan.'
}
try { Remove-Item -LiteralPath $Work -Recurse -Force -ErrorAction SilentlyContinue } catch { }

if ($fail -gt 0) { exit 1 }
Write-Output 'SMOKE TEST LULUS.'
exit 0
