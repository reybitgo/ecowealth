<#
.SYNOPSIS
    EcoWealth Phase 1 HTTP smoke test (P1-T1 ... P1-T11).

.DESCRIPTION
    Hits the running app through Apache (or `php -S`) and checks status
    codes, redirects, guards, menu visibility, asset base paths and
    maintenance mode. Two checks change data temporarily and restore it:
    a throw-away member account is deleted and `maintenance_mode` is put
    back to its previous value.

    Runs under Windows PowerShell 5.1 and PowerShell 7 (ASCII only).

.EXAMPLE
    powershell -NoProfile -File tests\smoke.ps1

.EXAMPLE
    pwsh tests/smoke.ps1 -BaseUrl 'http://localhost/ecowealth/system' -DbPass 'secret'
#>
param(
    [string] $BaseUrl   = 'http://localhost/ecowealth/system',
    [string] $MysqlPath = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe',
    [string] $DbName    = 'ecowealth_sneat',
    [string] $DbUser    = 'root',
    [string] $DbPass    = ''
)

$ErrorActionPreference = 'Stop'
$script:Failures = 0
$script:Passed   = 0
$script:TempDir  = Join-Path ([System.IO.Path]::GetTempPath()) ('ecowealth-smoke-' + [guid]::NewGuid().ToString('N'))

New-Item -ItemType Directory -Force -Path $script:TempDir | Out-Null

$base     = $BaseUrl.TrimEnd('/')
$basePath = ([uri]$base).AbsolutePath.TrimEnd('/')      # e.g. /ecowealth/system

$script:Jars = @{
    guest  = Join-Path $script:TempDir 'guest.jar'
    admin  = Join-Path $script:TempDir 'admin.jar'
    member = Join-Path $script:TempDir 'member.jar'
}

foreach ($jar in $script:Jars.Values) {
    New-Item -ItemType File -Force -Path $jar | Out-Null
}

function Write-Result {
    param([string] $Id, [bool] $Ok, [string] $Message)

    if ($Ok) {
        $script:Passed++
        Write-Host ("  {0} {1,-6} {2}" -f $Id, 'PASS', $Message) -ForegroundColor Green
    } else {
        $script:Failures++
        Write-Host ("  {0} {1,-6} {2}" -f $Id, 'FAIL', $Message) -ForegroundColor Red
    }
}

function Assert-That {
    param([string] $Id, [bool] $Condition, [string] $Message)
    Write-Result -Id $Id -Ok $Condition -Message $Message
}

# ---------------------------------------------------------------------------
#  HTTP helpers (curl.exe - no redirect following, one cookie jar per session)
# ---------------------------------------------------------------------------
function Invoke-App {
    param(
        [string]    $Method  = 'GET',
        [string]    $Path    = '/',
        [hashtable] $Form    = @{},
        [string]    $Session = 'guest',
        [string[]]  $Headers = @()
    )

    $id         = [guid]::NewGuid().ToString('N')
    $bodyFile   = Join-Path $script:TempDir "body-$id.html"
    $headerFile = Join-Path $script:TempDir "head-$id.txt"
    $jar        = $script:Jars[$Session]
    $url        = $base + $Path

    $curlArgs = @(
        '--silent', '--show-error',
        '--output', $bodyFile,
        '--dump-header', $headerFile,
        '--write-out', '%{http_code}',
        '--request', $Method,
        '--cookie', $jar,
        '--cookie-jar', $jar,
        '--max-redirs', '0'
    )

    foreach ($header in $Headers) {
        $curlArgs += @('--header', $header)
    }

    foreach ($key in $Form.Keys) {
        $curlArgs += @('--data-urlencode', "$key=$($Form[$key])")
    }

    $status = & curl.exe @curlArgs $url
    $code   = 0
    [void][int]::TryParse(("$status").Trim(), [ref] $code)

    $body = ''
    if (Test-Path $bodyFile) { $body = [string] (Get-Content $bodyFile -Raw -ErrorAction SilentlyContinue) }

    $responseHeaders = @{}
    if (Test-Path $headerFile) {
        foreach ($line in Get-Content $headerFile) {
            if ($line -match '^([A-Za-z0-9\-]+):\s*(.+)$') {
                $responseHeaders[$matches[1].ToLowerInvariant()] = $matches[2].Trim()
            }
        }
    }

    Remove-Item $bodyFile, $headerFile -Force -ErrorAction SilentlyContinue

    $location = ''
    if ($responseHeaders.ContainsKey('location')) { $location = [string] $responseHeaders['location'] }

    [pscustomobject]@{
        Status   = $code
        Body     = $body
        Headers  = $responseHeaders
        Location = $location
    }
}

function Get-Csrf {
    param([string] $Html)

    if ($Html -match 'name="_csrf"\s+value="([0-9a-f]{64})"') { return $matches[1] }
    if ($Html -match 'value="([0-9a-f]{64})"\s+name="_csrf"') { return $matches[1] }

    return ''
}

function Invoke-MySql {
    param([string] $Sql)

    $mysqlArgs = @('-u', $DbUser, '--batch', '--skip-column-names', '-D', $DbName, '-e', $Sql)
    if ($DbPass -ne '') { $mysqlArgs = @('-p' + $DbPass) + $mysqlArgs }

    return (& $MysqlPath @mysqlArgs) 2>&1
}

function Get-SettingValue {
    param([string] $Key)

    return (Invoke-MySql "SELECT value FROM settings WHERE key_name = '$Key';" | Out-String).Trim()
}

function Set-SettingValue {
    param([string] $Key, [string] $Value)

    Invoke-MySql "INSERT INTO settings (key_name, value) VALUES ('$Key', '$Value') ON DUPLICATE KEY UPDATE value = '$Value';" | Out-Null
}

function Sign-In {
    param([string] $Session, [string] $Username, [string] $Password)

    $form  = Invoke-App -Path '/login' -Session $Session
    $token = Get-Csrf $form.Body

    if ($token -eq '') { return $false }

    $result = Invoke-App -Method 'POST' -Path '/login' -Session $Session -Form @{
        '_csrf'          = $token
        'email-username' = $Username
        'password'       = $Password
    }

    return $result.Status -eq 302 -and $result.Location -match '/dashboard$'
}

# ---------------------------------------------------------------------------
#  Test run
# ---------------------------------------------------------------------------
Write-Host ''
Write-Host 'EcoWealth Phase 1 smoke test' -ForegroundColor Cyan
Write-Host "  app:      $base"
Write-Host "  database: $DbName"
Write-Host ''

$memberUsername    = 'smoke_' + (Get-Random -Minimum 100000 -Maximum 999999)
$memberPassword    = 'Smoke@12345'
$maintenanceBefore = '0'

try {
    # ---- Guest surface ---------------------------------------------------
    $homeGuest = Invoke-App -Path '/'
    Assert-That 'P1-T1' ($homeGuest.Status -eq 302 -and $homeGuest.Location -match '/login$') `
        "GET / as guest -> 302 login (got $($homeGuest.Status) $($homeGuest.Location))"

    $shopGuest = Invoke-App -Path '/shop'
    Assert-That 'P1-T1' ($shopGuest.Status -eq 302 -and $shopGuest.Location -match '/login$') `
        "GET /shop as guest -> 302 login (got $($shopGuest.Status))"

    $notFound = Invoke-App -Path '/no-such-page'
    Assert-That 'P1-T4' ($notFound.Status -eq 404 -and $notFound.Body -match 'Page not found') `
        "GET /no-such-page -> 404 page (got $($notFound.Status))"

    $asset = Invoke-App -Path '/assets/css/demo.css'
    Assert-That 'P1-T5' ($asset.Status -eq 200) "GET /assets/css/demo.css -> 200 (got $($asset.Status))"

    $loginPage = Invoke-App -Path '/login'
    Assert-That 'P1-T9' ($loginPage.Status -eq 200 -and (Get-Csrf $loginPage.Body) -ne '') `
        'GET /login -> 200 with a CSRF token'

    # ---- Admin session ---------------------------------------------------
    $signedIn = Sign-In -Session 'admin' -Username 'admin' -Password 'Admin@123'
    Assert-That 'P1-T9' $signedIn 'POST /login (admin) -> 302 /dashboard'

    $dashboard = Invoke-App -Path '/dashboard' -Session 'admin'
    Assert-That 'P1-T9' ($dashboard.Status -eq 200 -and $dashboard.Body -match 'admin') `
        "GET /dashboard as admin -> 200 (got $($dashboard.Status))"

    $homeAdmin = Invoke-App -Path '/' -Session 'admin'
    Assert-That 'P1-T10' ($homeAdmin.Status -eq 302 -and $homeAdmin.Location -match '/dashboard$') `
        "GET / as member -> 302 /dashboard (got $($homeAdmin.Status) $($homeAdmin.Location))"

    $adminPage = Invoke-App -Path '/admin' -Session 'admin'
    Assert-That 'P1-T3' ($adminPage.Status -eq 200 -and $adminPage.Body -match 'Back office' -and $adminPage.Body -match 'Registration codes') `
        "GET /admin as admin -> 200 with the back-office menu (got $($adminPage.Status))"

    $memberAsAdmin = Invoke-App -Path '/shop' -Session 'admin'
    Assert-That 'P1-T8' ($memberAsAdmin.Status -eq 200 -and $memberAsAdmin.Body -notmatch 'E-wallet monitor') `
        "member portal does not leak admin items (got $($memberAsAdmin.Status))"

    $passwordPage = Invoke-App -Path '/profile/password' -Session 'admin'
    Assert-That 'P1-T9' ($passwordPage.Status -eq 200 -and $passwordPage.Body -match 'Current password') `
        "GET /profile/password -> 200 (got $($passwordPage.Status))"

    $deep404     = Invoke-App -Path '/admin/products/9/edit' -Session 'admin'
    $assetPathOk = $deep404.Body -match [regex]::Escape("$basePath/assets/")
    $noWrongBase = $deep404.Body -notmatch [regex]::Escape("$basePath/admin/products/assets/")
    Assert-That 'P1-T5' ($deep404.Status -eq 404 -and $assetPathOk -and $noWrongBase) `
        'deep URL keeps asset URLs on the install prefix'

    # Every asset the admin page references must resolve (gate: no 404 asset).
    $origin       = ([uri]$base).GetLeftPart([System.UriPartial]::Authority)   # http://localhost
    $assetMatches = [regex]::Matches($adminPage.Body, '(?:href|src)="([^"]*assets/[^"]+)"')
    $assetUrls    = @($assetMatches | ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique)
    $brokenAssets = @()

    foreach ($assetUrl in $assetUrls) {
        $assetStatus = & curl.exe --silent --output NUL --write-out '%{http_code}' ($origin + $assetUrl)
        if ("$assetStatus".Trim() -ne '200') { $brokenAssets += "$assetUrl ($assetStatus)" }
    }

    Assert-That 'P1-T5' ($assetUrls.Count -gt 0 -and $brokenAssets.Count -eq 0) `
        ("all {0} asset URLs on /admin resolve ({1} broken)" -f $assetUrls.Count, $brokenAssets.Count)
    foreach ($broken in $brokenAssets) { Write-Host "        broken: $broken" -ForegroundColor DarkYellow }

    # ---- P1-T6: a bad CSRF token must not sign the admin out -------------
    $beforeLogout = Invoke-App -Path '/admin' -Session 'admin'
    $badLogout    = Invoke-App -Method 'POST' -Path '/logout' -Session 'admin' -Form @{ '_csrf' = ('0' * 64) }
    $afterLogout  = Invoke-App -Path '/admin' -Session 'admin'
    Assert-That 'P1-T6' ($badLogout.Status -eq 302 -and $beforeLogout.Status -eq 200 -and $afterLogout.Status -eq 200) `
        "POST /logout with a wrong CSRF token is rejected (logout=$($badLogout.Status), still signed in=$($afterLogout.Status))"

    # ---- Member guard ----------------------------------------------------
    $registerPage  = Invoke-App -Path '/register' -Session 'member'
    $registerToken = Get-Csrf $registerPage.Body
    $register = Invoke-App -Method 'POST' -Path '/register' -Session 'member' -Form @{
        '_csrf'            = $registerToken
        'username'         = $memberUsername
        'email'            = "$memberUsername@example.com"
        'password'         = $memberPassword
        'confirm-password' = $memberPassword
        'terms'            = '1'
    }
    Assert-That 'P1-T9' ($register.Status -eq 302 -and $register.Location -match '/login$') `
        "POST /register creates an account (got $($register.Status) $($register.Location))"

    $memberSignedIn = Sign-In -Session 'member' -Username $memberUsername -Password $memberPassword
    Assert-That 'P1-T9' $memberSignedIn 'the new member can sign in'

    $adminAsMember = Invoke-App -Path '/admin' -Session 'member'
    Assert-That 'P1-T2' ($adminAsMember.Status -eq 403 -and $adminAsMember.Body -match 'Access denied') `
        "GET /admin as member -> 403 (got $($adminAsMember.Status))"

    # ---- Central CSRF check on a routed POST -----------------------------
    $csrfForm = Invoke-App -Method 'POST' -Path '/profile/password' -Session 'member' -Form @{ '_csrf' = 'nope' }
    Assert-That 'P1-T6' ($csrfForm.Status -eq 302) `
        "routed POST with a bad CSRF token redirects back (got $($csrfForm.Status))"

    $csrfXhr = Invoke-App -Method 'POST' -Path '/profile/password' -Session 'member' -Form @{ '_csrf' = 'nope' } `
        -Headers @('X-Requested-With: XMLHttpRequest', 'Accept: application/json')
    Assert-That 'P1-T6' ($csrfXhr.Status -eq 422 -and $csrfXhr.Body -match '"code":"csrf"') `
        "XHR with a bad CSRF token -> 422 JSON (got $($csrfXhr.Status))"

    # ---- P1-T11: maintenance mode ---------------------------------------
    $maintenanceBefore = Get-SettingValue 'maintenance_mode'
    Set-SettingValue 'maintenance_mode' '1'

    $maintGuest = Invoke-App -Path '/shop'
    $retryAfter = ''
    if ($maintGuest.Headers.ContainsKey('retry-after')) { $retryAfter = [string] $maintGuest.Headers['retry-after'] }
    Assert-That 'P1-T11' ($maintGuest.Status -eq 503 -and $retryAfter -eq '3600' -and $maintGuest.Body -match 'maintenance') `
        "guest sees the 503 page with Retry-After: 3600 (got $($maintGuest.Status), retry=$retryAfter)"

    $maintAdmin = Invoke-App -Path '/admin' -Session 'admin'
    Assert-That 'P1-T11' ($maintAdmin.Status -eq 200) `
        "admin bypasses maintenance mode (got $($maintAdmin.Status))"

    Set-SettingValue 'maintenance_mode' $maintenanceBefore
} finally {
    # Always put the database back the way we found it.
    try { Set-SettingValue 'maintenance_mode' $maintenanceBefore } catch { }
    try { Invoke-MySql "DELETE FROM users WHERE username = '$memberUsername';" | Out-Null } catch { }
    Remove-Item $script:TempDir -Recurse -Force -ErrorAction SilentlyContinue
}

Write-Host ''
Write-Host ("Passed: {0}   Failed: {1}" -f $script:Passed, $script:Failures) -ForegroundColor Cyan
Write-Host ''

if ($script:Failures -eq 0) { exit 0 } else { exit 1 }
