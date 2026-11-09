<#
.SYNOPSIS
    EcoWealth HTTP smoke test (Phase 1-11: P1-T1 ... P1-T11, P2-T1 ... P2-T10,
    P3-T1 ... P3-T8, P4-T1 ... P4-T13, P5-T7 / P5-T9 / P5-T11,
    P6-T1 ... P6-T11, P7-T1 ... P7-T12, P8-T1 ... P8-T10,
    P9-T1 ... P9-T17, P10-T1 ... P10-T8, P11-T8 ... P11-T12).

.DESCRIPTION
    Hits the running app through Apache (or `php -S`) and checks status
    codes, redirects, guards, menu visibility, asset base paths,
    maintenance mode, the product catalogue, the package matrix, the cart,
    the e-wallet, checkout/orders, the manual payment loop, fulfilment
    (pack, ship, deliver, confirm, invoices), the auto-complete job, the
    Phase 9 membership flow (registration modes, registration codes,
    activation by proof with admin approval, member suspend/restore and
    genealogy) and the Phase 10/11 compensation path (real activations
    crediting the direct sponsor and the unilevel levels 2-10, an
    ineligible upline skipped, the member earnings statement with its
    level breakdown, the admin commission ledger, the two CSV exports
    and the finance report) end to end, including the expiry job from
    both the CLI and its web entry.
    Everything the script changes is restored in a finally block: a
    throw-away member account, products with SKU prefix SMOKE-,
    packages named "Smoke Package …", registration codes and member
    accounts it creates, the commissions it paid and the wallet balances
    they moved, `maintenance_mode`, the payment and fulfilment settings it
    edits, the smoke proof files and its orders.

    Wallet-funded activations (P9-T4 / P9-T9) are covered by the unit
    suite instead: they move real money between the payer and the primary
    admin, which an HTTP smoke run cannot reverse cleanly.

    Runs under Windows PowerShell 5.1 and PowerShell 7 (ASCII only).

.EXAMPLE
    powershell -NoProfile -File tests\smoke.ps1

.EXAMPLE
    pwsh tests/smoke.ps1 -BaseUrl 'http://localhost/ecowealth/system' -DbPass 'secret'
#>
param(
    [string] $BaseUrl   = 'http://localhost/ecowealth/system',
    [string] $MysqlPath = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe',
    [string] $PhpPath   = 'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe',
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
    other  = Join-Path $script:TempDir 'other.jar'
    p9a    = Join-Path $script:TempDir 'p9a.jar'
    p9b    = Join-Path $script:TempDir 'p9b.jar'
    p9c    = Join-Path $script:TempDir 'p9c.jar'
    p9d    = Join-Path $script:TempDir 'p9d.jar'
    p9e    = Join-Path $script:TempDir 'p9e.jar'
    p9f    = Join-Path $script:TempDir 'p9f.jar'
    p10a   = Join-Path $script:TempDir 'p10a.jar'
    p11reg = Join-Path $script:TempDir 'p11reg.jar'
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
        [string[]]  $Headers = @(),
        [hashtable] $Files   = @{}
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

    if ($Files.Count -gt 0) {
        # Multipart: fields and files travel as form parts.
        foreach ($key in $Form.Keys) {
            $value = $Form[$key]
            if ($value -is [System.Array]) {
                foreach ($item in $value) { $curlArgs += @('--form', "$key=$item") }
            } else {
                $curlArgs += @('--form', "$key=$value")
            }
        }
        foreach ($key in $Files.Keys) {
            $curlArgs += @('--form', "$key=@$($Files[$key])")
        }
    } else {
        foreach ($key in $Form.Keys) {
            $value = $Form[$key]
            if ($value -is [System.Array]) {
                # Repeated keys: content_product_id[]=1&content_product_id[]=2
                foreach ($item in $value) { $curlArgs += @('--data-urlencode', "$key=$item") }
            } else {
                $curlArgs += @('--data-urlencode', "$key=$value")
            }
        }
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
Write-Host 'EcoWealth HTTP smoke test (phases 1-12)' -ForegroundColor Cyan
Write-Host "  app:      $base"
Write-Host "  database: $DbName"
Write-Host ''

$memberUsername    = 'smoke_' + (Get-Random -Minimum 100000 -Maximum 999999)
$otherUsername     = 'smoke_other_' + (Get-Random -Minimum 100000 -Maximum 999999)
$memberPassword    = 'Smoke@12345'
$maintenanceBefore = '0'
$selfOrderId       = 0
$settingsSnapshot  = @{}

# Phase 9 fixtures created by this run (removed in the finally block).
$p9Users       = @()
$p9CodeIds     = @()
$p9CodeStart   = 0
$p9PackageAId  = 0
$p9JuanId      = 0
$p9PendingId   = 0

# Phase 10: commissions paid during this run go to demo members (juan), so
# the finally block removes the awards, their ledger rows and restores every
# pre-existing wallet to the balance it had before the run.
$p10CommissionStart = 0
$p10LedgerStart     = 0
$p10WalletSnapshot  = @{}

# Phase 12: the transfer this run writes, its ledger legs and its audit rows
# are deleted in the finally block, and every limit counter is restored (the
# reset cron zeroes them all during the run).
$p12LedgerStart     = 0
$p12TransferStart   = 0
$p12AuditStart      = 0
$p12CounterSnapshot = @{}
$p12Ran             = $false

try {
    $p10CommissionStart = [int]((Invoke-MySql "SELECT COALESCE(MAX(id), 0) FROM commissions;" | Out-String).Trim())
    $p10LedgerStart     = [int]((Invoke-MySql "SELECT COALESCE(MAX(id), 0) FROM ewallet_ledger;" | Out-String).Trim())

    foreach ($walletRow in @(Invoke-MySql "SELECT CONCAT(username, '|', ewallet_balance, '|', withdrawable_balance) FROM users;")) {
        $walletParts = ([string] $walletRow).Trim().Split('|')
        if ($walletParts.Count -eq 3) {
            $p10WalletSnapshot[$walletParts[0]] = "$($walletParts[1])|$($walletParts[2])"
        }
    }
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
    Assert-That 'P1-T3' ($adminPage.Status -eq 200 -and $adminPage.Body -match 'Back office' -and $adminPage.Body -match 'Products') `
        "GET /admin as admin -> 200 with the back-office menu (got $($adminPage.Status))"

    $memberAsAdmin = Invoke-App -Path '/shop' -Session 'admin'
    Assert-That 'P1-T8' ($memberAsAdmin.Status -eq 200 `
            -and $memberAsAdmin.Body -match 'Back office' `
            -and $memberAsAdmin.Body -match 'href="[^"]*/admin/products"' `
            -and $memberAsAdmin.Body -match 'href="[^"]*/admin/wallets"') `
        "admin /shop keeps the back-office menu (got $($memberAsAdmin.Status))"

    $passwordPage = Invoke-App -Path '/profile/password' -Session 'admin'
    Assert-That 'P1-T9' ($passwordPage.Status -eq 200 -and $passwordPage.Body -match 'Current password') `
        "GET /profile/password -> 200 (got $($passwordPage.Status))"

    $deep404     = Invoke-App -Path '/admin/products/987654/edit' -Session 'admin'
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
    # Phase 9: a guest registers and is signed in immediately, so the
    # register POST redirects to /dashboard (no second sign-in needed).
    $registerPage  = Invoke-App -Path '/register' -Session 'member'
    $registerToken = Get-Csrf $registerPage.Body
    $register = Invoke-App -Method 'POST' -Path '/register' -Session 'member' -Form @{
        '_csrf'            = $registerToken
        'mode'             = 'standard'
        'sponsor'          = 'juan'
        'username'         = $memberUsername
        'email'            = "$memberUsername@example.com"
        'password'         = $memberPassword
        'password_confirm' = $memberPassword
        'terms'            = '1'
    }
    Assert-That 'P1-T9' ($register.Status -eq 302 -and $register.Location -match '/dashboard$') `
        "POST /register creates an account and signs it in (got $($register.Status) $($register.Location))"

    $memberDashboard = Invoke-App -Path '/dashboard' -Session 'member'
    Assert-That 'P1-T9' ($memberDashboard.Status -eq 200 -and $memberDashboard.Body -match $memberUsername) `
        "the new member's session opens /dashboard (got $($memberDashboard.Status))"

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

    # ---- Phase 2: products & catalogue -----------------------------------
    $adminToken = Get-Csrf $adminPage.Body
    $smokeSku   = 'SMOKE-' + (Get-Random -Minimum 100000 -Maximum 999999)
    $smokeName  = 'Smoke Product ' + $smokeSku

    # P2-T1 — create through the admin form
    $create = Invoke-App -Method 'POST' -Path '/admin/products/save' -Session 'admin' -Form @{
        '_csrf'      = $adminToken
        'name'       = $smokeName
        'sku'        = $smokeSku
        'price'      = '123.45'
        'stock'      = '7'
        'sort_order' = '999'
        'status'     = 'active'
    }
    $smokeId = [int]((Invoke-MySql "SELECT id FROM products WHERE sku = '$smokeSku' LIMIT 1;" | Out-String).Trim())
    Assert-That 'P2-T1' ($create.Status -eq 302 -and $smokeId -gt 0) `
        "POST /admin/products/save creates a product (status=$($create.Status), id=$smokeId)"

    $adminList = Invoke-App -Path "/admin/products?q=$smokeSku" -Session 'admin'
    Assert-That 'P2-T1' ($adminList.Status -eq 200 -and $adminList.Body.Contains($smokeName)) `
        "the new product appears in the admin list (status=$($adminList.Status))"

    # P2-T2 — invalid input keeps the form and writes nothing
    $badCreate = Invoke-App -Method 'POST' -Path '/admin/products/save' -Session 'admin' -Form @{
        '_csrf'  = $adminToken
        'name'   = ''
        'sku'    = "$smokeSku-BAD"
        'price'  = '0'
        'stock'  = '-1'
        'status' = 'active'
    }
    $badForm = Invoke-App -Path '/admin/products/new' -Session 'admin'
    Assert-That 'P2-T2' ($badCreate.Status -eq 302 -and $badForm.Body -match 'Please fix the highlighted' -and $badForm.Body -match 'Product name is required') `
        'invalid input is rejected and the form shows field errors'

    $badRow = (Invoke-MySql "SELECT COUNT(*) FROM products WHERE sku = '$smokeSku-BAD';" | Out-String).Trim()
    Assert-That 'P2-T2' ($badRow -eq '0') 'no row is written for an invalid product'

    # P2-T3 — a script renamed .jpg, then a 6 MB file
    $badImage = Join-Path $script:TempDir 'bad.jpg'
    Set-Content -Path $badImage -Value '<?php echo "pwned";' -Encoding ASCII

    $badUpload = Invoke-App -Method 'POST' -Path '/admin/products/save' -Session 'admin' `
        -Files @{ 'image' = $badImage } `
        -Form @{
            '_csrf'      = $adminToken
            'name'       = "$smokeName Upload"
            'price'      = '10.00'
            'stock'      = '1'
            'sort_order' = '999'
            'status'     = 'active'
        }
    $uploadForm = Invoke-App -Path '/admin/products/new' -Session 'admin'
    Assert-That 'P2-T3' ($badUpload.Status -eq 302 -and $uploadForm.Body -match 'not allowed') `
        'a PHP file renamed .jpg is rejected'

    $bigImage = Join-Path $script:TempDir 'big.png'
    [System.IO.File]::WriteAllBytes($bigImage, (New-Object byte[] (6 * 1024 * 1024)))

    $bigUpload = Invoke-App -Method 'POST' -Path '/admin/products/save' -Session 'admin' `
        -Files @{ 'image' = $bigImage } `
        -Form @{
            '_csrf'      = $adminToken
            'name'       = "$smokeName Big"
            'price'      = '10.00'
            'stock'      = '1'
            'sort_order' = '999'
            'status'     = 'active'
        }
    $bigForm = Invoke-App -Path '/admin/products/new' -Session 'admin'
    Assert-That 'P2-T3' ($bigUpload.Status -eq 302 -and $bigForm.Body -match 'too large') `
        'a file over the 5 MB cap is rejected'

    # P2-T4 — edit the price and see it in the shop
    $edit = Invoke-App -Method 'POST' -Path '/admin/products/save' -Session 'admin' -Form @{
        '_csrf'      = $adminToken
        'product_id' = "$smokeId"
        'name'       = $smokeName
        'sku'        = $smokeSku
        'price'      = '99.99'
        'stock'      = '7'
        'sort_order' = '999'
        'status'     = 'active'
    }
    $editForm = Invoke-App -Path "/admin/products/$smokeId/edit" -Session 'admin'
    $detail   = Invoke-App -Path "/shop/$smokeId" -Session 'admin'
    Assert-That 'P2-T4' ($edit.Status -eq 302 -and $editForm.Body -match 'value="99.99"' -and $detail.Body -match '99.99') `
        'editing the price persists and the shop shows it'

    # P2-T9 — XSS in a product name is escaped in the storefront
    $xssCreate = Invoke-App -Method 'POST' -Path '/admin/products/save' -Session 'admin' -Form @{
        '_csrf'      = $adminToken
        'name'       = '<script>alert(1)</script> Smoke'
        'sku'        = "$smokeSku-XSS"
        'price'      = '1.00'
        'stock'      = '1'
        'sort_order' = '999'
        'status'     = 'active'
    }
    $xssShop = Invoke-App -Path "/shop?q=$smokeSku-XSS" -Session 'member'
    Assert-That 'P2-T9' ($xssCreate.Status -eq 302 `
            -and $xssShop.Body -notmatch [regex]::Escape('<script>alert(1)</script>') `
            -and $xssShop.Body -match '&lt;script&gt;alert\(1\)&lt;/script&gt;') `
        'product names are escaped in the storefront'

    # P2-T8 — pagination and search keep their query
    $pageRows = (1..12 | ForEach-Object {
        "('$smokeSku-P$($_)', 'Smoke Page $($_)', '$($smokeSku.ToLower())-p$($_)', 1.00, 5, 'active', 999)"
    }) -join ','
    Invoke-MySql "INSERT INTO products (sku, name, slug, price, stock, status, sort_order) VALUES $pageRows;" | Out-Null

    $page1  = Invoke-App -Path '/shop' -Session 'member'
    $page2  = Invoke-App -Path '/shop?page=2' -Session 'member'
    $search = Invoke-App -Path "/shop?q=$smokeSku" -Session 'member'
    $cards1 = [regex]::Matches($page1.Body, '<article class="card product-card').Count

    Assert-That 'P2-T8' ($page1.Status -eq 200 -and $cards1 -eq 12 -and $page2.Status -eq 200 `
            -and $page1.Body -match 'assets/css/shop.css' `
            -and $page1.Body -notmatch 'href="[^"]*/admin/products"' `
            -and $page2.Body -match 'Smoke Page' -and $search.Body -match 'page=2' -and $search.Body -match "q=$smokeSku") `
        "the catalogue paginates at 12 per page and search links keep q (page1 cards=$cards1)"

    # P2-T5 — deactivate: gone from the shop, direct URL 404
    $deactivate = Invoke-App -Method 'POST' -Path "/admin/products/$smokeId/status" -Session 'admin' `
        -Form @{ '_csrf' = $adminToken; 'status' = 'inactive' }
    $hidden = Invoke-App -Path "/shop?q=$smokeSku" -Session 'member'
    $gone   = Invoke-App -Path "/shop/$smokeId" -Session 'member'
    Assert-That 'P2-T5' ($deactivate.Status -eq 302 -and -not $hidden.Body.Contains($smokeName) -and $gone.Status -eq 404) `
        "a deactivated product leaves the shop and its URL 404s (status=$($gone.Status))"

    # P2-T10 — members cannot reach the admin product routes
    $memberAdmin = Invoke-App -Path '/admin/products' -Session 'member'
    $memberPost  = Invoke-App -Method 'POST' -Path '/admin/products/save' -Session 'member' -Form @{
        '_csrf' = $adminToken
        'name'  = 'Nope'
        'price' = '1.00'
        'stock' = '1'
        'status' = 'active'
    }
    Assert-That 'P2-T10' ($memberAdmin.Status -eq 403 -and $memberPost.Status -eq 403) `
        "a member cannot use admin product routes (GET=$($memberAdmin.Status), POST=$($memberPost.Status))"

    # ---- Phase 3: packages -----------------------------------------------
    $pkgName  = 'Smoke Package ' + $smokeSku
    $coffeeId = [int]((Invoke-MySql "SELECT id FROM products WHERE sku = 'EW-COFFEE' LIMIT 1;" | Out-String).Trim())

    # P3-T4 regression — a product bundled by a package cannot be deleted.
    $blockedDelete = Invoke-App -Method 'POST' -Path "/admin/products/$coffeeId/delete" -Session 'admin' `
        -Form @{ '_csrf' = $adminToken }
    $coffeeStill = (Invoke-MySql "SELECT COUNT(*) FROM products WHERE id = $coffeeId;" | Out-String).Trim()

    Assert-That 'P3-T4' ($blockedDelete.Status -eq 302 -and $coffeeStill -eq '1') `
        "a product used by a package cannot be deleted (status=$($blockedDelete.Status), rows=$coffeeStill)"

    $pkgFormFields = @{
        'name'                  = $pkgName
        'price'                 = '1234.00'
        'product_value'         = '1300.00'
        'direct_referral_bonus' = '150.00'
        'sort_order'            = '999'
        'status'                = 'active'
        'indirect_enabled'      = '1'
        'levels[2]'             = '100'
        'levels[3]'             = '50'
        'levels[4]'             = '40'
        'levels[5]'             = '30'
        'levels[6]'             = '20'
        'levels[7]'             = '10'
        'levels[8]'             = '5'
        'levels[9]'             = '5'
        'levels[10]'            = '5'
        'content_product_id[]'  = @($coffeeId)
        'content_quantity[]'    = @('2')
    }

    # P3-T1 — create with a full level matrix and contents
    $pkgCreate = Invoke-App -Method 'POST' -Path '/admin/packages/save' -Session 'admin' `
        -Form ($pkgFormFields + @{ '_csrf' = $adminToken })

    $pkgId      = [int]((Invoke-MySql "SELECT id FROM packages WHERE name = '$pkgName' LIMIT 1;" | Out-String).Trim())
    $levelCount = (Invoke-MySql "SELECT COUNT(*) FROM package_levels WHERE package_id = $pkgId AND level BETWEEN 2 AND 10;" | Out-String).Trim()
    $level1Rows = (Invoke-MySql "SELECT COUNT(*) FROM package_levels WHERE package_id = $pkgId AND level = 1;" | Out-String).Trim()
    $contentRow = (Invoke-MySql "SELECT CONCAT(product_id, ':', quantity) FROM package_products WHERE package_id = $pkgId;" | Out-String).Trim()

    Assert-That 'P3-T1' ($pkgCreate.Status -eq 302 -and $pkgId -gt 0 -and $levelCount -eq '9' -and $contentRow -eq "${coffeeId}:2") `
        "POST /admin/packages/save writes the package, 9 levels and its contents (status=$($pkgCreate.Status), id=$pkgId, levels=$levelCount)"

    $pkgList = Invoke-App -Path "/admin/packages?q=$smokeSku" -Session 'admin'
    Assert-That 'P3-T1' ($pkgList.Status -eq 200 -and $pkgList.Body.Contains($pkgName)) `
        'the new package appears in the admin list'

    # P3-T5 — level 1 is read-only and never stored as a level row
    $pkgDetail = Invoke-App -Path "/admin/packages/$pkgId/edit" -Session 'admin'
    Assert-That 'P3-T5' ($pkgDetail.Status -eq 200 `
            -and $pkgDetail.Body -match 'Level 1 [^<]{1,6}Direct referral' `
            -and $pkgDetail.Body -notmatch 'name="levels\[1\]"' `
            -and $level1Rows -eq '0') `
        'level 1 is read-only and not duplicated into package_levels'

    # P3-T4 — contents show on the public card
    $publicPackages = Invoke-App -Path '/packages' -Session 'member'
    Assert-That 'P3-T4' ($publicPackages.Status -eq 200 -and $publicPackages.Body.Contains($pkgName) -and $publicPackages.Body -match 'Coffee') `
        'the public page lists the package contents'

    # P3-T8 — prices and bonuses formatted
    Assert-That 'P3-T8' ($publicPackages.Body -match '4,498' -and $publicPackages.Body -match '6,998' `
            -and $publicPackages.Body -match 'Direct referral \(level 1\)') `
        'the packages page formats prices and shows the direct bonus'

    # P3-T2 — edit level 3
    $pkgEditFields = $pkgFormFields.Clone()
    $pkgEditFields['levels[3]'] = '50.50'
    $pkgEdit = Invoke-App -Method 'POST' -Path '/admin/packages/save' -Session 'admin' `
        -Form ($pkgEditFields + @{ '_csrf' = $adminToken; 'package_id' = "$pkgId" })

    $level3      = (Invoke-MySql "SELECT amount FROM package_levels WHERE package_id = $pkgId AND level = 3;" | Out-String).Trim()
    $pkgEditForm = Invoke-App -Path "/admin/packages/$pkgId/edit" -Session 'admin'

    Assert-That 'P3-T2' ($pkgEdit.Status -eq 302 -and $level3 -eq '50.50' -and $pkgEditForm.Body -match 'value="50.50"') `
        "editing level 3 persists and reopens in the form (stored=$level3)"

    # P3-T3 — invalid input is rejected without writing
    $badPackage = Invoke-App -Method 'POST' -Path '/admin/packages/save' -Session 'admin' -Form @{
        '_csrf'                 = $adminToken
        'name'                  = ''
        'price'                 = '0'
        'direct_referral_bonus' = '-5'
        'status'                = 'active'
        'levels[2]'             = '-1'
        'content_product_id[]'  = @($coffeeId)
        'content_quantity[]'    = @('1')
    }
    $badPackageForm = Invoke-App -Path '/admin/packages/new' -Session 'admin'
    $badPackageRows = (Invoke-MySql "SELECT COUNT(*) FROM packages WHERE name = '';" | Out-String).Trim()

    Assert-That 'P3-T3' ($badPackage.Status -eq 302 -and $badPackageForm.Body -match 'Please fix the highlighted' `
            -and $badPackageForm.Body -match 'Package name is required' -and $badPackageRows -eq '0') `
        'invalid package input is rejected and nothing is written'

    # P3-T6 — deactivate hides the package from members
    $pkgDeactivate = Invoke-App -Method 'POST' -Path "/admin/packages/$pkgId/status" -Session 'admin' `
        -Form @{ '_csrf' = $adminToken; 'status' = 'inactive' }
    $publicAfter = Invoke-App -Path '/packages' -Session 'member'

    Assert-That 'P3-T6' ($pkgDeactivate.Status -eq 302 -and -not $publicAfter.Body.Contains($pkgName)) `
        'a deactivated package disappears from the packages page'

    # P3-T7 — members cannot reach the admin package routes
    $memberPackages = Invoke-App -Path '/admin/packages' -Session 'member'
    $memberPackagePost = Invoke-App -Method 'POST' -Path '/admin/packages/save' -Session 'member' -Form @{
        '_csrf'                 = $adminToken
        'name'                  = 'Nope'
        'price'                 = '1.00'
        'direct_referral_bonus' = '0'
        'status'                = 'active'
    }

    Assert-That 'P3-T7' ($memberPackages.Status -eq 403 -and $memberPackagePost.Status -eq 403) `
        "a member cannot use admin package routes (GET=$($memberPackages.Status), POST=$($memberPackagePost.Status))"

    # ---- Phase 4: cart ---------------------------------------------------
    # A dedicated product keeps every cart check away from the seeded catalogue.
    $cartSku  = 'SMOKE-CART-' + (Get-Random -Minimum 100000 -Maximum 999999)
    $cartName = 'Smoke Cart ' + $cartSku

    Invoke-App -Method 'POST' -Path '/admin/products/save' -Session 'admin' -Form @{
        '_csrf'      = $adminToken
        'name'       = $cartName
        'sku'        = $cartSku
        'price'      = '20.00'
        'stock'      = '3'
        'sort_order' = '999'
        'status'     = 'active'
    } | Out-Null

    $cartProductId = [int]((Invoke-MySql "SELECT id FROM products WHERE sku = '$cartSku' LIMIT 1;" | Out-String).Trim())
    $cartMemberId  = [int]((Invoke-MySql "SELECT id FROM users WHERE username = '$memberUsername' LIMIT 1;" | Out-String).Trim())
    $xhrHeaders    = @('X-Requested-With: XMLHttpRequest', 'Accept: application/json')

    $cartGuest = Invoke-App -Path '/cart'
    Assert-That 'P4-T1' ($cartGuest.Status -eq 302 -and $cartGuest.Location -match '/login$') `
        "GET /cart as guest -> 302 login (got $($cartGuest.Status))"

    $cartEmpty       = Invoke-App -Path '/cart' -Session 'member'
    $memberCartToken = Get-Csrf $cartEmpty.Body
    Assert-That 'P4-T1' ($cartEmpty.Status -eq 200 -and $cartEmpty.Body -match 'Your cart is empty' -and $memberCartToken -ne '') `
        "GET /cart as a member -> 200 with the empty state (got $($cartEmpty.Status))"

    # P4-T12 - a plain form POST (no JavaScript) redirects, flashes and writes.
    $noJsAdd = Invoke-App -Method 'POST' -Path '/cart/add' -Session 'member' -Form @{
        '_csrf'      = $memberCartToken
        'product_id' = "$cartProductId"
        'quantity'   = '1'
    }
    $cartAfterNoJs = Invoke-App -Path '/cart' -Session 'member'
    $lineCount = (Invoke-MySql "SELECT COUNT(*) FROM cart_items ci JOIN carts c ON c.id = ci.cart_id WHERE c.user_id = $cartMemberId AND ci.product_id = $cartProductId;" | Out-String).Trim()

    Assert-That 'P4-T12' ($noJsAdd.Status -eq 302 -and $lineCount -eq '1' `
            -and $cartAfterNoJs.Body -match 'Added to cart' -and $cartAfterNoJs.Body.Contains($cartName)) `
        "a no-JS add redirects, flashes and writes one line (status=$($noJsAdd.Status), rows=$lineCount)"

    # P4-T13 - the drawer is on every member page and lists the saved line.
    $shopWithCart = Invoke-App -Path '/shop' -Session 'member'
    Assert-That 'P4-T13' ($shopWithCart.Status -eq 200 -and $shopWithCart.Body -match 'id="cartOffcanvas"' `
            -and $shopWithCart.Body -match 'data-cart-badge' -and $shopWithCart.Body.Contains($cartName) `
            -and $shopWithCart.Body -match 'data-cart-qty-form') `
        'the storefront carries the cart drawer with the saved line and badge'

    # P4-T11 - AJAX add answers ok + count + the refreshed drawer.
    $ajaxAdd = Invoke-App -Method 'POST' -Path '/cart/add' -Session 'member' -Headers $xhrHeaders -Form @{
        '_csrf'      = $memberCartToken
        'product_id' = "$cartProductId"
        'quantity'   = '2'
    }
    $ajaxPayload = $ajaxAdd.Body | ConvertFrom-Json
    Assert-That 'P4-T11' ($ajaxAdd.Status -eq 200 -and $ajaxPayload.ok -eq $true -and $ajaxPayload.count -eq 3 `
            -and $ajaxPayload.drawer_html.Contains($cartName) -and $ajaxPayload.subtotal -eq 60) `
        "an AJAX add returns ok, count and the drawer (count=$($ajaxPayload.count), subtotal=$($ajaxPayload.subtotal))"

    $countJson    = Invoke-App -Path '/cart/count' -Session 'member' -Headers $xhrHeaders
    $countPayload = $countJson.Body | ConvertFrom-Json
    Assert-That 'P4-T11' ($countJson.Status -eq 200 -and $countPayload.ok -eq $true -and $countPayload.count -eq 3) `
        "GET /cart/count returns the badge count (count=$($countPayload.count))"

    # P4-T3 - adding beyond available is refused with the exact numbers.
    $overAdd = Invoke-App -Method 'POST' -Path '/cart/add' -Session 'member' -Headers $xhrHeaders -Form @{
        '_csrf'      = $memberCartToken
        'product_id' = "$cartProductId"
        'quantity'   = '5'
    }
    $overPayload = $overAdd.Body | ConvertFrom-Json
    $cartQty = (Invoke-MySql "SELECT quantity FROM cart_items ci JOIN carts c ON c.id = ci.cart_id WHERE c.user_id = $cartMemberId AND ci.product_id = $cartProductId;" | Out-String).Trim()

    Assert-That 'P4-T3' ($overAdd.Status -eq 400 -and $overPayload.ok -eq $false `
            -and $overAdd.Body -match 'Requested 8, only 3 available' -and $cartQty -eq '3') `
        "adding beyond stock is refused and writes nothing (status=$($overAdd.Status), qty=$cartQty)"

    # P4-T10 - a deactivated product cannot be added.
    Invoke-App -Method 'POST' -Path "/admin/products/$cartProductId/status" -Session 'admin' `
        -Form @{ '_csrf' = $adminToken; 'status' = 'inactive' } | Out-Null
    $inactiveAdd = Invoke-App -Method 'POST' -Path '/cart/add' -Session 'member' -Headers $xhrHeaders -Form @{
        '_csrf'      = $memberCartToken
        'product_id' = "$cartProductId"
        'quantity'   = '1'
    }
    Invoke-App -Method 'POST' -Path "/admin/products/$cartProductId/status" -Session 'admin' `
        -Form @{ '_csrf' = $adminToken; 'status' = 'active' } | Out-Null

    Assert-That 'P4-T10' ($inactiveAdd.Status -eq 400 -and $inactiveAdd.Body -match 'no longer available') `
        "an inactive product cannot be added (status=$($inactiveAdd.Status))"

    # P4-T4 - update the quantity, then remove the line with quantity 0.
    $cartItemId = [int]((Invoke-MySql "SELECT ci.id FROM cart_items ci JOIN carts c ON c.id = ci.cart_id WHERE c.user_id = $cartMemberId AND ci.product_id = $cartProductId LIMIT 1;" | Out-String).Trim())
    $update = Invoke-App -Method 'POST' -Path '/cart/update' -Session 'member' -Headers $xhrHeaders -Form @{
        '_csrf'    = $memberCartToken
        'item_id'  = "$cartItemId"
        'quantity' = '2'
    }
    $updatePayload = $update.Body | ConvertFrom-Json
    $removed = Invoke-App -Method 'POST' -Path '/cart/update' -Session 'member' -Headers $xhrHeaders -Form @{
        '_csrf'    = $memberCartToken
        'item_id'  = "$cartItemId"
        'quantity' = '0'
    }
    $removedPayload = $removed.Body | ConvertFrom-Json
    $rowAfterRemove = (Invoke-MySql "SELECT COUNT(*) FROM cart_items WHERE id = $cartItemId;" | Out-String).Trim()

    Assert-That 'P4-T4' ($update.Status -eq 200 -and $updatePayload.count -eq 2 `
            -and $removed.Status -eq 200 -and $removedPayload.removed -eq $true -and $removedPayload.count -eq 0 `
            -and $rowAfterRemove -eq '0') `
        "update persists and quantity 0 removes the line (count=$($updatePayload.count), removed=$($removedPayload.removed))"

    # P4-T12 - the +/- steppers are submit buttons, so they work with JS off.
    # The stale quantity posted alongside 'direction' must be ignored.
    Invoke-App -Method 'POST' -Path '/cart/add' -Session 'member' -Form @{
        '_csrf'      = $memberCartToken
        'product_id' = "$cartProductId"
        'quantity'   = '1'
    } | Out-Null
    $stepItemId = [int]((Invoke-MySql "SELECT ci.id FROM cart_items ci JOIN carts c ON c.id = ci.cart_id WHERE c.user_id = $cartMemberId AND ci.product_id = $cartProductId LIMIT 1;" | Out-String).Trim())

    $stepUp = Invoke-App -Method 'POST' -Path '/cart/update' -Session 'member' -Form @{
        '_csrf'     = $memberCartToken
        'item_id'   = "$stepItemId"
        'direction' = 'up'
        'quantity'  = '99'
    }
    $stepQtyUp = (Invoke-MySql "SELECT quantity FROM cart_items WHERE id = $stepItemId;" | Out-String).Trim()

    $stepDown = Invoke-App -Method 'POST' -Path '/cart/update' -Session 'member' -Form @{
        '_csrf'     = $memberCartToken
        'item_id'   = "$stepItemId"
        'direction' = 'down'
    }
    $stepQtyDown = (Invoke-MySql "SELECT quantity FROM cart_items WHERE id = $stepItemId;" | Out-String).Trim()

    $stepRemove = Invoke-App -Method 'POST' -Path '/cart/update' -Session 'member' -Form @{
        '_csrf'     = $memberCartToken
        'item_id'   = "$stepItemId"
        'direction' = 'down'
    }
    $stepRows = (Invoke-MySql "SELECT COUNT(*) FROM cart_items WHERE id = $stepItemId;" | Out-String).Trim()

    Assert-That 'P4-T12' ($stepUp.Status -eq 302 -and $stepQtyUp -eq '2' `
            -and $stepDown.Status -eq 302 -and $stepQtyDown -eq '1' `
            -and $stepRemove.Status -eq 302 -and $stepRows -eq '0') `
        "the +/- steppers post a direction with JS off (up=$stepQtyUp, down=$stepQtyDown, removed rows=$stepRows)"

    # P4-T5 / P4-T6 - a second signed-in account cannot touch the line (IDOR).
    Invoke-App -Method 'POST' -Path '/cart/add' -Session 'member' -Headers $xhrHeaders -Form @{
        '_csrf'      = $memberCartToken
        'product_id' = "$cartProductId"
        'quantity'   = '2'
    } | Out-Null
    $foreignItemId = [int]((Invoke-MySql "SELECT ci.id FROM cart_items ci JOIN carts c ON c.id = ci.cart_id WHERE c.user_id = $cartMemberId AND ci.product_id = $cartProductId LIMIT 1;" | Out-String).Trim())

    $otherRegPage  = Invoke-App -Path '/register' -Session 'other'
    $otherRegToken = Get-Csrf $otherRegPage.Body
    Invoke-App -Method 'POST' -Path '/register' -Session 'other' -Form @{
        '_csrf'            = $otherRegToken
        'mode'             = 'standard'
        'sponsor'          = 'juan'
        'username'         = $otherUsername
        'email'            = "$otherUsername@example.com"
        'password'         = $memberPassword
        'password_confirm' = $memberPassword
        'terms'            = '1'
    } | Out-Null
    $otherSignedIn = (Invoke-App -Path '/dashboard' -Session 'other').Status -eq 200

    $otherCartPage  = Invoke-App -Path '/cart' -Session 'other'
    $otherCartToken = Get-Csrf $otherCartPage.Body
    Invoke-App -Method 'POST' -Path '/cart/add' -Session 'other' -Headers $xhrHeaders -Form @{
        '_csrf'      = $otherCartToken
        'product_id' = "$cartProductId"
        'quantity'   = '1'
    } | Out-Null

    $idorUpdate = Invoke-App -Method 'POST' -Path '/cart/update' -Session 'other' -Headers $xhrHeaders -Form @{
        '_csrf'    = $otherCartToken
        'item_id'  = "$foreignItemId"
        'quantity' = '9'
    }
    $idorRemove = Invoke-App -Method 'POST' -Path '/cart/remove' -Session 'other' -Headers $xhrHeaders -Form @{
        '_csrf'   = $otherCartToken
        'item_id' = "$foreignItemId"
    }
    $foreignQty = (Invoke-MySql "SELECT quantity FROM cart_items WHERE id = $foreignItemId;" | Out-String).Trim()

    Assert-That 'P4-T5' ($otherSignedIn -and $idorUpdate.Status -eq 400 `
            -and $idorUpdate.Body -match 'no longer in your cart' -and $foreignQty -eq '2') `
        "another member cannot rewrite the line (status=$($idorUpdate.Status), qty=$foreignQty)"
    Assert-That 'P4-T6' ($idorRemove.Status -eq 400 -and $foreignQty -eq '2' `
            -and (Invoke-MySql "SELECT COUNT(*) FROM cart_items WHERE id = $foreignItemId;" | Out-String).Trim() -eq '1') `
        "another member cannot remove the line (status=$($idorRemove.Status))"

    # P4-T7 - the cart follows a catalogue price change on the next reload.
    $cartBeforePrice = Invoke-App -Path '/cart' -Session 'member'
    Invoke-App -Method 'POST' -Path '/admin/products/save' -Session 'admin' -Form @{
        '_csrf'      = $adminToken
        'product_id' = "$cartProductId"
        'name'       = $cartName
        'sku'        = $cartSku
        'price'      = '12.50'
        'stock'      = '3'
        'sort_order' = '999'
        'status'     = 'active'
    } | Out-Null
    $cartAfterPrice = Invoke-App -Path '/cart' -Session 'member'

    Assert-That 'P4-T7' ($cartBeforePrice.Body -match '40\.00' `
            -and $cartAfterPrice.Body -match '25\.00' -and $cartAfterPrice.Body -match '12\.50') `
        'the cart follows the live catalogue price (2 x 20.00 becomes 2 x 12.50)'

    # P4-T13 - admins shop too, so their sidebar offers the same cart.
    $adminCart = Invoke-App -Path '/cart' -Session 'admin'
    $adminMenu = ($adminCart.Body -match '<div>Cart</div>') -and ($adminCart.Body -match 'menu-item active') `
        -and ($adminCart.Body -match 'admin/products')
    Assert-That 'P4-T13' ($adminCart.Status -eq 200 -and $adminMenu) `
        "GET /cart as admin keeps the back-office sidebar with an active Cart item (status=$($adminCart.Status))"

    # ---- Phase 5: e-wallet -----------------------------------------------
    $walletGuest = Invoke-App -Path '/wallet'
    Assert-That 'P5-T7' ($walletGuest.Status -eq 302 -and $walletGuest.Location -match '/login$') `
        "GET /wallet as guest -> 302 login (got $($walletGuest.Status))"

    $walletPage  = Invoke-App -Path '/wallet' -Session 'member'
    $walletToken = Get-Csrf $walletPage.Body
    Assert-That 'P5-T7' ($walletPage.Status -eq 200 -and $walletPage.Body -match 'E-wallet' `
            -and $walletPage.Body -match 'Total balance' -and $walletPage.Body -match 'Withdrawable' `
            -and $walletPage.Body -match 'No wallet movements yet' -and $walletToken -ne '') `
        "GET /wallet as a member -> 200 with both buckets and an empty statement (got $($walletPage.Status))"

    $walletMonitor = Invoke-App -Path '/admin/wallets' -Session 'admin'
    Assert-That 'P5-T7' ($walletMonitor.Status -eq 200 -and $walletMonitor.Body -match 'E-wallet monitor' `
            -and $walletMonitor.Body -match 'Total float' -and $walletMonitor.Body -match 'My e-wallet') `
        "GET /admin/wallets as admin -> 200 with the float and both menu items (got $($walletMonitor.Status))"

    $adminWalletMember = Invoke-App -Path '/admin/wallets' -Session 'member'
    $adminWalletMemberPost = Invoke-App -Method 'POST' -Path "/admin/wallets/$cartMemberId/credit" -Session 'member' `
        -Form @{ '_csrf' = $walletToken; 'amount' = '999.00'; 'note' = 'nope' }
    Assert-That 'P5-T7' ($adminWalletMember.Status -eq 403 -and $adminWalletMemberPost.Status -eq 403) `
        "a member cannot use the admin wallet routes (GET=$($adminWalletMember.Status), POST=$($adminWalletMemberPost.Status))"

    $topupPage = Invoke-App -Path "/admin/wallets/$cartMemberId" -Session 'admin'
    Assert-That 'P5-T7' ($topupPage.Status -eq 200 -and $topupPage.Body -match 'Credit this wallet' `
            -and $topupPage.Body -match 'Top-up history' -and (Get-Csrf $topupPage.Body) -ne '') `
        "GET /admin/wallets/$cartMemberId -> 200 with the top-up form (got $($topupPage.Status))"

    # P5-T7 - an admin top-up credits the non-withdrawable bucket and writes
    # the top-up row, the ledger row and the audit entry.
    $topup = Invoke-App -Method 'POST' -Path "/admin/wallets/$cartMemberId/credit" -Session 'admin' -Form @{
        '_csrf'  = $adminToken
        'amount' = '500.25'
        'note'   = 'Smoke top-up'
    }
    $walletBuckets = (Invoke-MySql "SELECT CONCAT(ewallet_balance, '|', withdrawable_balance) FROM users WHERE id = $cartMemberId;" | Out-String).Trim()
    $topupRows     = (Invoke-MySql "SELECT COUNT(*) FROM ewallet_admin_topups WHERE recipient_id = $cartMemberId;" | Out-String).Trim()
    $ledgerRows    = (Invoke-MySql "SELECT COUNT(*) FROM ewallet_ledger WHERE user_id = $cartMemberId AND ref_type = 'topup';" | Out-String).Trim()
    $auditRows     = (Invoke-MySql "SELECT COUNT(*) FROM audit_log WHERE action = 'wallet.topup' AND subject_id = $cartMemberId;" | Out-String).Trim()
    Assert-That 'P5-T7' ($topup.Status -eq 302 -and $walletBuckets -eq '500.25|0.00' -and $topupRows -eq '1' `
            -and $ledgerRows -eq '1' -and $auditRows -eq '1') `
        "POST /admin/wallets/{id}/credit credits 500.25 non-withdrawable and records top-up + ledger + audit (status=$($topup.Status), buckets=$walletBuckets, topups=$topupRows, ledger=$ledgerRows, audit=$auditRows)"

    $walletAfter = Invoke-App -Path '/wallet' -Session 'member'
    Assert-That 'P5-T7' ($walletAfter.Status -eq 200 -and $walletAfter.Body.Contains('500.25') `
            -and $walletAfter.Body -match 'Admin top-up' -and $walletAfter.Body.Contains('Smoke top-up')) `
        "the member sees the top-up on /wallet (status=$($walletAfter.Status))"

    # P5-T9 - the statement filters narrow the ledger.
    $creditOnly = Invoke-App -Path '/wallet?type=credit&ref_type=topup' -Session 'member'
    $debitOnly  = Invoke-App -Path '/wallet?type=debit' -Session 'member'
    Assert-That 'P5-T9' ($creditOnly.Status -eq 200 -and $creditOnly.Body.Contains('Smoke top-up') `
            -and $debitOnly.Status -eq 200 -and -not $debitOnly.Body.Contains('Smoke top-up')) `
        "the statement filters narrow the ledger (credit=$($creditOnly.Status), debit=$($debitOnly.Status))"

    $topupTab  = Invoke-App -Path '/admin/wallets?tab=topups' -Session 'admin'
    $ledgerTab = Invoke-App -Path '/admin/wallets?tab=ledger' -Session 'admin'
    Assert-That 'P5-T9' ($topupTab.Status -eq 200 -and $topupTab.Body.Contains('Smoke top-up') `
            -and $ledgerTab.Status -eq 200 -and $ledgerTab.Body -match 'All wallet movements' `
            -and $ledgerTab.Body.Contains($memberUsername)) `
        "the monitor tabs list the top-up and every movement (topups=$($topupTab.Status), ledger=$($ledgerTab.Status))"

    # P5-T7 - an invalid amount is refused and writes nothing.
    $topupRowsBefore = (Invoke-MySql "SELECT COUNT(*) FROM ewallet_admin_topups WHERE recipient_id = $cartMemberId;" | Out-String).Trim()
    $badTopup = Invoke-App -Method 'POST' -Path "/admin/wallets/$cartMemberId/credit" -Session 'admin' -Form @{
        '_csrf'  = $adminToken
        'amount' = '0'
        'note'   = 'Nope'
    }
    $badTopupPage   = Invoke-App -Path "/admin/wallets/$cartMemberId" -Session 'admin'
    $topupRowsAfter = (Invoke-MySql "SELECT COUNT(*) FROM ewallet_admin_topups WHERE recipient_id = $cartMemberId;" | Out-String).Trim()
    Assert-That 'P5-T7' ($badTopup.Status -eq 302 -and $topupRowsBefore -eq $topupRowsAfter `
            -and $badTopupPage.Body -match 'greater than 0' -and $badTopupPage.Body -match 'is-invalid') `
        "an invalid top-up is refused and writes nothing (status=$($badTopup.Status), rows=$topupRowsAfter)"

    # P5-T11 - the reconciliation command agrees with the ledger.
    $reconcileOutput = & $PhpPath (Join-Path (Split-Path -Parent $PSScriptRoot) 'bin\wallet_reconcile.php') --json 2>&1
    $reconcileExit   = $LASTEXITCODE
    $reconcileJson   = ($reconcileOutput -join "`n") | ConvertFrom-Json
    Assert-That 'P5-T11' ($reconcileExit -eq 0 -and $reconcileJson.ok -eq $true) `
        "bin/wallet_reconcile.php reports a clean ledger (exit=$reconcileExit, accounts=$($reconcileJson.checked))"

    # Admins may open their own statement inside the back-office layout.
    $adminWallet = Invoke-App -Path '/wallet' -Session 'admin'
    Assert-That 'P5-T7' ($adminWallet.Status -eq 200 -and $adminWallet.Body -match 'My e-wallet' `
            -and $adminWallet.Body -match 'E-wallet monitor') `
        "GET /wallet as admin -> 200 in the back-office layout (got $($adminWallet.Status))"

    # ---- Phase 6: checkout & orders ---------------------------------------
    $orderSku  = 'SMOKE-ORDER-' + (Get-Random -Minimum 100000 -Maximum 999999)
    $orderName = 'Smoke Order Product ' + $orderSku

    Invoke-App -Method 'POST' -Path '/admin/products/save' -Session 'admin' -Form @{
        '_csrf'      = $adminToken
        'name'       = $orderName
        'sku'        = $orderSku
        'price'      = '40.00'
        'stock'      = '10'
        'sort_order' = '999'
        'status'     = 'active'
    } | Out-Null

    $orderProductId = [int]((Invoke-MySql "SELECT id FROM products WHERE sku = '$orderSku' LIMIT 1;" | Out-String).Trim())

    # Start from an empty cart so the order totals are deterministic.
    Invoke-MySql "DELETE ci FROM cart_items ci JOIN carts c ON c.id = ci.cart_id WHERE c.user_id = $cartMemberId;" | Out-Null

    $checkoutGuest = Invoke-App -Path '/checkout'
    Assert-That 'P6-T1' ($checkoutGuest.Status -eq 302 -and $checkoutGuest.Location -match '/login$') `
        "GET /checkout as guest -> 302 login (got $($checkoutGuest.Status))"

    $emptyCheckout = Invoke-App -Path '/checkout' -Session 'member'
    Assert-That 'P6-T1' ($emptyCheckout.Status -eq 302 -and $emptyCheckout.Location -match '/shop$') `
        "GET /checkout with an empty cart -> 302 shop (got $($emptyCheckout.Status))"

    # Fund the member's e-wallet through the audited admin route.
    Invoke-App -Method 'POST' -Path "/admin/wallets/$cartMemberId/credit" -Session 'admin' `
        -Form @{ '_csrf' = $adminToken; 'amount' = '1000.00'; 'note' = 'Smoke order funds' } | Out-Null
    $walletBeforeOrder = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE id = $cartMemberId;" | Out-String).Trim())

    Invoke-App -Method 'POST' -Path '/cart/add' -Session 'member' `
        -Form @{ '_csrf' = $memberCartToken; 'product_id' = "$orderProductId"; 'quantity' = '2' } | Out-Null

    $checkoutPage  = Invoke-App -Path '/checkout' -Session 'member'
    $checkoutToken = Get-Csrf $checkoutPage.Body
    $idem          = ''
    if ($checkoutPage.Body -match 'name="idempotency_key"\s+value="([0-9a-f]{32})"') { $idem = $matches[1] }

    Assert-That 'P6-T1' ($checkoutPage.Status -eq 200 -and $checkoutPage.Body -match 'Checkout' `
            -and $checkoutPage.Body -match 'E-wallet' -and $checkoutPage.Body -match 'Place order' `
            -and $checkoutPage.Body -match 'name="terms"' -and $idem -ne '') `
        "GET /checkout -> 200 with items, payment, terms and an idempotency key (status=$($checkoutPage.Status), key=$idem)"

    $checkoutForm = @{
        '_csrf'             = $checkoutToken
        'billing_name'      = 'Smoke Buyer'
        'billing_phone'     = '0917 000 0000'
        'shipping_name'     = 'Smoke Buyer'
        'shipping_phone'    = '0917 000 0000'
        'shipping_address'  = '12 Smoke Street'
        'shipping_barangay' = 'Poblacion'
        'shipping_city'     = 'Makati'
        'shipping_province' = 'Metro Manila'
        'shipping_postal'   = '1200'
        'notes_member'      = 'Smoke checkout'
        'payment_method'    = 'ewallet'
        'terms'             = '1'
        'idempotency_key'   = $idem
        'save_address'      = '1'
    }

    $placed  = Invoke-App -Method 'POST' -Path '/checkout' -Session 'member' -Form $checkoutForm
    $orderId = 0
    if ($placed.Location -match '/orders/(\d+)$') { $orderId = [int]$matches[1] }

    $orderStatus = (Invoke-MySql "SELECT status FROM shop_orders WHERE id = $orderId;" | Out-String).Trim()
    $orderTotal  = (Invoke-MySql "SELECT total FROM shop_orders WHERE id = $orderId;" | Out-String).Trim()
    $orderNo     = (Invoke-MySql "SELECT order_no FROM shop_orders WHERE id = $orderId;" | Out-String).Trim()
    $orderNoOk   = $orderNo -match '^EW\d{6}\d{5,}$'
    $orderLine   = (Invoke-MySql "SELECT CONCAT(quantity, '|', reservation_state) FROM shop_order_items WHERE order_id = $orderId;" | Out-String).Trim()
    $walletAfter = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE id = $cartMemberId;" | Out-String).Trim())
    $orderLedger = (Invoke-MySql "SELECT COUNT(*) FROM ewallet_ledger WHERE user_id = $cartMemberId AND ref_type = 'shop_order';" | Out-String).Trim()
    $orderEvents = (Invoke-MySql "SELECT COUNT(*) FROM shop_order_events WHERE order_id = $orderId;" | Out-String).Trim()

    Assert-That 'P6-T1' ($placed.Status -eq 302 -and $orderId -gt 0 -and $orderStatus -eq 'paid' `
            -and $orderTotal -eq '80.00' -and $orderNoOk -and $orderLine -eq '2|committed' `
            -and [math]::Abs($walletAfter - ($walletBeforeOrder - 80)) -lt 0.001 `
            -and $orderLedger -eq '1' -and $orderEvents -eq '2') `
        "POST /checkout (e-wallet) -> paid order, wallet debited once, stock held (id=$orderId, no=$orderNo, total=$orderTotal, wallet=$walletAfter, ledger=$orderLedger, events=$orderEvents)"

    # P6-T2 - the same idempotency key returns the same order.
    $repeatForm = $checkoutForm.Clone()
    $repeatForm.Remove('save_address')
    $repeated = Invoke-App -Method 'POST' -Path '/checkout' -Session 'member' -Form $repeatForm
    $orderCount = (Invoke-MySql "SELECT COUNT(*) FROM shop_orders WHERE user_id = $cartMemberId;" | Out-String).Trim()
    $walletRepeat = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE id = $cartMemberId;" | Out-String).Trim())
    Assert-That 'P6-T2' ($repeated.Status -eq 302 -and $repeated.Location -match "/orders/$orderId$" `
            -and $orderCount -eq '1' -and [math]::Abs($walletRepeat - $walletAfter) -lt 0.001) `
        "a replayed checkout returns the same order without charging twice (orders=$orderCount, wallet=$walletRepeat)"

    $ordersPage     = Invoke-App -Path '/orders' -Session 'member'
    $ordersFiltered = Invoke-App -Path '/orders?status=paid' -Session 'member'
    Assert-That 'P6-T9' ($ordersPage.Status -eq 200 -and $ordersPage.Body.Contains($orderNo) `
            -and $ordersFiltered.Status -eq 200 -and $ordersFiltered.Body.Contains($orderNo)) `
        "GET /orders lists the order and filters by status (all=$($ordersPage.Status), paid=$($ordersFiltered.Status))"

    $orderPage = Invoke-App -Path "/orders/$orderId" -Session 'member'
    Assert-That 'P6-T1' ($orderPage.Status -eq 200 -and $orderPage.Body.Contains($orderNo) `
            -and $orderPage.Body -match 'Payment received' -and $orderPage.Body.Contains($orderName)) `
        "GET /orders/$orderId -> 200 with the paid order (got $($orderPage.Status))"

    $foreignOrder = Invoke-App -Path "/orders/$orderId" -Session 'other'
    Assert-That 'P6-T8' ($foreignOrder.Status -eq 404) `
        "another member opening the order gets 404 (got $($foreignOrder.Status))"

    $adminOrders = Invoke-App -Path '/admin/orders' -Session 'admin'
    $adminOrder  = Invoke-App -Path "/admin/orders/$orderId" -Session 'admin'
    Assert-That 'P6-T10' ($adminOrders.Status -eq 200 -and $adminOrders.Body.Contains($orderNo) `
            -and $adminOrders.Body -match 'Paid revenue' `
            -and $adminOrder.Status -eq 200 -and $adminOrder.Body.Contains($memberUsername)) `
        "the admin desk lists and opens every order (list=$($adminOrders.Status), detail=$($adminOrder.Status))"

    $memberAdminOrders = Invoke-App -Path '/admin/orders' -Session 'member'
    Assert-That 'P6-T10' ($memberAdminOrders.Status -eq 403) `
        "a member cannot open /admin/orders (got $($memberAdminOrders.Status))"

    # P6-T11 - a manual order can be cancelled before payment.
    Invoke-App -Method 'POST' -Path '/cart/add' -Session 'member' `
        -Form @{ '_csrf' = $memberCartToken; 'product_id' = "$orderProductId"; 'quantity' = '1' } | Out-Null

    $manualPage  = Invoke-App -Path '/checkout' -Session 'member'
    $manualToken = Get-Csrf $manualPage.Body
    $manualIdem  = ''
    if ($manualPage.Body -match 'name="idempotency_key"\s+value="([0-9a-f]{32})"') { $manualIdem = $matches[1] }

    $manualForm = $checkoutForm.Clone()
    $manualForm['_csrf']           = $manualToken
    $manualForm['payment_method']  = 'gcash'
    $manualForm['idempotency_key'] = $manualIdem
    $manualForm.Remove('save_address')

    $manualPlaced = Invoke-App -Method 'POST' -Path '/checkout' -Session 'member' -Form $manualForm
    $manualId = 0
    if ($manualPlaced.Location -match '/orders/(\d+)$') { $manualId = [int]$matches[1] }

    $manualStatus   = (Invoke-MySql "SELECT status FROM shop_orders WHERE id = $manualId;" | Out-String).Trim()
    $manualDeadline = (Invoke-MySql "SELECT payment_deadline FROM shop_orders WHERE id = $manualId;" | Out-String).Trim()
    $manualWallet   = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE id = $cartMemberId;" | Out-String).Trim())
    $manualLedger   = (Invoke-MySql "SELECT COUNT(*) FROM ewallet_ledger WHERE user_id = $cartMemberId AND ref_type = 'shop_order';" | Out-String).Trim()

    $manualPageView = Invoke-App -Path "/orders/$manualId" -Session 'member'
    $orderPageToken = Get-Csrf $manualPageView.Body

    Assert-That 'P6-T1' ($manualPlaced.Status -eq 302 -and $manualId -gt 0 -and $manualStatus -eq 'pending' `
            -and $manualDeadline -ne '' -and $manualDeadline -ne 'NULL' `
            -and [math]::Abs($manualWallet - $walletAfter) -lt 0.001 -and $manualLedger -eq '1' `
            -and $manualPageView.Body -match 'Payment pending' -and $manualPageView.Body -match 'GCash' `
            -and $manualPageView.Body -match 'Cancel order') `
        "POST /checkout (GCash) -> pending order with a deadline, wallet untouched (id=$manualId, status=$manualStatus, wallet=$manualWallet)"

    $cancelled = Invoke-App -Method 'POST' -Path "/orders/$manualId/cancel" -Session 'member' `
        -Form @{ '_csrf' = $orderPageToken; 'reason' = 'Smoke cancel' }
    $cancelStatus = (Invoke-MySql "SELECT status FROM shop_orders WHERE id = $manualId;" | Out-String).Trim()
    $cancelLine   = (Invoke-MySql "SELECT reservation_state FROM shop_order_items WHERE order_id = $manualId;" | Out-String).Trim()
    $cancelPage   = Invoke-App -Path "/orders/$manualId" -Session 'member'

    Assert-That 'P6-T11' ($cancelled.Status -eq 302 -and $cancelStatus -eq 'cancelled' -and $cancelLine -eq 'released' `
            -and $cancelPage.Body -match 'Order cancelled' -and $cancelPage.Body -match 'Smoke cancel') `
        "POST /orders/{id}/cancel releases the reservation and cancels the order (status=$cancelStatus, line=$cancelLine)"

    # P6-T7 - the checkout address was saved and the address book works.
    $profilePage = Invoke-App -Path '/profile' -Session 'member'
    Assert-That 'P6-T7' ($profilePage.Status -eq 200 -and $profilePage.Body.Contains('12 Smoke Street') `
            -and $profilePage.Body.Contains('Smoke Buyer')) `
        "GET /profile shows the address saved during checkout (got $($profilePage.Status))"

    Invoke-App -Method 'POST' -Path '/profile/address' -Session 'member' -Form @{
        '_csrf'          = (Get-Csrf $profilePage.Body)
        'recipient_name' = 'Smoke Office'
        'address_line'   = '99 Office Road'
        'city'           = 'Pasig'
        'is_default'     = '1'
    } | Out-Null
    $profileAfter = Invoke-App -Path '/profile' -Session 'member'

    # Checkout needs a non-empty cart; put one unit back before reading it.
    Invoke-App -Method 'POST' -Path '/cart/add' -Session 'member' `
        -Form @{ '_csrf' = $memberCartToken; 'product_id' = "$orderProductId"; 'quantity' = '1' } | Out-Null
    $checkoutAddress = Invoke-App -Path '/checkout' -Session 'member'
    Assert-That 'P6-T7' ($profileAfter.Body.Contains('99 Office Road') `
            -and $checkoutAddress.Status -eq 200 -and $checkoutAddress.Body.Contains('Smoke Office')) `
        "the address book saves a new default and checkout pre-fills it (profile contains the address)"

    # P6-T7 - payout accounts are member-editable from the profile.
    $trc20 = 'T' + ('1' * 33)
    $bep20 = '0x' + ('a' * 40)

    $payoutSave = Invoke-App -Method 'POST' -Path '/profile/payment' -Session 'member' -Form @{
        '_csrf'              = (Get-Csrf $profileAfter.Body)
        'gcash_number'       = '0917 555 1234'
        'maya_number'        = '+63 917 555 9876'
        'usdt_trc20_address' = $trc20
        'usdt_bep20_address' = $bep20
    }
    $profilePayments = Invoke-App -Path '/profile' -Session 'member'
    $payoutRow = (Invoke-MySql "SELECT CONCAT(COALESCE(gcash_number,''), '|', COALESCE(maya_number,''), '|', COALESCE(usdt_trc20_address,'')) FROM users WHERE id = $cartMemberId;" | Out-String).Trim()

    Assert-That 'P6-T7' ($payoutSave.Status -eq 302 -and $payoutRow -eq "09175551234|+639175559876|$trc20" `
            -and $profilePayments.Body -match 'Payout accounts' -and $profilePayments.Body.Contains('09175551234')) `
        "POST /profile/payment saves the payout accounts (status=$($payoutSave.Status), stored=$payoutRow)"

    $payoutBeforeBad = (Invoke-MySql "SELECT COALESCE(gcash_number,'') FROM users WHERE id = $cartMemberId;" | Out-String).Trim()
    $badPayout = Invoke-App -Method 'POST' -Path '/profile/payment' -Session 'member' -Form @{
        '_csrf'              = (Get-Csrf $profilePayments.Body)
        'gcash_number'       = 'nope'
        'maya_number'        = ''
        'usdt_trc20_address' = ''
        'usdt_bep20_address' = ''
    }
    $badPayoutPage = Invoke-App -Path '/profile' -Session 'member'
    $payoutAfterBad = (Invoke-MySql "SELECT COALESCE(gcash_number,'') FROM users WHERE id = $cartMemberId;" | Out-String).Trim()

    Assert-That 'P6-T7' ($badPayout.Status -eq 302 -and $payoutBeforeBad -eq $payoutAfterBad `
            -and $badPayoutPage.Body -match 'Enter a GCash number' -and $badPayoutPage.Body.Contains('value="nope"')) `
        "an invalid payout number is refused and keeps the typed value (stored=$payoutAfterBad)"

    # P6-T6 - missing terms is refused and the typed input is kept.
    Invoke-App -Method 'POST' -Path '/cart/add' -Session 'member' `
        -Form @{ '_csrf' = $memberCartToken; 'product_id' = "$orderProductId"; 'quantity' = '1' } | Out-Null
    $termsPage  = Invoke-App -Path '/checkout' -Session 'member'
    $termsToken = Get-Csrf $termsPage.Body
    $termsIdem  = ''
    if ($termsPage.Body -match 'name="idempotency_key"\s+value="([0-9a-f]{32})"') { $termsIdem = $matches[1] }

    $badTermsForm = @{
        '_csrf'            = $termsToken
        'billing_name'     = 'No Terms Buyer'
        'shipping_name'    = 'No Terms Buyer'
        'shipping_address' = '12 Smoke Street'
        'payment_method'   = 'gcash'
        'idempotency_key'  = $termsIdem
    }
    $ordersBeforeBadTerms = (Invoke-MySql "SELECT COUNT(*) FROM shop_orders WHERE user_id = $cartMemberId;" | Out-String).Trim()
    $badTerms = Invoke-App -Method 'POST' -Path '/checkout' -Session 'member' -Form $badTermsForm
    $ordersAfterBadTerms = (Invoke-MySql "SELECT COUNT(*) FROM shop_orders WHERE user_id = $cartMemberId;" | Out-String).Trim()
    $termsErrorPage = Invoke-App -Path '/checkout' -Session 'member'

    Assert-That 'P6-T6' ($badTerms.Status -eq 302 -and $ordersBeforeBadTerms -eq $ordersAfterBadTerms `
            -and $termsErrorPage.Body -match 'Please accept the terms' `
            -and $termsErrorPage.Body.Contains('No Terms Buyer')) `
        "a checkout without terms is refused, writes no order and keeps the input (orders=$ordersAfterBadTerms)"

    # ---- Phase 7: manual payments and the order lifecycle ------------------
    $proofPng = Join-Path $script:TempDir 'proof.png'
    [System.IO.File]::WriteAllBytes($proofPng, [Convert]::FromBase64String(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='))

    # A new manual order starts in pending with an open proof window.
    Invoke-MySql "DELETE ci FROM cart_items ci JOIN carts c ON c.id = ci.cart_id WHERE c.user_id = $cartMemberId;" | Out-Null
    Invoke-App -Method 'POST' -Path '/cart/add' -Session 'member' `
        -Form @{ '_csrf' = $memberCartToken; 'product_id' = "$orderProductId"; 'quantity' = '1' } | Out-Null

    $payPage  = Invoke-App -Path '/checkout' -Session 'member'
    $payToken = Get-Csrf $payPage.Body
    $payIdem  = ''
    if ($payPage.Body -match 'name="idempotency_key"\s+value="([0-9a-f]{32})"') { $payIdem = $matches[1] }

    $payForm = $checkoutForm.Clone()
    $payForm['_csrf']           = $payToken
    $payForm['payment_method']  = 'gcash'
    $payForm['idempotency_key'] = $payIdem
    $payForm.Remove('save_address')

    $payPlaced = Invoke-App -Method 'POST' -Path '/checkout' -Session 'member' -Form $payForm
    $payOrderId = 0
    if ($payPlaced.Location -match '/orders/(\d+)$') { $payOrderId = [int]$matches[1] }

    $payOrderPage = Invoke-App -Path "/orders/$payOrderId" -Session 'member'
    $payOrderToken = Get-Csrf $payOrderPage.Body

    Assert-That 'P7-T1' ($payOrderId -gt 0 -and $payOrderPage.Status -eq 200 `
            -and $payOrderPage.Body -match 'Upload your payment proof' `
            -and $payOrderPage.Body -match 'name="proof_image"' `
            -and $payOrderPage.Body -match 'enctype="multipart/form-data"') `
        "GET /orders/$payOrderId -> the proof panel is offered (id=$payOrderId)"

    # P7-T2 - a script renamed .png is refused while the window is open.
    $badProof = Join-Path $script:TempDir 'bad-proof.png'
    Set-Content -Path $badProof -Value '<?php echo "pwned";' -Encoding ASCII

    $badProofPost = Invoke-App -Method 'POST' -Path "/orders/$payOrderId/proof" -Session 'member' `
        -Files @{ 'proof_image' = $badProof } `
        -Form @{
            '_csrf'        = $payOrderToken
            'amount_sent'  = '40.00'
            'reference_no' = 'SMOKE-BAD-1'
        }

    $badProofCount = (Invoke-MySql "SELECT COUNT(*) FROM shop_payment_proofs WHERE order_id = $payOrderId;" | Out-String).Trim()
    $badProofPage  = Invoke-App -Path "/orders/$payOrderId" -Session 'member'
    $badProofState = (Invoke-MySql "SELECT status FROM shop_orders WHERE id = $payOrderId;" | Out-String).Trim()

    Assert-That 'P7-T2' ($badProofPost.Status -eq 302 -and $badProofCount -eq '0' `
            -and $badProofState -eq 'pending' -and $badProofPage.Body -match 'not allowed') `
        "a PHP file renamed .png is refused with no attempt written (attempts=$badProofCount, status=$badProofState)"

    # P7-T1 - upload a valid PNG proof.
    $proofUpload = Invoke-App -Method 'POST' -Path "/orders/$payOrderId/proof" -Session 'member' `
        -Files @{ 'proof_image' = $proofPng } `
        -Form @{
            '_csrf'         = (Get-Csrf $badProofPage.Body)
            'amount_sent'   = '40.00'
            'reference_no'  = 'SMOKE-REF-1'
            'transfer_date' = (Get-Date -Format 'yyyy-MM-dd')
        }

    $payStatus  = (Invoke-MySql "SELECT status FROM shop_orders WHERE id = $payOrderId;" | Out-String).Trim()
    $proofRow   = (Invoke-MySql "SELECT CONCAT(attempt_no, '|', status, '|', amount_claimed, '|', reference_no) FROM shop_payment_proofs WHERE order_id = $payOrderId;" | Out-String).Trim()
    $proofId    = [int]((Invoke-MySql "SELECT id FROM shop_payment_proofs WHERE order_id = $payOrderId LIMIT 1;" | Out-String).Trim())
    $proofEvent = (Invoke-MySql "SELECT COUNT(*) FROM shop_order_events WHERE order_id = $payOrderId AND reason_code = 'proof_submitted';" | Out-String).Trim()
    $proofPage  = Invoke-App -Path "/orders/$payOrderId" -Session 'member'

    Assert-That 'P7-T1' ($proofUpload.Status -eq 302 -and $payStatus -eq 'payment_review' `
            -and $proofRow -eq '1|pending|40.00|SMOKE-REF-1' -and $proofEvent -eq '1' `
            -and $proofPage.Body -match 'We are verifying your payment') `
        "POST /orders/{id}/proof stores attempt 1 and moves the order to review (status=$payStatus, proof=$proofRow)"

    # P7-T9 - the proof streams only through the guarded admin route.
    $adminProof   = Invoke-App -Path "/admin/proofs/$proofId" -Session 'admin'
    $memberProof  = Invoke-App -Path "/admin/proofs/$proofId" -Session 'member'
    $guestProof   = Invoke-App -Path "/admin/proofs/$proofId"
    $proofMime    = ''
    if ($adminProof.Headers.ContainsKey('content-type')) { $proofMime = [string] $adminProof.Headers['content-type'] }

    Assert-That 'P7-T9' ($adminProof.Status -eq 200 -and $proofMime -match 'image/png' `
            -and $memberProof.Status -eq 403 -and $guestProof.Status -eq 302) `
        "GET /admin/proofs/{id} streams for an admin only (admin=$($adminProof.Status)/$proofMime, member=$($memberProof.Status), guest=$($guestProof.Status))"

    # The review pane opens for an admin.
    $reviewPage = Invoke-App -Path "/admin/orders/$payOrderId" -Session 'admin'
    Assert-That 'P7-T4' ($reviewPage.Status -eq 200 -and $reviewPage.Body -match 'Payment review' `
            -and $reviewPage.Body -match 'Verify payment' -and $reviewPage.Body -match 'Reject proof' `
            -and $reviewPage.Body -match "admin/proofs/$proofId") `
        "the admin order page shows the review pane (status=$($reviewPage.Status))"

    # P7-T4 - a wrong amount is refused and changes nothing.
    $reviewToken = Get-Csrf $reviewPage.Body
    $wrongAmount = Invoke-App -Method 'POST' -Path "/admin/orders/$payOrderId/transition" -Session 'admin' -Form @{
        '_csrf'             = $reviewToken
        'to'                = 'paid'
        'amount_received'   = '39.99'
        'payment_reference' = 'SMOKE-REF-1'
    }
    $statusAfterWrong = (Invoke-MySql "SELECT status FROM shop_orders WHERE id = $payOrderId;" | Out-String).Trim()
    $wrongPage        = Invoke-App -Path "/admin/orders/$payOrderId" -Session 'admin'

    Assert-That 'P7-T4' ($wrongAmount.Status -eq 302 -and $statusAfterWrong -eq 'payment_review' `
            -and $wrongPage.Body -match 'does not match the order total') `
        "a wrong amount is refused and the order stays in review (status=$statusAfterWrong)"

    # P7-T4 - verify with the correct amount.
    $verified = Invoke-App -Method 'POST' -Path "/admin/orders/$payOrderId/transition" -Session 'admin' -Form @{
        '_csrf'             = (Get-Csrf $wrongPage.Body)
        'to'                = 'paid'
        'amount_received'   = '40.00'
        'payment_reference' = 'SMOKE-REF-1'
        'note'              = 'Smoke verification'
    }

    $paidStatus  = (Invoke-MySql "SELECT CONCAT(o.status, '|', CASE WHEN o.paid_at IS NULL THEN 'no' ELSE 'yes' END, '|', COALESCE(o.payment_reference, '')) FROM shop_orders o WHERE o.id = $payOrderId;" | Out-String).Trim()
    $paidLine    = (Invoke-MySql "SELECT reservation_state FROM shop_order_items WHERE order_id = $payOrderId;" | Out-String).Trim()
    $paidProof   = (Invoke-MySql "SELECT status FROM shop_payment_proofs WHERE id = $proofId;" | Out-String).Trim()
    $paidEvent   = (Invoke-MySql "SELECT COUNT(*) FROM shop_order_events WHERE order_id = $payOrderId AND reason_code = 'payment_verified';" | Out-String).Trim()
    $paidPage    = Invoke-App -Path "/orders/$payOrderId" -Session 'member'

    Assert-That 'P7-T4' ($verified.Status -eq 302 -and $paidStatus -eq 'paid|yes|SMOKE-REF-1' `
            -and $paidLine -eq 'committed' -and $paidProof -eq 'verified' -and $paidEvent -eq '1' `
            -and $paidPage.Body -match 'Payment received') `
        "verifying pays the order, commits the stock and marks the proof (status=$paidStatus, line=$paidLine, proof=$paidProof)"

    # P7-T5 / P7-T6 - reject, then re-upload within the correction window.
    Invoke-MySql "DELETE ci FROM cart_items ci JOIN carts c ON c.id = ci.cart_id WHERE c.user_id = $cartMemberId;" | Out-Null
    Invoke-App -Method 'POST' -Path '/cart/add' -Session 'member' `
        -Form @{ '_csrf' = $memberCartToken; 'product_id' = "$orderProductId"; 'quantity' = '1' } | Out-Null
    $retryPage  = Invoke-App -Path '/checkout' -Session 'member'
    $retryToken = Get-Csrf $retryPage.Body
    $retryIdem  = ''
    if ($retryPage.Body -match 'name="idempotency_key"\s+value="([0-9a-f]{32})"') { $retryIdem = $matches[1] }
    $retryForm = $payForm.Clone()
    $retryForm['_csrf']           = $retryToken
    $retryForm['idempotency_key'] = $retryIdem
    $retryPlaced = Invoke-App -Method 'POST' -Path '/checkout' -Session 'member' -Form $retryForm
    $retryOrderId = 0
    if ($retryPlaced.Location -match '/orders/(\d+)$') { $retryOrderId = [int]$matches[1] }

    $retryOrderPage = Invoke-App -Path "/orders/$retryOrderId" -Session 'member'
    $retryOrderToken = Get-Csrf $retryOrderPage.Body
    Invoke-App -Method 'POST' -Path "/orders/$retryOrderId/proof" -Session 'member' `
        -Files @{ 'proof_image' = $proofPng } `
        -Form @{
            '_csrf'        = $retryOrderToken
            'amount_sent'  = '40.00'
            'reference_no' = 'SMOKE-REF-2'
        } | Out-Null

    $retryProofId = [int]((Invoke-MySql "SELECT id FROM shop_payment_proofs WHERE order_id = $retryOrderId ORDER BY id DESC LIMIT 1;" | Out-String).Trim())
    $retryAdmin   = Invoke-App -Path "/admin/orders/$retryOrderId" -Session 'admin'
    $rejected     = Invoke-App -Method 'POST' -Path "/admin/orders/$retryOrderId/transition" -Session 'admin' -Form @{
        '_csrf'       = (Get-Csrf $retryAdmin.Body)
        'to'          = 'payment_failed'
        'reason_code' = 'wrong_amount'
        'note'        = 'Smoke rejection'
    }

    $failedRow = (Invoke-MySql "SELECT CONCAT(status, '|', CASE WHEN correction_deadline IS NULL THEN 'no' ELSE 'yes' END) FROM shop_orders WHERE id = $retryOrderId;" | Out-String).Trim()
    $failedLine = (Invoke-MySql "SELECT reservation_state FROM shop_order_items WHERE order_id = $retryOrderId;" | Out-String).Trim()
    $failedProof = (Invoke-MySql "SELECT CONCAT(status, '|', COALESCE(reject_reason, '')) FROM shop_payment_proofs WHERE id = $retryProofId;" | Out-String).Trim()
    $failedPage = Invoke-App -Path "/orders/$retryOrderId" -Session 'member'

    Assert-That 'P7-T5' ($rejected.Status -eq 302 -and $failedRow -eq 'payment_failed|yes' `
            -and $failedLine -eq 'released' -and $failedProof -match '^rejected\|The amount received' `
            -and $failedPage.Body -match 'Payment needs correction') `
        "a rejection releases the stock and opens the correction window (status=$failedRow, line=$failedLine)"

    $retryUpload = Invoke-App -Method 'POST' -Path "/orders/$retryOrderId/proof" -Session 'member' `
        -Files @{ 'proof_image' = $proofPng } `
        -Form @{
            '_csrf'        = (Get-Csrf $failedPage.Body)
            'amount_sent'  = '40.00'
            'reference_no' = 'SMOKE-REF-2R'
        }

    $retryStatus  = (Invoke-MySql "SELECT status FROM shop_orders WHERE id = $retryOrderId;" | Out-String).Trim()
    $retryLine    = (Invoke-MySql "SELECT reservation_state FROM shop_order_items WHERE order_id = $retryOrderId;" | Out-String).Trim()
    $retryAttempt = (Invoke-MySql "SELECT COUNT(*) FROM shop_payment_proofs WHERE order_id = $retryOrderId;" | Out-String).Trim()

    Assert-That 'P7-T6' ($retryUpload.Status -eq 302 -and $retryStatus -eq 'payment_review' `
            -and $retryLine -eq 'reserved' -and $retryAttempt -eq '2') `
        "a corrected proof re-reserves the stock (status=$retryStatus, line=$retryLine, attempts=$retryAttempt)"

    # P7-T12 - an admin cancels the manual order; nothing is refunded.
    $retryReview = Invoke-App -Path "/admin/orders/$retryOrderId" -Session 'admin'
    $cancelledByAdmin = Invoke-App -Method 'POST' -Path "/admin/orders/$retryOrderId/transition" -Session 'admin' -Form @{
        '_csrf'       = (Get-Csrf $retryReview.Body)
        'to'          = 'cancelled'
        'reason_code' = 'out_of_stock'
        'note'        = 'Smoke cancel'
    }
    $cancelRow    = (Invoke-MySql "SELECT CONCAT(status, '|', reservation_state) FROM shop_orders o JOIN shop_order_items oi ON oi.order_id = o.id WHERE o.id = $retryOrderId LIMIT 1;" | Out-String).Trim()
    $refundRows   = (Invoke-MySql "SELECT COUNT(*) FROM ewallet_ledger WHERE user_id = $cartMemberId AND ref_type = 'refund';" | Out-String).Trim()
    $cancelProof  = (Invoke-MySql "SELECT status FROM shop_payment_proofs WHERE order_id = $retryOrderId AND status = 'pending';" | Out-String).Trim()

    Assert-That 'P7-T12' ($cancelledByAdmin.Status -eq 302 -and $cancelRow -eq 'cancelled|released' `
            -and $refundRows -eq '0' -and $cancelProof -eq '') `
        "an admin cancellation releases the stock and refunds no manual payment (row=$cancelRow, refunds=$refundRows)"

    # P7-T7 - the expiry CLI cancels an overdue order once and only once.
    Invoke-MySql "DELETE ci FROM cart_items ci JOIN carts c ON c.id = ci.cart_id WHERE c.user_id = $cartMemberId;" | Out-Null
    Invoke-App -Method 'POST' -Path '/cart/add' -Session 'member' `
        -Form @{ '_csrf' = $memberCartToken; 'product_id' = "$orderProductId"; 'quantity' = '1' } | Out-Null
    $expirePage  = Invoke-App -Path '/checkout' -Session 'member'
    $expireToken = Get-Csrf $expirePage.Body
    $expireIdem  = ''
    if ($expirePage.Body -match 'name="idempotency_key"\s+value="([0-9a-f]{32})"') { $expireIdem = $matches[1] }
    $expireForm = $payForm.Clone()
    $expireForm['_csrf']           = $expireToken
    $expireForm['idempotency_key'] = $expireIdem
    $expirePlaced = Invoke-App -Method 'POST' -Path '/checkout' -Session 'member' -Form $expireForm
    $expireOrderId = 0
    if ($expirePlaced.Location -match '/orders/(\d+)$') { $expireOrderId = [int]$matches[1] }

    Invoke-MySql "UPDATE shop_orders SET payment_deadline = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = $expireOrderId;" | Out-Null

    $expiryScript = Join-Path (Split-Path -Parent $PSScriptRoot) 'bin\shop_expire.php'
    $expiryFirst  = ((& $PhpPath $expiryScript --json 2>&1) -join "`n") | ConvertFrom-Json
    $expirySecond = ((& $PhpPath $expiryScript --json 2>&1) -join "`n") | ConvertFrom-Json
    $expiredStatus = (Invoke-MySql "SELECT status FROM shop_orders WHERE id = $expireOrderId;" | Out-String).Trim()
    $expiredLine   = (Invoke-MySql "SELECT reservation_state FROM shop_order_items WHERE order_id = $expireOrderId;" | Out-String).Trim()
    $expiredEvent  = (Invoke-MySql "SELECT COUNT(*) FROM shop_order_events WHERE order_id = $expireOrderId AND reason_code = 'payment_expired';" | Out-String).Trim()

    Assert-That 'P7-T7' ($expiryFirst.ok -eq $true -and @($expiryFirst.ids) -contains $expireOrderId `
            -and $expirySecond.expired -eq 0 -and $expiredStatus -eq 'cancelled' `
            -and $expiredLine -eq 'released' -and $expiredEvent -eq '1') `
        "bin/shop_expire.php cancels the overdue order and is idempotent (first=$($expiryFirst.expired), second=$($expirySecond.expired), status=$expiredStatus)"

    # P7-T7 - the guarded web entry (doctor, dry-run, JSON), used for local testing.
    $cronDoctor = Invoke-App -Path '/cron/shop_expire.php?doctor=1'
    $cronDry    = Invoke-App -Path '/cron/shop_expire.php?dry-run=1&from-settings=1'
    $cronJson   = Invoke-App -Path '/cron/shop_expire.php?json=1'
    $cronPayload = $null
    try { $cronPayload = $cronJson.Body | ConvertFrom-Json } catch { }
    $cronJsonOk = $cronPayload -ne $null -and $cronPayload.ok -eq $true `
        -and $cronPayload.trigger -eq 'http' -and $cronPayload.mode -eq 'stamps'

    Assert-That 'P7-T7' ($cronDoctor.Status -eq 200 -and $cronDoctor.Body -match 'expiry enabled' `
            -and $cronDoctor.Body -match 'timezone' -and $cronDoctor.Body -match 'log' `
            -and $cronDry.Status -eq 200 -and $cronDry.Body -match 'dry run' `
            -and $cronDry.Body -match 'mode:\s+settings' `
            -and $cronJson.Status -eq 200 -and $cronJsonOk) `
        "the web cron entry serves doctor, dry-run and JSON (doctor=$($cronDoctor.Status), dry=$($cronDry.Status), json=$($cronJson.Status))"

    # P7-T7 - the admin kill switch pauses the web entry too.
    $expiryBefore = Get-SettingValue 'shop_expiry_enabled'
    Set-SettingValue 'shop_expiry_enabled' '0'
    $cronDisabled = Invoke-App -Path '/cron/shop_expire.php'
    Set-SettingValue 'shop_expiry_enabled' '1'
    $cronEnabled = Invoke-App -Path '/cron/shop_expire.php?dry-run=1'
    Set-SettingValue 'shop_expiry_enabled' $expiryBefore

    Assert-That 'P7-T7' ($cronDisabled.Status -eq 200 -and $cronDisabled.Body -match 'disabled in the admin settings' `
            -and $cronEnabled.Status -eq 200 -and $cronEnabled.Body -match 'enabled:\s+yes') `
        "the expiry kill switch pauses the web cron entry (status=$($cronDisabled.Status))"

    # P7-T10 - an admin cannot verify their own order.
    $adminCart      = Invoke-App -Path '/cart' -Session 'admin'
    $adminCartToken = Get-Csrf $adminCart.Body
    Invoke-App -Method 'POST' -Path '/cart/add' -Session 'admin' `
        -Form @{ '_csrf' = $adminCartToken; 'product_id' = "$orderProductId"; 'quantity' = '1' } | Out-Null
    $selfPage  = Invoke-App -Path '/checkout' -Session 'admin'
    $selfToken = Get-Csrf $selfPage.Body
    $selfIdem  = ''
    if ($selfPage.Body -match 'name="idempotency_key"\s+value="([0-9a-f]{32})"') { $selfIdem = $matches[1] }
    $selfForm = $payForm.Clone()
    $selfForm['_csrf']           = $selfToken
    $selfForm['idempotency_key'] = $selfIdem
    $selfPlaced = Invoke-App -Method 'POST' -Path '/checkout' -Session 'admin' -Form $selfForm
    $selfOrderId = 0
    if ($selfPlaced.Location -match '/orders/(\d+)$') { $selfOrderId = [int]$matches[1] }

    $selfOrderPage = Invoke-App -Path "/orders/$selfOrderId" -Session 'admin'
    Invoke-App -Method 'POST' -Path "/orders/$selfOrderId/proof" -Session 'admin' `
        -Files @{ 'proof_image' = $proofPng } `
        -Form @{
            '_csrf'        = (Get-Csrf $selfOrderPage.Body)
            'amount_sent'  = '40.00'
            'reference_no' = 'SMOKE-SELF-1'
        } | Out-Null

    $selfReviewPage = Invoke-App -Path "/admin/orders/$selfOrderId" -Session 'admin'
    $selfVerify     = Invoke-App -Method 'POST' -Path "/admin/orders/$selfOrderId/transition" -Session 'admin' -Form @{
        '_csrf'             = (Get-Csrf $selfReviewPage.Body)
        'to'                = 'paid'
        'amount_received'   = '40.00'
        'payment_reference' = 'SMOKE-SELF-1'
    }
    $selfStatus  = (Invoke-MySql "SELECT status FROM shop_orders WHERE id = $selfOrderId;" | Out-String).Trim()
    $selfAfter   = Invoke-App -Path "/admin/orders/$selfOrderId" -Session 'admin'

    Assert-That 'P7-T10' ($selfOrderId -gt 0 -and $selfReviewPage.Body -match 'You placed this order' `
            -and $selfVerify.Status -eq 302 -and $selfStatus -eq 'payment_review' `
            -and $selfAfter.Body -match 'cannot verify, reject or cancel their own order') `
        "an admin cannot verify their own order (status=$selfStatus)"

    # P7-T4 / P8-T16 - each settings card saves on its own.
    $settingsBefore = Get-SettingValue 'shop_payment_deadline_hours'
    $settingsPage   = Invoke-App -Path '/admin/settings' -Session 'admin'
    $settingsToken  = Get-Csrf $settingsPage.Body

    foreach ($key in @(
            'shop_enabled', 'shop_expiry_enabled', 'shop_autocomplete_enabled',
            'payment_gcash_number', 'payment_gcash_name',
            'payment_maya_number', 'payment_maya_name', 'payment_bank_name',
            'payment_bank_account_name', 'payment_bank_account_number',
            'shop_payment_deadline_hours', 'shop_correction_window_hours',
            'shop_max_proof_attempts', 'shop_autocomplete_days',
            'seller_name', 'seller_address', 'seller_tin', 'seller_phone')) {
        $settingsSnapshot[$key] = Get-SettingValue $key
    }

    $sectionForms = ([regex]::Matches($settingsPage.Body, 'name="section" value="(shop|accounts|windows|fulfilment)"')).Count
    $saveButtons  = ([regex]::Matches($settingsPage.Body, 'Save settings')).Count

    # 1. Receiving accounts.
    $saveAccounts = Invoke-App -Method 'POST' -Path '/admin/settings' -Session 'admin' -Form @{
        '_csrf'                       = $settingsToken
        'section'                     = 'accounts'
        'payment_gcash_number'        = '0917 123 4567'
        'payment_gcash_name'          = 'EcoWealth Smoke'
        'payment_maya_number'         = '0917 765 4321'
        'payment_maya_name'           = 'EcoWealth Smoke'
        'payment_bank_name'           = 'BDO'
        'payment_bank_account_name'   = 'EcoWealth Smoke Inc.'
        'payment_bank_account_number' = '1234 5678 9012'
    }
    $gcashSaved = Get-SettingValue 'payment_gcash_number'

    # 2. Fulfilment & invoices, without touching the accounts card.
    $saveFulfilment = Invoke-App -Method 'POST' -Path '/admin/settings' -Session 'admin' -Form @{
        '_csrf'                       = $settingsToken
        'section'                     = 'fulfilment'
        'seller_name'                 = 'EcoWealth Smoke Trading'
        'seller_address'              = '1 Smoke Plaza, Makati'
        'seller_tin'                  = '000-111-222-000'
        'seller_phone'                = '0917 999 0000'
        'shop_autocomplete_days'      = '9'
        'shop_autocomplete_enabled'   = '1'
    }
    $sellerSaved = Get-SettingValue 'seller_name'
    $daysSaved   = Get-SettingValue 'shop_autocomplete_days'
    $autoSaved   = Get-SettingValue 'shop_autocomplete_enabled'
    $gcashAfterFulfilment = Get-SettingValue 'payment_gcash_number'

    # 3. Payment windows, without touching the seller block.
    $saveWindows = Invoke-App -Method 'POST' -Path '/admin/settings' -Session 'admin' -Form @{
        '_csrf'                       = $settingsToken
        'section'                     = 'windows'
        'shop_payment_deadline_hours' = '30'
        'shop_correction_window_hours' = '12'
        'shop_max_proof_attempts'     = '4'
        'shop_expiry_enabled'         = '1'
    }
    $settingsSaved = (Invoke-MySql "SELECT CONCAT(value, '|') FROM settings WHERE key_name = 'shop_payment_deadline_hours';" | Out-String).Trim()
    $attemptsSaved = Get-SettingValue 'shop_max_proof_attempts'
    $expirySaved   = Get-SettingValue 'shop_expiry_enabled'
    $sellerAfterWindows = Get-SettingValue 'seller_name'

    # 4. Shop switch, on then off, without touching the windows.
    $shopOff = Invoke-App -Method 'POST' -Path '/admin/settings' -Session 'admin' -Form @{
        '_csrf'        = $settingsToken
        'section'      = 'shop'
        'shop_enabled' = '0'
    }
    $shopOffValue = Get-SettingValue 'shop_enabled'
    $deadlineAfterShop = Get-SettingValue 'shop_payment_deadline_hours'

    $shopOn = Invoke-App -Method 'POST' -Path '/admin/settings' -Session 'admin' -Form @{
        '_csrf'        = $settingsToken
        'section'      = 'shop'
        'shop_enabled' = '1'
    }
    $shopOnValue = Get-SettingValue 'shop_enabled'

    Assert-That 'P7-T4' ($settingsPage.Status -eq 200 -and $settingsPage.Body -match 'Receiving accounts' `
            -and $settingsPage.Body -match 'name="shop_payment_deadline_hours"' `
            -and $settingsPage.Body -match 'name="shop_expiry_enabled"' `
            -and $settingsPage.Body -match 'name="seller_name"' `
            -and $settingsPage.Body -match 'name="shop_autocomplete_days"' `
            -and $settingsPage.Body -match 'Fulfilment &amp; invoices' `
            -and $sectionForms -eq 4 -and $saveButtons -eq 4 `
            -and $saveAccounts.Status -eq 302 -and $gcashSaved -eq '0917 123 4567' `
            -and $saveFulfilment.Status -eq 302 -and $sellerSaved -eq 'EcoWealth Smoke Trading' `
            -and $daysSaved -eq '9' -and $autoSaved -eq '1' `
            -and $saveWindows.Status -eq 302 -and $settingsSaved -eq '30|' `
            -and $attemptsSaved -eq '4' -and $expirySaved -eq '1' `
            -and $shopOff.Status -eq 302 -and $shopOffValue -eq '0' `
            -and $shopOn.Status -eq 302 -and $shopOnValue -eq '1') `
        "each settings card has its own form + Save settings (forms=$sectionForms, buttons=$saveButtons, accounts=$gcashSaved, seller=$sellerSaved, deadline=$settingsSaved)"

    Assert-That 'P7-T4' ($gcashAfterFulfilment -eq '0917 123 4567' `
            -and $sellerAfterWindows -eq 'EcoWealth Smoke Trading' `
            -and $deadlineAfterShop -eq '30') `
        "saving one card leaves the other cards untouched (accounts=$gcashAfterFulfilment, seller=$sellerAfterWindows, deadline=$deadlineAfterShop)"

    $settingsAfter   = Invoke-App -Path '/admin/settings' -Session 'admin'
    $badSettings     = Invoke-App -Method 'POST' -Path '/admin/settings' -Session 'admin' -Form @{
        '_csrf'                        = (Get-Csrf $settingsAfter.Body)
        'section'                      = 'windows'
        'shop_payment_deadline_hours'  = '0'
        'shop_correction_window_hours' = '12'
        'shop_max_proof_attempts'      = '4'
        'shop_expiry_enabled'          = '1'
    }
    $settingsKept = Get-SettingValue 'shop_payment_deadline_hours'
    $settingsErrorPage = Invoke-App -Path '/admin/settings' -Session 'admin'

    Assert-That 'P7-T4' ($badSettings.Status -eq 302 -and $settingsKept -eq '30' `
            -and $settingsErrorPage.Body -match 'must be at least 1') `
        "an invalid window is refused and keeps the saved value (deadline=$settingsKept)"

    # P8-T16 - the fulfilment card validates and saves independently too.
    $badFulfilment = Invoke-App -Method 'POST' -Path '/admin/settings' -Session 'admin' -Form @{
        '_csrf'                      = (Get-Csrf $settingsErrorPage.Body)
        'section'                    = 'fulfilment'
        'seller_name'                = ''
        'seller_address'             = '1 Smoke Plaza, Makati'
        'seller_tin'                 = '000-111-222-000'
        'seller_phone'               = '0917 999 0000'
        'shop_autocomplete_days'     = '9'
        'shop_autocomplete_enabled'  = '1'
    }
    $sellerKept = Get-SettingValue 'seller_name'
    $fulfilmentErrorPage = Invoke-App -Path '/admin/settings' -Session 'admin'

    $unknownSection = Invoke-App -Method 'POST' -Path '/admin/settings' -Session 'admin' -Form @{
        '_csrf'   = (Get-Csrf $fulfilmentErrorPage.Body)
        'section' = 'nope'
    }
    $unknownPage = Invoke-App -Path '/admin/settings' -Session 'admin'

    Assert-That 'P8-T16' ($badFulfilment.Status -eq 302 -and $sellerKept -eq 'EcoWealth Smoke Trading' `
            -and $fulfilmentErrorPage.Body -match 'Registered business name is required' `
            -and $unknownSection.Status -eq 302 -and $unknownPage.Body -match 'Unknown settings section') `
        "the fulfilment card validates on its own and an unknown section is refused (seller=$sellerKept)"

    $memberSettings = Invoke-App -Path '/admin/settings' -Session 'member'
    Assert-That 'P7-T4' ($memberSettings.Status -eq 403) `
        "a member cannot open /admin/settings (got $($memberSettings.Status))"

    # ---- Phase 8: fulfilment and invoices ---------------------------------
    $invoiceCount = (Invoke-MySql "SELECT COUNT(*) FROM shop_invoices WHERE order_id = $orderId;" | Out-String).Trim()
    $invoiceRow   = (Invoke-MySql "SELECT CONCAT(invoice_no, '|', subtotal, '|', total, '|', CASE WHEN voided_at IS NULL THEN 'live' ELSE 'void' END) FROM shop_invoices WHERE order_id = $orderId;" | Out-String).Trim()
    $invoiceNo    = ''
    if ($invoiceRow -match '^([^|]+)\|') { $invoiceNo = $matches[1] }

    $invoiceMember = Invoke-App -Path "/orders/$orderId/invoice" -Session 'member'
    $invoiceAdmin  = Invoke-App -Path "/admin/orders/$orderId/invoice" -Session 'admin'
    $invoiceOther  = Invoke-App -Path "/orders/$orderId/invoice" -Session 'other'
    $invoiceGuest  = Invoke-App -Path "/orders/$orderId/invoice"

    Assert-That 'P8-T5' ($invoiceCount -eq '1' -and $invoiceRow -match '^INV-\d{6}-\d{5,}\|80\.00\|80\.00\|live$' `
            -and $invoiceMember.Status -eq 200 -and $invoiceMember.Body -match 'SALES INVOICE' `
            -and $invoiceMember.Body.Contains($invoiceNo) -and $invoiceMember.Body -match '@page \{ size: A4; margin: 14mm; \}' `
            -and $invoiceMember.Body -notmatch 'layout-menu' `
            -and $invoiceAdmin.Status -eq 200 -and $invoiceAdmin.Body.Contains($invoiceNo) `
            -and $invoiceOther.Status -eq 404 -and $invoiceGuest.Status -eq 302) `
        "an e-wallet payment freezes one A4 invoice; only its owner and an admin can read it (no=$invoiceNo, member=$($invoiceMember.Status), other=$($invoiceOther.Status), guest=$($invoiceGuest.Status))"

    # P8-T6 - a reprint returns the same number and one row.
    $invoiceAgain = Invoke-App -Path "/orders/$orderId/invoice" -Session 'member'
    $invoiceCountAgain = (Invoke-MySql "SELECT COUNT(*) FROM shop_invoices WHERE order_id = $orderId;" | Out-String).Trim()
    Assert-That 'P8-T6' ($invoiceAgain.Status -eq 200 -and $invoiceAgain.Body.Contains($invoiceNo) `
            -and $invoiceAgain.Body -match 'SALES INVOICE' -and $invoiceCountAgain -eq '1') `
        "re-printing the invoice returns the same snapshot (no=$invoiceNo, rows=$invoiceCountAgain)"

    # P8-T1 - pack, ready to ship.
    $stockBeforeShip = [int]((Invoke-MySql "SELECT stock FROM products WHERE id = $orderProductId;" | Out-String).Trim())
    $adminOrderPage  = Invoke-App -Path "/admin/orders/$orderId" -Session 'admin'
    $pack = Invoke-App -Method 'POST' -Path "/admin/orders/$orderId/transition" -Session 'admin' `
        -Form @{ '_csrf' = (Get-Csrf $adminOrderPage.Body); 'to' = 'packing'; 'note' = 'Smoke packing' }
    $packStatus = (Invoke-MySql "SELECT status FROM shop_orders WHERE id = $orderId;" | Out-String).Trim()
    $packPage   = Invoke-App -Path "/admin/orders/$orderId" -Session 'admin'
    $ready = Invoke-App -Method 'POST' -Path "/admin/orders/$orderId/transition" -Session 'admin' `
        -Form @{ '_csrf' = (Get-Csrf $packPage.Body); 'to' = 'ready_to_ship' }
    $readyStatus = (Invoke-MySql "SELECT status FROM shop_orders WHERE id = $orderId;" | Out-String).Trim()
    $readyPage   = Invoke-App -Path "/admin/orders/$orderId" -Session 'admin'
    Assert-That 'P8-T1' ($pack.Status -eq 302 -and $packStatus -eq 'packing' `
            -and $packPage.Body -match 'Mark ready to ship' `
            -and $ready.Status -eq 302 -and $readyStatus -eq 'ready_to_ship' `
            -and $readyPage.Body -match '<select name="courier"' `
            -and $readyPage.Body -match 'name="courier_other"' `
            -and $readyPage.Body -match 'Others \(type below\)' `
            -and $readyPage.Body -match 'app-ship.js' `
            -and $readyPage.Body -notmatch '<datalist' `
            -and $readyPage.Body -match 'name="tracking_number"' `
            -and $readyPage.Body -match 'Ship order') `
        "POST transition pack -> ready_to_ship; the ship form has a courier select + Others field (packed=$packStatus, ready=$readyStatus)"

    # P8-T2 - shipping without tracking is refused.
    $badShip = Invoke-App -Method 'POST' -Path "/admin/orders/$orderId/ship" -Session 'admin' `
        -Form @{ '_csrf' = (Get-Csrf $readyPage.Body); 'courier' = 'J&T Express'; 'tracking_number' = '' }
    $afterBadShip      = (Invoke-MySql "SELECT status FROM shop_orders WHERE id = $orderId;" | Out-String).Trim()
    $shipmentAfterBad  = (Invoke-MySql "SELECT COUNT(*) FROM shop_shipments WHERE order_id = $orderId;" | Out-String).Trim()
    $badShipPage       = Invoke-App -Path "/admin/orders/$orderId" -Session 'admin'
    Assert-That 'P8-T2' ($badShip.Status -eq 302 -and $afterBadShip -eq 'ready_to_ship' `
            -and $shipmentAfterBad -eq '0' -and $badShipPage.Body -match 'Tracking number is required') `
        "shipping without tracking is refused with no write (status=$afterBadShip, shipments=$shipmentAfterBad)"

    # P8-T2 - "Others" without a typed name is refused too (and nothing is written).
    $badOther = Invoke-App -Method 'POST' -Path "/admin/orders/$orderId/ship" -Session 'admin' `
        -Form @{
            '_csrf'           = (Get-Csrf $badShipPage.Body)
            'courier'         = 'Others'
            'courier_other'   = ''
            'tracking_number' = 'JT-SMOKE-OTHER'
        }
    $afterBadOther     = (Invoke-MySql "SELECT status FROM shop_orders WHERE id = $orderId;" | Out-String).Trim()
    $shipmentAfterBadOther = (Invoke-MySql "SELECT COUNT(*) FROM shop_shipments WHERE order_id = $orderId;" | Out-String).Trim()
    $badOtherPage      = Invoke-App -Path "/admin/orders/$orderId" -Session 'admin'
    Assert-That 'P8-T2' ($badOther.Status -eq 302 -and $afterBadOther -eq 'ready_to_ship' `
            -and $shipmentAfterBadOther -eq '0' `
            -and $badOtherPage.Body -match 'Enter the courier name' `
            -and $badOtherPage.Body -match 'value="Others" selected') `
        "choosing Others without a name is refused and keeps the choice (status=$afterBadOther, shipments=$shipmentAfterBadOther)"

    # P8-T1 / P8-T3 - ship: tracking saved, stock deducted exactly once.
    $ship = Invoke-App -Method 'POST' -Path "/admin/orders/$orderId/ship" -Session 'admin' `
        -Form @{
            '_csrf'           = (Get-Csrf $badOtherPage.Body)
            'courier'         = 'J&T Express'
            'tracking_number' = 'JT-SMOKE-0001'
            'notes'           = 'Smoke shipment'
        }
    $shipStatus    = (Invoke-MySql "SELECT CONCAT(status, '|', CASE WHEN shipped_at IS NULL THEN 'no' ELSE 'yes' END) FROM shop_orders WHERE id = $orderId;" | Out-String).Trim()
    $shipmentRow   = (Invoke-MySql "SELECT CONCAT(courier, '|', tracking_number) FROM shop_shipments WHERE order_id = $orderId;" | Out-String).Trim()
    $stockAfter    = [int]((Invoke-MySql "SELECT stock FROM products WHERE id = $orderProductId;" | Out-String).Trim())
    $lineAfterShip = (Invoke-MySql "SELECT reservation_state FROM shop_order_items WHERE order_id = $orderId;" | Out-String).Trim()
    $shippedMemberPage = Invoke-App -Path "/orders/$orderId" -Session 'member'
    Assert-That 'P8-T1' ($ship.Status -eq 302 -and $shipStatus -eq 'shipped|yes' `
            -and $shipmentRow -eq 'J&T Express|JT-SMOKE-0001' `
            -and $shippedMemberPage.Body -match 'On the way' `
            -and $shippedMemberPage.Body.Contains('JT-SMOKE-0001')) `
        "shipping saves the courier + tracking and the member sees it (status=$shipStatus, shipment=$shipmentRow)"
    Assert-That 'P8-T3' ($stockAfter -eq ($stockBeforeShip - 2) -and $lineAfterShip -eq 'deducted') `
        "shipping deducts the stock once (before=$stockBeforeShip, after=$stockAfter, line=$lineAfterShip)"

    # P8-T8 - an order that has shipped cannot be cancelled.
    $shippedAdminPage = Invoke-App -Path "/admin/orders/$orderId" -Session 'admin'
    $cancelAfterShip  = Invoke-App -Method 'POST' -Path "/admin/orders/$orderId/transition" -Session 'admin' `
        -Form @{ '_csrf' = (Get-Csrf $shippedAdminPage.Body); 'to' = 'cancelled'; 'reason_code' = 'customer_request' }
    $afterCancelShip = (Invoke-MySql "SELECT status FROM shop_orders WHERE id = $orderId;" | Out-String).Trim()
    $cancelPage      = Invoke-App -Path "/admin/orders/$orderId" -Session 'admin'
    Assert-That 'P8-T8' ($cancelAfterShip.Status -eq 302 -and $afterCancelShip -eq 'shipped' `
            -and $cancelPage.Body -match 'cannot be cancelled') `
        "cancelling a shipped order is refused (status=$afterCancelShip)"

    # P8-T4 - deliver, then the member confirms receipt.
    $deliver = Invoke-App -Method 'POST' -Path "/admin/orders/$orderId/deliver" -Session 'admin' `
        -Form @{ '_csrf' = (Get-Csrf $cancelPage.Body); 'note' = 'Left at the reception' }
    $deliveredRow = (Invoke-MySql "SELECT CONCAT(status, '|', CASE WHEN delivered_at IS NULL THEN 'no' ELSE 'yes' END) FROM shop_orders WHERE id = $orderId;" | Out-String).Trim()
    $shipmentDelivered = (Invoke-MySql "SELECT CASE WHEN delivered_at IS NULL THEN 'no' ELSE 'yes' END FROM shop_shipments WHERE order_id = $orderId;" | Out-String).Trim()
    $deliveredMemberPage = Invoke-App -Path "/orders/$orderId" -Session 'member'
    $confirm = Invoke-App -Method 'POST' -Path "/orders/$orderId/confirm" -Session 'member' `
        -Form @{ '_csrf' = (Get-Csrf $deliveredMemberPage.Body); 'note' = 'Received in good order' }
    $completedRow = (Invoke-MySql "SELECT CONCAT(status, '|', CASE WHEN completed_at IS NULL THEN 'no' ELSE 'yes' END) FROM shop_orders WHERE id = $orderId;" | Out-String).Trim()
    $confirmEvent = (Invoke-MySql "SELECT COUNT(*) FROM shop_order_events WHERE order_id = $orderId AND reason_code = 'receipt_confirmed';" | Out-String).Trim()
    $completedPage = Invoke-App -Path "/orders/$orderId" -Session 'member'
    Assert-That 'P8-T4' ($deliver.Status -eq 302 -and $deliveredRow -eq 'delivered|yes' -and $shipmentDelivered -eq 'yes' `
            -and $deliveredMemberPage.Body -match 'Confirm your receipt' `
            -and $confirm.Status -eq 302 -and $completedRow -eq 'completed|yes' -and $confirmEvent -eq '1' `
            -and $completedPage.Body -match 'Order complete' -and $completedPage.Body -match 'View / print invoice') `
        "deliver + member confirm -> completed with timestamps and one event (delivered=$deliveredRow, completed=$completedRow)"

    # P8-T9 - the auto-complete job runs from its web entry and is idempotent.
    # Use a second delivered order, backdated far beyond the window so a real
    # dev database's young orders are never touched.
    Invoke-MySql "DELETE ci FROM cart_items ci JOIN carts c ON c.id = ci.cart_id WHERE c.user_id = $cartMemberId;" | Out-Null
    Invoke-App -Method 'POST' -Path '/cart/add' -Session 'member' `
        -Form @{ '_csrf' = $memberCartToken; 'product_id' = "$orderProductId"; 'quantity' = '1' } | Out-Null
    $autoPage  = Invoke-App -Path '/checkout' -Session 'member'
    $autoToken = Get-Csrf $autoPage.Body
    $autoIdem  = ''
    if ($autoPage.Body -match 'name="idempotency_key"\s+value="([0-9a-f]{32})"') { $autoIdem = $matches[1] }
    $autoForm  = $checkoutForm.Clone()
    $autoForm['_csrf']           = $autoToken
    $autoForm['idempotency_key'] = $autoIdem
    $autoForm.Remove('save_address')
    $autoPlaced = Invoke-App -Method 'POST' -Path '/checkout' -Session 'member' -Form $autoForm
    $autoOrderId = 0
    if ($autoPlaced.Location -match '/orders/(\d+)$') { $autoOrderId = [int]$matches[1] }

    if ($autoOrderId -gt 0) {
        $autoAdminPage = Invoke-App -Path "/admin/orders/$autoOrderId" -Session 'admin'
        Invoke-App -Method 'POST' -Path "/admin/orders/$autoOrderId/transition" -Session 'admin' `
            -Form @{ '_csrf' = (Get-Csrf $autoAdminPage.Body); 'to' = 'packing' } | Out-Null
        $autoAdminPage = Invoke-App -Path "/admin/orders/$autoOrderId" -Session 'admin'
        Invoke-App -Method 'POST' -Path "/admin/orders/$autoOrderId/transition" -Session 'admin' `
            -Form @{ '_csrf' = (Get-Csrf $autoAdminPage.Body); 'to' = 'ready_to_ship' } | Out-Null
        $autoAdminPage = Invoke-App -Path "/admin/orders/$autoOrderId" -Session 'admin'
        Invoke-App -Method 'POST' -Path "/admin/orders/$autoOrderId/ship" -Session 'admin' `
            -Form @{
                '_csrf'         = (Get-Csrf $autoAdminPage.Body)
                'courier'       = 'Others'
                'courier_other' = 'Flash Express'
                'tracking_number' = 'LBC-SMOKE-1'
            } | Out-Null
        $autoCourier = (Invoke-MySql "SELECT courier FROM shop_shipments WHERE order_id = $autoOrderId;" | Out-String).Trim()
        Assert-That 'P8-T2' ($autoCourier -eq 'Flash Express') `
            "the Others picker stores the typed courier (courier=$autoCourier)"
        $autoAdminPage = Invoke-App -Path "/admin/orders/$autoOrderId" -Session 'admin'
        Invoke-App -Method 'POST' -Path "/admin/orders/$autoOrderId/deliver" -Session 'admin' `
            -Form @{ '_csrf' = (Get-Csrf $autoAdminPage.Body) } | Out-Null

        $autoDaysBefore = Get-SettingValue 'shop_autocomplete_days'
        Set-SettingValue 'shop_autocomplete_days' '365'
        Invoke-MySql "UPDATE shop_orders SET delivered_at = DATE_SUB(NOW(), INTERVAL 400 DAY) WHERE id = $autoOrderId;" | Out-Null
        Invoke-MySql "UPDATE shop_shipments SET delivered_at = DATE_SUB(NOW(), INTERVAL 400 DAY) WHERE order_id = $autoOrderId;" | Out-Null

        $autoDoctor = Invoke-App -Path '/cron/order_autocomplete.php?doctor=1'
        $autoDry    = Invoke-App -Path '/cron/order_autocomplete.php?dry-run=1'
        $autoFirstRaw  = Invoke-App -Path '/cron/order_autocomplete.php?json=1'
        $autoSecondRaw = Invoke-App -Path '/cron/order_autocomplete.php?json=1'
        $autoFirst  = $null
        $autoSecond = $null
        try { $autoFirst = $autoFirstRaw.Body | ConvertFrom-Json } catch { }
        try { $autoSecond = $autoSecondRaw.Body | ConvertFrom-Json } catch { }
        $autoStatus = (Invoke-MySql "SELECT status FROM shop_orders WHERE id = $autoOrderId;" | Out-String).Trim()
        $autoEvent  = (Invoke-MySql "SELECT COUNT(*) FROM shop_order_events WHERE order_id = $autoOrderId AND reason_code = 'auto_completed';" | Out-String).Trim()

        Set-SettingValue 'shop_autocomplete_days' $autoDaysBefore

        Assert-That 'P8-T9' ($autoDoctor.Status -eq 200 -and $autoDoctor.Body -match 'enabled' `
                -and $autoDry.Status -eq 200 -and $autoDry.Body -match 'candidates:' `
                -and $autoFirst.ok -eq $true -and @($autoFirst.ids) -contains $autoOrderId `
                -and $autoSecond.completed -eq 0 -and $autoStatus -eq 'completed' -and $autoEvent -eq '1') `
            "order_autocomplete.php completes the old delivery once and is idempotent (first=$($autoFirst.completed), second=$($autoSecond.completed), status=$autoStatus)"
    } else {
        Assert-That 'P8-T9' $false "the second checkout for the auto-complete check did not create an order"
    }

    # ---- Phase 9: registration, codes, membership and activation ---------
    $p9PackageAId = [int]((Invoke-MySql "SELECT id FROM packages WHERE slug = 'package-a' LIMIT 1;" | Out-String).Trim())
    $p9JuanId     = [int]((Invoke-MySql "SELECT id FROM users WHERE username = 'juan' LIMIT 1;" | Out-String).Trim())
    $p9CodeStart  = [int]((Invoke-MySql "SELECT COALESCE(MAX(id), 0) FROM reg_codes;" | Out-String).Trim())

    # P9-T1 - the P1-T9 signup above is a standard, active, sponsored account.
    $p9MemberRow = (Invoke-MySql ("SELECT CONCAT(member_status, '|', IF(package_id IS NULL, 'none', package_id), '|', LENGTH(referral_code)) FROM users WHERE username = '$memberUsername';") | Out-String).Trim()
    $p9MemberSponsor = (Invoke-MySql ("SELECT sp.username FROM users u LEFT JOIN users sp ON sp.id = u.sponsor_id WHERE u.username = '$memberUsername';") | Out-String).Trim()
    Assert-That 'P9-T1' ($p9MemberRow -eq 'active|none|8' -and $p9MemberSponsor -eq 'juan') `
        "standard registration is active, package-free, sponsored and has a referral code (row=$p9MemberRow, sponsor=$p9MemberSponsor)"

    # P9-T1 - a blank sponsor joins the company account (the primary admin).
    $noSponsorName = 'smoke_nosponsor_' + (Get-Random -Minimum 100000 -Maximum 999999)
    $p9Users += $noSponsorName
    $noSponsorPage  = Invoke-App -Path '/register' -Session 'p9f'
    $noSponsorToken = Get-Csrf $noSponsorPage.Body
    $noSponsorPost  = Invoke-App -Method 'POST' -Path '/register' -Session 'p9f' -Form @{
        '_csrf'            = $noSponsorToken
        'mode'             = 'standard'
        'sponsor'          = ''
        'username'         = $noSponsorName
        'email'            = "$noSponsorName@example.com"
        'password'         = $memberPassword
        'password_confirm' = $memberPassword
        'terms'            = '1'
    }
    $noSponsorRow = (Invoke-MySql ("SELECT CONCAT(u.member_status, '|', COALESCE(sp.username, ''), '|', LENGTH(u.referral_code)) FROM users u LEFT JOIN users sp ON sp.id = u.sponsor_id WHERE u.username = '$noSponsorName';") | Out-String).Trim()
    Assert-That 'P9-T1' ($noSponsorPage.Body -match '\(optional\)' `
            -and $noSponsorPost.Status -eq 302 -and $noSponsorPost.Location -match '/dashboard$' `
            -and $noSponsorRow -eq 'active|admin|8') `
        "a blank sponsor is optional and joins the company account (row=$noSponsorRow)"

    # P9-T6 - a referral link pre-fills the sponsor and creates a pending member.
    $refPage = Invoke-App -Path '/register?ref=JUAN0001' -Session 'p9a'
    $refToken = Get-Csrf $refPage.Body
    $pendingUsername = 'smoke_pending_' + (Get-Random -Minimum 100000 -Maximum 999999)
    $p9Users += $pendingUsername
    $refPost = Invoke-App -Method 'POST' -Path '/register' -Session 'p9a' -Form @{
        '_csrf'            = $refToken
        'mode'             = 'pending'
        'sponsor'          = 'JUAN0001'
        'username'         = $pendingUsername
        'email'            = "$pendingUsername@example.com"
        'password'         = $memberPassword
        'password_confirm' = $memberPassword
        'terms'            = '1'
    }
    $pendingRow = (Invoke-MySql ("SELECT CONCAT(u.member_status, '|', COALESCE(sp.username, ''), '|', LENGTH(u.referral_code)) FROM users u LEFT JOIN users sp ON sp.id = u.sponsor_id WHERE u.username = '$pendingUsername';") | Out-String).Trim()
    $p9PendingId = [int]((Invoke-MySql ("SELECT id FROM users WHERE username = '$pendingUsername';") | Out-String).Trim())
    Assert-That 'P9-T6' ($refPage.Status -eq 200 -and $refPage.Body -match 'value="juan"' `
            -and $refPost.Status -eq 302 -and $pendingRow -eq 'pending|juan|8') `
        "a referral link pre-fills the sponsor and creates a pending member (row=$pendingRow)"

    # P9-T2 - an admin-issued code activates the member with its package.
    $codesPage  = Invoke-App -Path '/admin/codes' -Session 'admin'
    $codesToken = Get-Csrf $codesPage.Body
    $genPost = Invoke-App -Method 'POST' -Path '/admin/codes/generate' -Session 'admin' -Form @{
        '_csrf'      = $codesToken
        'package_id' = "$p9PackageAId"
        'quantity'   = '1'
        'price'      = '4498.00'
        'expires_at' = ''
        'code_type'  = 'registration'
    }
    $newCode = (Invoke-MySql "SELECT code FROM reg_codes WHERE id > $p9CodeStart ORDER BY id DESC LIMIT 1;" | Out-String).Trim()
    $newCodeId = [int]((Invoke-MySql "SELECT id FROM reg_codes WHERE code = '$newCode' LIMIT 1;" | Out-String).Trim())
    if ($newCodeId -gt 0) { $p9CodeIds += $newCodeId }

    $codeUsername = 'smoke_code_' + (Get-Random -Minimum 100000 -Maximum 999999)
    $p9Users += $codeUsername
    $codeRegPage  = Invoke-App -Path '/register' -Session 'p9b'
    $codeRegToken = Get-Csrf $codeRegPage.Body
    $codePost = Invoke-App -Method 'POST' -Path '/register' -Session 'p9b' -Form @{
        '_csrf'            = $codeRegToken
        'mode'             = 'code'
        'sponsor'          = 'juan'
        'reg_code'         = $newCode
        'username'         = $codeUsername
        'email'            = "$codeUsername@example.com"
        'password'         = $memberPassword
        'password_confirm' = $memberPassword
        'terms'            = '1'
    }
    $codeRow = (Invoke-MySql ("SELECT CONCAT(u.member_status, '|', p.slug, '|', LENGTH(u.referral_code)) FROM users u JOIN packages p ON p.id = u.package_id WHERE u.username = '$codeUsername';") | Out-String).Trim()
    $codeUseRow = (Invoke-MySql "SELECT CONCAT(status, '|', IF(used_by IS NULL, 0, 1), '|', IF(used_at IS NULL, 0, 1)) FROM reg_codes WHERE id = $newCodeId;" | Out-String).Trim()
    Assert-That 'P9-T2' ($genPost.Status -eq 302 -and $newCode -match '^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$' `
            -and $codePost.Status -eq 302 -and $codeRow -eq 'active|package-a|8' -and $codeUseRow -eq 'used|1|1') `
        "a registration code activates its package and is marked used (status=$($codePost.Status), row=$codeRow, code=$codeUseRow)"

    # P9-T3 - invalid, unknown and reused codes never create an account.
    $badPage  = Invoke-App -Path '/register' -Session 'p9c'
    $badToken = Get-Csrf $badPage.Body
    $badUsername  = 'smoke_bad_' + (Get-Random -Minimum 100000 -Maximum 999999)
    $reusedName   = 'smoke_reused_' + (Get-Random -Minimum 100000 -Maximum 999999)
    $p9Users += $badUsername
    $p9Users += $reusedName
    $badPost = Invoke-App -Method 'POST' -Path '/register' -Session 'p9c' -Form @{
        '_csrf'            = $badToken
        'mode'             = 'code'
        'sponsor'          = 'juan'
        'reg_code'         = 'ZZZZ-ZZZZ-ZZZZ'
        'username'         = $badUsername
        'email'            = "$badUsername@example.com"
        'password'         = $memberPassword
        'password_confirm' = $memberPassword
        'terms'            = '1'
    }
    $reusedPost = Invoke-App -Method 'POST' -Path '/register' -Session 'p9c' -Form @{
        '_csrf'            = $badToken
        'mode'             = 'code'
        'sponsor'          = 'juan'
        'reg_code'         = $newCode
        'username'         = $reusedName
        'email'            = "$reusedName@example.com"
        'password'         = $memberPassword
        'password_confirm' = $memberPassword
        'terms'            = '1'
    }
    # The refusal page must announce the failure once (one alert, one message):
    # the previous build flashed the message and also rendered it in the summary.
    $badRender       = Invoke-App -Path '/register' -Session 'p9c'
    $badMessageCount = [regex]::Matches($badRender.Body, 'That registration code has already been used\.').Count
    $badAlertCount   = [regex]::Matches($badRender.Body, 'alert-danger').Count
    Assert-That 'P9-T3' ($badRender.Status -eq 200 -and $badMessageCount -eq 1 -and $badAlertCount -eq 1) `
        "a refused code renders one notification (alerts=$badAlertCount, messages=$badMessageCount)"
    $badCount    = [int]((Invoke-MySql "SELECT COUNT(*) FROM users WHERE username IN ('$badUsername', '$reusedName');" | Out-String).Trim())
    Assert-That 'P9-T3' ($badPost.Status -eq 302 -and $badPost.Location -match '/register$' `
            -and $reusedPost.Status -eq 302 -and $badCount -eq 0) `
        "invalid and already-used codes are refused without creating an account (rows=$badCount)"

    # P9-T5 - a guest choosing the e-wallet mode is sent to sign in.
    $guestRegPage = Invoke-App -Path '/register' -Session 'guest'
    $guestRegToken = Get-Csrf $guestRegPage.Body
    $ewalletGuestName = 'smoke_ewallet_' + (Get-Random -Minimum 100000 -Maximum 999999)
    $p9Users += $ewalletGuestName
    $ewalletGuest = Invoke-App -Method 'POST' -Path '/register' -Session 'guest' -Form @{
        '_csrf'            = $guestRegToken
        'mode'             = 'ewallet'
        'sponsor'          = 'juan'
        'package_id'       = "$p9PackageAId"
        'username'         = $ewalletGuestName
        'email'            = "$ewalletGuestName@example.com"
        'password'         = $memberPassword
        'password_confirm' = $memberPassword
        'terms'            = '1'
    }
    $ewalletGuestCount = [int]((Invoke-MySql "SELECT COUNT(*) FROM users WHERE username = '$ewalletGuestName';" | Out-String).Trim())
    Assert-That 'P9-T5' ($ewalletGuest.Status -eq 302 -and $ewalletGuest.Location -match '/login$' -and $ewalletGuestCount -eq 0) `
        "a guest cannot register by e-wallet (got $($ewalletGuest.Status) $($ewalletGuest.Location), rows=$ewalletGuestCount)"

    # P9-T7 - an unknown sponsor is a field error, not an account.
    $sponsorFailName = 'smoke_sponsor_' + (Get-Random -Minimum 100000 -Maximum 999999)
    $p9Users += $sponsorFailName
    $sponsorPost = Invoke-App -Method 'POST' -Path '/register' -Session 'p9c' -Form @{
        '_csrf'            = $badToken
        'mode'             = 'standard'
        'sponsor'          = 'nobody_here'
        'username'         = $sponsorFailName
        'email'            = "$sponsorFailName@example.com"
        'password'         = $memberPassword
        'password_confirm' = $memberPassword
        'terms'            = '1'
    }
    $sponsorCount = [int]((Invoke-MySql "SELECT COUNT(*) FROM users WHERE username = '$sponsorFailName';" | Out-String).Trim())
    Assert-That 'P9-T7' ($sponsorPost.Status -eq 302 -and $sponsorPost.Location -match '/register$' -and $sponsorCount -eq 0) `
        "an unknown sponsor is refused without an account (rows=$sponsorCount)"

    # P9-T8 - an existing username is refused.
    $duplicatePost = Invoke-App -Method 'POST' -Path '/register' -Session 'p9c' -Form @{
        '_csrf'            = $badToken
        'mode'             = 'standard'
        'sponsor'          = 'juan'
        'username'         = $memberUsername
        'email'            = "dup_$memberUsername@example.com"
        'password'         = $memberPassword
        'password_confirm' = $memberPassword
        'terms'            = '1'
    }
    $duplicateCount = [int]((Invoke-MySql "SELECT COUNT(*) FROM users WHERE username = '$memberUsername';" | Out-String).Trim())
    Assert-That 'P9-T8' ($duplicatePost.Status -eq 302 -and $duplicatePost.Location -match '/register$' -and $duplicateCount -eq 1) `
        "a duplicate username is refused (rows=$duplicateCount)"

    # P9-T10 - a manual proof waits for review and the admin approves it.
    $pngPath = Join-Path $script:TempDir 'p9-proof.png'
    [System.IO.File]::WriteAllBytes($pngPath, [Convert]::FromBase64String('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='))

    $membershipPage  = Invoke-App -Path '/membership' -Session 'p9a'
    $membershipToken = Get-Csrf $membershipPage.Body
    $proofReference  = 'SMOKE-P9-' + (Get-Random -Minimum 100000 -Maximum 999999)
    $proofPost = Invoke-App -Method 'POST' -Path '/membership/activate' -Session 'p9a' -Form @{
        '_csrf'        = $membershipToken
        'method'       = 'manual'
        'package_id'   = "$p9PackageAId"
        'channel'      = 'gcash'
        'reference_no' = $proofReference
    } -Files @{ 'proof' = $pngPath }
    $activationId = [int]((Invoke-MySql "SELECT id FROM activations WHERE user_id = $p9PendingId ORDER BY id DESC LIMIT 1;" | Out-String).Trim())
    $activationRow = (Invoke-MySql "SELECT CONCAT(status, '|', payment_method, '|', IF(proof_path IS NULL, 'none', 'stored'), '|', COALESCE(reference_no, '')) FROM activations WHERE id = $activationId;" | Out-String).Trim()
    $queuePage = Invoke-App -Path '/admin/activations' -Session 'admin'
    $queueOk   = ($queuePage.Status -eq 200) -and ($queuePage.Body -match [regex]::Escape($pendingUsername)) -and ($queuePage.Body -match 'Approve')

    $approve = Invoke-App -Method 'POST' -Path "/admin/activations/$activationId/approve" -Session 'admin' -Form @{
        '_csrf' = $codesToken
    }
    $approvedMember = (Invoke-MySql ("SELECT CONCAT(u.member_status, '|', p.slug, '|', IF(u.activated_at IS NULL, 0, 1)) FROM users u JOIN packages p ON p.id = u.package_id WHERE u.id = $p9PendingId;") | Out-String).Trim()
    $approvedRow = (Invoke-MySql "SELECT CONCAT(status, '|', IF(commission_processed_at IS NULL, 0, 1), '|', IF(reviewed_by IS NULL, 0, 1)) FROM activations WHERE id = $activationId;" | Out-String).Trim()
    Assert-That 'P9-T10' ($proofPost.Status -eq 302 -and $activationRow -eq ('pending_review|gcash|stored|' + $proofReference) `
            -and $queueOk -and $approve.Status -eq 302 -and $approvedMember -eq 'active|package-a|1' -and $approvedRow -eq 'approved|1|1') `
        "a manual activation waits for review and approval activates the member (row=$activationRow, member=$approvedMember, activation=$approvedRow)"

    # P9-T12 - approving the same activation again changes nothing.
    $approveAgain = Invoke-App -Method 'POST' -Path "/admin/activations/$activationId/approve" -Session 'admin' -Form @{
        '_csrf' = $codesToken
    }
    $approvedCount = [int]((Invoke-MySql "SELECT COUNT(*) FROM activations WHERE user_id = $p9PendingId AND status = 'approved';" | Out-String).Trim())
    $hookedCount   = [int]((Invoke-MySql "SELECT COUNT(*) FROM activations WHERE user_id = $p9PendingId AND commission_processed_at IS NOT NULL;" | Out-String).Trim())
    Assert-That 'P9-T12' ($approveAgain.Status -eq 302 -and $approvedCount -eq 1 -and $hookedCount -eq 1) `
        "a second approval is refused (approved=$approvedCount, hook stamped=$hookedCount)"

    # P9-T11 - a rejection returns the member to pending with a reason.
    $rejectUsername = 'smoke_reject_' + (Get-Random -Minimum 100000 -Maximum 999999)
    $p9Users += $rejectUsername
    $rejectRegPage  = Invoke-App -Path '/register' -Session 'p9c'
    $rejectRegToken = Get-Csrf $rejectRegPage.Body
    $rejectRegPost  = Invoke-App -Method 'POST' -Path '/register' -Session 'p9c' -Form @{
        '_csrf'            = $rejectRegToken
        'mode'             = 'pending'
        'sponsor'          = 'juan'
        'username'         = $rejectUsername
        'email'            = "$rejectUsername@example.com"
        'password'         = $memberPassword
        'password_confirm' = $memberPassword
        'terms'            = '1'
    }
    $rejectMemberId = [int]((Invoke-MySql "SELECT id FROM users WHERE username = '$rejectUsername';" | Out-String).Trim())
    $rejectMembership = Invoke-App -Path '/membership' -Session 'p9c'
    $rejectToken = Get-Csrf $rejectMembership.Body
    $rejectProof = Invoke-App -Method 'POST' -Path '/membership/activate' -Session 'p9c' -Form @{
        '_csrf'        = $rejectToken
        'method'       = 'manual'
        'package_id'   = "$p9PackageAId"
        'channel'      = 'maya'
        'reference_no' = 'SMOKE-RJ-' + (Get-Random -Minimum 100000 -Maximum 999999)
    } -Files @{ 'proof' = $pngPath }
    $rejectActivationId = [int]((Invoke-MySql "SELECT id FROM activations WHERE user_id = $rejectMemberId ORDER BY id DESC LIMIT 1;" | Out-String).Trim())
    $rejectAction = Invoke-App -Method 'POST' -Path "/admin/activations/$rejectActivationId/reject" -Session 'admin' -Form @{
        '_csrf'  = $codesToken
        'reason' = 'Smoke test rejection'
    }
    $rejectRow = (Invoke-MySql ("SELECT CONCAT(a.status, '|', u.member_status, '|', COALESCE(a.reject_reason, '')) FROM activations a JOIN users u ON u.id = a.user_id WHERE a.id = $rejectActivationId;") | Out-String).Trim()
    Assert-That 'P9-T11' ($rejectRegPost.Status -eq 302 -and $rejectProof.Status -eq 302 -and $rejectAction.Status -eq 302 `
            -and $rejectRow -eq 'rejected|pending|Smoke test rejection') `
        "a rejected activation returns the member to pending with a reason (row=$rejectRow)"

    # P9-T16 - the member detail page, then suspend/restore.
    $detailPage = Invoke-App -Path "/admin/members/$p9PendingId" -Session 'admin'
    $detailOk   = ($detailPage.Status -eq 200) -and ($detailPage.Body -match 'Package A') `
        -and ($detailPage.Body -match '@juan') -and ($detailPage.Body -match 'Wallet') `
        -and ($detailPage.Body -match 'Suspend member')

    $suspend = Invoke-App -Method 'POST' -Path "/admin/members/$p9PendingId/status" -Session 'admin' -Form @{
        '_csrf'   = $codesToken
        'action'  = 'suspend'
        'reason'  = 'Smoke test'
    }
    $suspendedRow = (Invoke-MySql "SELECT CONCAT(member_status, '|', is_active) FROM users WHERE id = $p9PendingId;" | Out-String).Trim()
    $suspendedSession = Invoke-App -Path '/dashboard' -Session 'p9a'
    $blockedLogin     = Sign-In -Session 'p9d' -Username $pendingUsername -Password $memberPassword

    $restore = Invoke-App -Method 'POST' -Path "/admin/members/$p9PendingId/status" -Session 'admin' -Form @{
        '_csrf'  = $codesToken
        'action' = 'restore'
    }
    $restoredRow = (Invoke-MySql "SELECT CONCAT(member_status, '|', is_active) FROM users WHERE id = $p9PendingId;" | Out-String).Trim()
    $restoredLogin = Sign-In -Session 'p9d' -Username $pendingUsername -Password $memberPassword

    Assert-That 'P9-T16' ($detailOk -and $suspend.Status -eq 302 -and $suspendedRow -eq 'suspended|0' `
            -and $suspendedSession.Status -eq 302 -and (-not $blockedLogin) `
            -and $restore.Status -eq 302 -and $restoredRow -eq 'active|1' -and $restoredLogin) `
        "member detail, suspend (sign-in blocked) and restore all work (detail=$detailOk, suspended=$suspendedRow, restored=$restoredRow)"

    # P9-T16 - the tables take a rows-per-page size (5 ... 100, default 10).
    $pagedFive = Invoke-App -Path '/admin/members?per_page=5' -Session 'admin'
    $pagedBad  = Invoke-App -Path '/admin/members?per_page=7' -Session 'admin'
    $pagedRows = [regex]::Matches($pagedFive.Body, 'member-row-avatar').Count
    Assert-That 'P9-T16' ($pagedFive.Status -eq 200 -and $pagedRows -eq 5 `
            -and $pagedFive.Body -match 'name="per_page"' `
            -and $pagedFive.Body -match '<option value="5" selected' `
            -and $pagedFive.Body -match 'Rows per page' `
            -and $pagedBad.Body -match '<option value="10" selected') `
        "the member table pages 5 per page and rejects an unlisted size (rows=$pagedRows, fallback=10)"

    # P9-T15 - a member cannot open the back-office membership screens.
    $memberCodes   = Invoke-App -Path '/admin/codes' -Session 'member'
    $memberMembers = Invoke-App -Path '/admin/members' -Session 'member'
    $memberActs    = Invoke-App -Path '/admin/activations' -Session 'member'
    Assert-That 'P9-T15' ($memberCodes.Status -eq 403 -and $memberMembers.Status -eq 403 -and $memberActs.Status -eq 403) `
        "a member gets 403 on codes/members/activations (got $($memberCodes.Status)/$($memberMembers.Status)/$($memberActs.Status))"

    # P9-T14 - a batch of ten, one expired, the list and the CSV export.
    $genTen = Invoke-App -Method 'POST' -Path '/admin/codes/generate' -Session 'admin' -Form @{
        '_csrf'      = $codesToken
        'package_id' = "$p9PackageAId"
        'quantity'   = '10'
        'price'      = '4498.00'
        'expires_at' = ''
        'code_type'  = 'registration'
    }
    $batchCount = [int]((Invoke-MySql "SELECT COUNT(*) FROM reg_codes WHERE id > $p9CodeStart;" | Out-String).Trim())
    $expireCode = (Invoke-MySql "SELECT code FROM reg_codes WHERE id > $p9CodeStart AND status = 'unused' ORDER BY id ASC LIMIT 1;" | Out-String).Trim()
    Invoke-MySql "UPDATE reg_codes SET expires_at = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE code = '$expireCode';" | Out-Null

    # Validating a past-dated code flips it to `expired` (plan §5.10).
    $expireCheck = Invoke-App -Method 'POST' -Path '/register/validate-code' -Session 'guest' -Form @{
        '_csrf' = $guestRegToken
        'code'  = $expireCode
    }
    $expireJson = $null
    try { $expireJson = $expireCheck.Body | ConvertFrom-Json } catch { }
    $expireStatus = (Invoke-MySql "SELECT status FROM reg_codes WHERE code = '$expireCode';" | Out-String).Trim()
    $expiredFilter = Invoke-App -Path "/admin/codes?status=expired" -Session 'admin'
    $expiredOk     = ($expiredFilter.Status -eq 200) -and ($expiredFilter.Body -match [regex]::Escape($expireCode)) `
        -and ($expireStatus -eq 'expired') -and ($expireJson.valid -eq $false)
    $export = Invoke-App -Path '/admin/codes/export?status=expired' -Session 'admin'
    $exportType = ''
    if ($export.Headers.ContainsKey('content-type')) { $exportType = [string] $export.Headers['content-type'] }
    $exportOk = ($export.Status -eq 200) -and ($exportType -match 'text/csv') `
        -and ($export.Body -match 'Code,Package,Price') -and ($export.Body -match [regex]::Escape($expireCode))
    Assert-That 'P9-T14' ($genTen.Status -eq 302 -and $batchCount -eq 11 -and $expiredOk -and $exportOk) `
        "ten codes are generated, one expires and the CSV export follows the filter (batch=$batchCount, filter=$expiredOk, csv=$exportOk status=$($export.Status) type=$exportType bytes=$($export.Body.Length) expired=$expireCode)"

    # P9-T17 - maria sees her team chain (level 1 direct recruits, level 2 their recruits).
    $mariaLogin = Sign-In -Session 'p9e' -Username 'maria' -Password 'Demo@1234'
    $genealogy  = Invoke-App -Path '/genealogy' -Session 'p9e'
    $genealogyOk = ($genealogy.Status -eq 200) -and ($genealogy.Body -match 'Team chain') `
        -and ($genealogy.Body -match 'peter') -and ($genealogy.Body -match 'Direct recruits') `
        -and ($genealogy.Body -notmatch 'Upline chain') `
        -and ($genealogy.Body -notmatch 'binary_parent|binary_position')
    Assert-That 'P9-T17' ($mariaLogin -and $genealogyOk) `
        "GET /genealogy shows maria's team chain, not her uplines (login=$mariaLogin, page=$($genealogy.Status))"

    # ---- Phase 10: direct referral commissions ---------------------------
    # The Phase 9 flows above already paid juan twice over HTTP: the code
    # registration (P9-T2) and the approved manual activation (P9-T10).
    $p10Credited = [int]((Invoke-MySql "SELECT COUNT(*) FROM commissions WHERE id > $p10CommissionStart AND status = 'credited' AND type = 'direct_referral';" | Out-String).Trim())
    $p10LedgerRows = [int]((Invoke-MySql "SELECT COUNT(*) FROM ewallet_ledger WHERE id > $p10LedgerStart AND ref_type = 'commission';" | Out-String).Trim())
    $p10Linked = [int]((Invoke-MySql "SELECT COUNT(*) FROM commissions c JOIN ewallet_ledger l ON l.id = c.ledger_id WHERE c.id > $p10CommissionStart AND c.status = 'credited' AND l.ref_type = 'commission';" | Out-String).Trim())
    $p10JuanBefore = [double](([string] $p10WalletSnapshot['juan']).Split('|')[0])
    $p10JuanNow    = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'juan';" | Out-String).Trim())
    $p10JuanWithdrawable = [double]((Invoke-MySql "SELECT withdrawable_balance FROM users WHERE username = 'juan';" | Out-String).Trim())

    Assert-That 'P10-T1' ($p10Credited -eq 2 -and $p10LedgerRows -eq 2 -and $p10Linked -eq 2 `
            -and [math]::Abs($p10JuanNow - ($p10JuanBefore + 1000)) -lt 0.001) `
        "two activations credit the sponsor twice, each with its ledger row (awards=$p10Credited, ledger=$p10LedgerRows, linked=$p10Linked, juan=$p10JuanNow was $p10JuanBefore)"
    Assert-That 'P10-T1' ($p10JuanWithdrawable -ge 1000) `
        "the sponsor's commission is withdrawable (withdrawable=$p10JuanWithdrawable)"

    # P10-T2 — a registration sponsored by the company root (active but
    # package-less) records a skip and moves no money.
    $skipGen = Invoke-App -Method 'POST' -Path '/admin/codes/generate' -Session 'admin' -Form @{
        '_csrf'      = $codesToken
        'package_id' = "$p9PackageAId"
        'quantity'   = '1'
        'price'      = '4498.00'
        'expires_at' = ''
        'code_type'  = 'registration'
    }
    $skipCode = (Invoke-MySql "SELECT code FROM reg_codes WHERE id > $p9CodeStart AND status = 'unused' ORDER BY id DESC LIMIT 1;" | Out-String).Trim()
    $skipUsername = 'smoke_skip_' + (Get-Random -Minimum 100000 -Maximum 999999)
    $p9Users += $skipUsername
    $skipRegPage  = Invoke-App -Path '/register' -Session 'p9f'
    $skipRegToken = Get-Csrf $skipRegPage.Body
    $skipPost = Invoke-App -Method 'POST' -Path '/register' -Session 'p9f' -Form @{
        '_csrf'            = $skipRegToken
        'mode'             = 'code'
        'sponsor'          = 'admin'
        'reg_code'         = $skipCode
        'username'         = $skipUsername
        'email'            = "$skipUsername@example.com"
        'password'         = $memberPassword
        'password_confirm' = $memberPassword
        'terms'            = '1'
    }
    $skipRow = (Invoke-MySql ("SELECT CONCAT(c.status, '|', c.amount, '|', CASE WHEN c.ledger_id IS NULL THEN 0 ELSE 1 END, '|', CASE WHEN c.reason IS NULL OR c.reason = '' THEN 0 ELSE 1 END) FROM commissions c JOIN users u ON u.id = c.source_user_id WHERE u.username = '$skipUsername' ORDER BY c.id DESC LIMIT 1;") | Out-String).Trim()
    $skipMember = (Invoke-MySql ("SELECT CONCAT(u.member_status, '|', p.slug) FROM users u JOIN packages p ON p.id = u.package_id WHERE u.username = '$skipUsername';") | Out-String).Trim()
    $p10AdminBefore = [double](([string] $p10WalletSnapshot['admin']).Split('|')[0])
    $adminNow = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'admin';" | Out-String).Trim())
    $juanAfterSkip = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'juan';" | Out-String).Trim())
    Assert-That 'P10-T2' ($skipPost.Status -eq 302 -and $skipRow -eq 'skipped|500.00|0|1' -and $skipMember -eq 'active|package-a' `
            -and [math]::Abs($adminNow - $p10AdminBefore) -lt 0.001 -and [math]::Abs($juanAfterSkip - $p10JuanNow) -lt 0.001) `
        "an ineligible sponsor is skipped, audited and unpaid (row=$skipRow, member=$skipMember, admin=$adminNow was $p10AdminBefore)"

    # P10-T7 — juan's earnings statement shows the awards, the filters and
    # the drawer; a guest cannot open it. (p10a is a fresh session: the P9
    # jars are still signed in as the members they registered.)
    $juanLogin = Sign-In -Session 'p10a' -Username 'juan' -Password 'Demo@1234'
    $earnings  = Invoke-App -Path '/earnings' -Session 'p10a'
    $earningsGuest = Invoke-App -Path '/earnings' -Session 'guest'
    $earningsAdmin = Invoke-App -Path '/earnings' -Session 'admin'
    # The statement totals every credited award, including the two QA
    # awards already on this development database before the run.
    $p10JuanEarningsValue = [double]((Invoke-MySql "SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE user_id = $p9JuanId AND status = 'credited';" | Out-String).Trim())
    $p10JuanEarnings = $p10JuanEarningsValue.ToString('N2', [System.Globalization.CultureInfo]::InvariantCulture)
    $earningsOk = ($earnings.Status -eq 200) `
        -and ($earnings.Body -match 'Total credited') -and ($earnings.Body -match 'Direct referral') `
        -and ($earnings.Body -match 'Indirect referral') -and ($earnings.Body -match [regex]::Escape($p10JuanEarnings)) `
        -and ($earnings.Body -match [regex]::Escape($codeUsername)) `
        -and ($earnings.Body -match 'name="level"') -and ($earnings.Body -match 'data-commission="') `
        -and ($earnings.Body -match 'id="commissionDetail"') -and ($earnings.Body -match 'href="[^"]*/wallet"')
    Assert-That 'P10-T7' ($juanLogin -and $earningsOk -and $earningsGuest.Status -eq 302 `
            -and $earningsGuest.Location -match '/login$' -and $earningsAdmin.Status -eq 200) `
        "GET /earnings shows the awards and the drawer; guest=$($earningsGuest.Status), admin=$($earningsAdmin.Status)"
    Assert-That 'P10-T7' ($earningsAdmin.Body -match 'Back office') `
        "an admin opening /earnings keeps the back-office layout"

    # P10-T8 — the admin ledger lists both sides, filters, and is admin-only.
    $ledgerPage = Invoke-App -Path '/admin/commissions' -Session 'admin'
    $ledgerSkipped = Invoke-App -Path '/admin/commissions?status=skipped' -Session 'admin'
    $ledgerIndirect = Invoke-App -Path '/admin/commissions?type=indirect_referral' -Session 'admin'
    $ledgerMember = Invoke-App -Path '/admin/commissions' -Session 'p10a'
    $ledgerOk = ($ledgerPage.Status -eq 200) `
        -and ($ledgerPage.Body -match 'Commission ledger') -and ($ledgerPage.Body -match 'Total credited') `
        -and ($ledgerPage.Body -match '@juan') -and ($ledgerPage.Body -match [regex]::Escape($codeUsername)) `
        -and ($ledgerPage.Body -match 'data-commission="')
    $ledgerFiltersOk = ($ledgerSkipped.Status -eq 200) -and ($ledgerSkipped.Body -match [regex]::Escape($skipUsername)) `
        -and ($ledgerIndirect.Status -eq 200) -and ($ledgerIndirect.Body -match 'Indirect referral') `
        -and ($ledgerIndirect.Body -match 'Skipped')
    Assert-That 'P10-T8' ($ledgerOk -and $ledgerFiltersOk -and $ledgerMember.Status -eq 403) `
        "GET /admin/commissions lists and filters the ledger; member=$($ledgerMember.Status) (page=$($ledgerPage.Status), skipped=$($ledgerSkipped.Status), indirect=$($ledgerIndirect.Status))"

    # ---- Phase 11: indirect referral commissions --------------------------
    # A code registration under peter walks the demo chain:
    #   peter L1 500, maria L2 100, juan L3 50, admin L4 skipped (no package).
    $p11CodeStart = [int]((Invoke-MySql "SELECT COALESCE(MAX(id), 0) FROM reg_codes;" | Out-String).Trim())
    $p11Gen = Invoke-App -Method 'POST' -Path '/admin/codes/generate' -Session 'admin' -Form @{
        '_csrf'      = $codesToken
        'package_id' = "$p9PackageAId"
        'quantity'   = '1'
        'price'      = '4498.00'
        'expires_at' = ''
        'code_type'  = 'registration'
    }
    $p11Code = (Invoke-MySql "SELECT code FROM reg_codes WHERE id > $p11CodeStart AND status = 'unused' ORDER BY id DESC LIMIT 1;" | Out-String).Trim()
    $p11Username = 'smoke_p11_' + (Get-Random -Minimum 100000 -Maximum 999999)
    $p9Users += $p11Username
    $p11PeterBefore = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'peter';" | Out-String).Trim())
    $p11MariaBefore = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'maria';" | Out-String).Trim())
    $p11JuanBefore  = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'juan';" | Out-String).Trim())
    $p11AdminBefore = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'admin';" | Out-String).Trim())

    $p11RegPage  = Invoke-App -Path '/register' -Session 'p11reg'
    $p11RegToken = Get-Csrf $p11RegPage.Body
    $p11Post = Invoke-App -Method 'POST' -Path '/register' -Session 'p11reg' -Form @{
        '_csrf'            = $p11RegToken
        'mode'             = 'code'
        'sponsor'          = 'peter'
        'reg_code'         = $p11Code
        'username'         = $p11Username
        'email'            = "$p11Username@example.com"
        'password'         = $memberPassword
        'password_confirm' = $memberPassword
        'terms'            = '1'
    }

    $p11Awards = (Invoke-MySql ("SELECT CONCAT(COUNT(*), '|', SUM(status = 'credited'), '|', SUM(status = 'skipped'), '|', SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END)) FROM commissions c JOIN users u ON u.id = c.source_user_id WHERE u.username = '$p11Username';") | Out-String).Trim()
    $p11Levels = (Invoke-MySql ("SELECT GROUP_CONCAT(CONCAT(level, ':', type, ':', status) ORDER BY level SEPARATOR ',') FROM commissions c JOIN users u ON u.id = c.source_user_id WHERE u.username = '$p11Username';") | Out-String).Trim()
    $p11PeterAfter = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'peter';" | Out-String).Trim())
    $p11MariaAfter = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'maria';" | Out-String).Trim())
    $p11JuanAfter  = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'juan';" | Out-String).Trim())
    $p11AdminAfter = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'admin';" | Out-String).Trim())
    Assert-That 'P11-T12' ($p11Gen.Status -eq 302 -and $p11Post.Status -eq 302 -and $p11Awards -eq '4|3|1|650.00' `
            -and $p11Levels -eq '1:direct_referral:credited,2:indirect_referral:credited,3:indirect_referral:credited,4:indirect_referral:skipped') `
        "a code registration pays the full unilevel chain (status=$($p11Post.Status), awards=$p11Awards, levels=$p11Levels)"
    Assert-That 'P11-T8' ([math]::Abs($p11PeterAfter - ($p11PeterBefore + 500)) -lt 0.001 `
            -and [math]::Abs($p11MariaAfter - ($p11MariaBefore + 100)) -lt 0.001 `
            -and [math]::Abs($p11JuanAfter - ($p11JuanBefore + 50)) -lt 0.001 `
            -and [math]::Abs($p11AdminAfter - $p11AdminBefore) -lt 0.001) `
        "wallet credits match the levels: peter +500, maria +100, juan +50, admin skipped"

    # P11-T9 — juan's earnings page now carries the level-3 award and the
    # per-level breakdown; the unilevel CSV exports the same rows.
    $p11Earnings = Invoke-App -Path '/earnings' -Session 'p10a'
    $p11Csv      = Invoke-App -Path '/earnings/export' -Session 'p10a'
    $p11CsvType  = ''
    if ($p11Csv.Headers.ContainsKey('content-type')) { $p11CsvType = [string] $p11Csv.Headers['content-type'] }
    $p11CsvGuest = Invoke-App -Path '/earnings/export' -Session 'guest'
    $p11JuanEarningsValue = [double]((Invoke-MySql "SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE user_id = $p9JuanId AND status = 'credited';" | Out-String).Trim())
    $p11JuanEarnings = $p11JuanEarningsValue.ToString('N2', [System.Globalization.CultureInfo]::InvariantCulture)
    $p11EarningsOk = ($p11Earnings.Status -eq 200) -and ($p11Earnings.Body -match 'Unilevel breakdown') `
        -and ($p11Earnings.Body -match 'Level 3') -and ($p11Earnings.Body -match [regex]::Escape($p11JuanEarnings)) `
        -and ($p11Earnings.Body -match 'Export CSV')
    $p11CsvOk = ($p11Csv.Status -eq 200) -and ($p11CsvType -match 'text/csv') `
        -and ($p11Csv.Body -match 'Award,"Awarded at",Level') -and ($p11Csv.Body -match 'indirect_referral') `
        -and ($p11Csv.Body -match [regex]::Escape($p11Username))
    Assert-That 'P11-T9' ($p11EarningsOk -and $p11CsvOk -and $p11CsvGuest.Status -eq 302 -and $p11CsvGuest.Location -match '/login$') `
        "the earnings breakdown and unilevel CSV show the new L3 award; guest=$($p11CsvGuest.Status)"

    # P11-T10 — the admin ledger filters the indirect rows by type and level,
    # and its CSV export is admin-only.
    $p11Ledger = Invoke-App -Path '/admin/commissions?type=indirect_referral&level=3' -Session 'admin'
    $p11AdminCsv = Invoke-App -Path '/admin/commissions/export?type=indirect_referral' -Session 'admin'
    $p11AdminCsvType = ''
    if ($p11AdminCsv.Headers.ContainsKey('content-type')) { $p11AdminCsvType = [string] $p11AdminCsv.Headers['content-type'] }
    $p11AdminCsvMember = Invoke-App -Path '/admin/commissions/export' -Session 'p10a'
    $p11LedgerOk = ($p11Ledger.Status -eq 200) -and ($p11Ledger.Body -match '@juan') `
        -and ($p11Ledger.Body -match 'Level 3') -and ($p11Ledger.Body -match '50\.00') `
        -and ($p11Ledger.Body -notmatch 'No commissions match these filters')
    $p11AdminCsvOk = ($p11AdminCsv.Status -eq 200) -and ($p11AdminCsvType -match 'text/csv') `
        -and ($p11AdminCsv.Body -match 'Recipient,"Recipient name"') -and ($p11AdminCsv.Body -match 'indirect_referral')
    Assert-That 'P11-T10' ($p11LedgerOk -and $p11AdminCsvOk -and $p11AdminCsvMember.Status -eq 403) `
        "the admin ledger filters indirect/level and exports CSV; member=$($p11AdminCsvMember.Status)"

    # P10-T6 — the finance report covers commissions too, and it is clean.
    $p10ReconcileOutput = & $PhpPath (Join-Path (Split-Path -Parent $PSScriptRoot) 'bin\wallet_reconcile.php') --json 2>&1
    $p10ReconcileExit   = $LASTEXITCODE
    $p10ReconcileJson   = $null
    try { $p10ReconcileJson = ($p10ReconcileOutput | Out-String) | ConvertFrom-Json } catch { }
    Assert-That 'P10-T6' ($p10ReconcileExit -eq 0 -and $p10ReconcileJson.ok -eq $true `
            -and $p10ReconcileJson.commissions.ok -eq $true `
            -and $p10ReconcileJson.commissions.credited_rows -ge 5) `
        "bin/wallet_reconcile.php reconciles wallets and commissions (exit=$p10ReconcileExit, credited=$($p10ReconcileJson.commissions.credited_rows))"

    # ---- Phase 12: e-wallet transfers ------------------------------------
    # juan pays peter 200 with the fee set to 8: juan -208, peter +200 and the
    # primary admin +8, in one transfer row and three ledger rows. The cleanup
    # below deletes the transfer, its legs and restores every counter, so a
    # rerun starts from the same state.
    $p12LedgerStart   = [int]((Invoke-MySql "SELECT COALESCE(MAX(id), 0) FROM ewallet_ledger;" | Out-String).Trim())
    $p12TransferStart = [int]((Invoke-MySql "SELECT COALESCE(MAX(id), 0) FROM ewallet_transfers;" | Out-String).Trim())
    $p12AuditStart    = [int]((Invoke-MySql "SELECT COALESCE(MAX(id), 0) FROM audit_log;" | Out-String).Trim())
    $p12Ran           = $true

    foreach ($key in @('ewallet_transfer_fee', 'ewallet_min_transfer', 'ewallet_transfer_daily_limit', 'ewallet_transfer_weekly_limit')) {
        if (-not $settingsSnapshot.ContainsKey($key)) { $settingsSnapshot[$key] = Get-SettingValue $key }
    }

    foreach ($counterRow in @(Invoke-MySql "SELECT CONCAT(username, '|', ewallet_sent_today, '|', ewallet_sent_this_week) FROM users;")) {
        $counterParts = ([string] $counterRow).Trim().Split('|')
        if ($counterParts.Count -eq 3) {
            $p12CounterSnapshot[$counterParts[0]] = "$($counterParts[1])|$($counterParts[2])"
        }
    }

    Set-SettingValue 'ewallet_transfer_fee' '8'

    $p12JuanBefore  = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'juan';" | Out-String).Trim())
    $p12PeterBefore = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'peter';" | Out-String).Trim())
    $p12AdminBefore = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'admin';" | Out-String).Trim())

    # P12-T1 - the send page, the statement's card and the guest guard.
    $transferPage  = Invoke-App -Path '/wallet/transfer' -Session 'p10a'
    $transferToken = Get-Csrf $transferPage.Body
    $walletPage    = Invoke-App -Path '/wallet' -Session 'p10a'
    $transferGuest = Invoke-App -Path '/wallet/transfer' -Session 'guest'
    $transferPageOk = ($transferPage.Status -eq 200) -and ($transferPage.Body -match 'Send funds') `
        -and ($transferPage.Body -match 'name="recipient"') -and ($transferPage.Body -match 'name="amount"') `
        -and ($transferPage.Body -match 'name="note"') -and ($transferPage.Body -match 'name="password"') `
        -and ($transferPage.Body -match 'Transfer limits') -and ($transferPage.Body -match 'Sent today') `
        -and ($transferPage.Body -match 'Send transfer') -and ($transferPage.Body -match 'Transfer fee')
    $walletCardOk = ($walletPage.Status -eq 200) -and ($walletPage.Body -match 'Send money to another member') `
        -and ($walletPage.Body -match '/wallet/transfer')
    Assert-That 'P12-T1' ($transferPageOk -and $walletCardOk -and $transferGuest.Status -eq 302 `
            -and $transferGuest.Location -match '/login$') `
        "GET /wallet/transfer renders the password-confirmed form and /wallet links it; guest=$($transferGuest.Status)"

    # P12-T6 - a wrong password is refused before anything is written.
    $p12Wrong = Invoke-App -Method 'POST' -Path '/wallet/transfer' -Session 'p10a' -Form @{
        '_csrf'     = $transferToken
        'recipient' = 'peter'
        'amount'    = '200.00'
        'note'      = 'Smoke transfer'
        'password'  = 'not-the-password'
    }
    $p12WrongRows    = [int]((Invoke-MySql "SELECT COUNT(*) FROM ewallet_transfers WHERE id > $p12TransferStart;" | Out-String).Trim())
    $p12WrongPage    = Invoke-App -Path '/wallet/transfer' -Session 'p10a'
    $p12WrongBalance = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'juan';" | Out-String).Trim())
    Assert-That 'P12-T6' ($p12Wrong.Status -eq 302 -and $p12Wrong.Location -match '/wallet/transfer$' `
            -and $p12WrongRows -eq 0 -and ($p12WrongPage.Body -match 'password is not correct') `
            -and [math]::Abs($p12WrongBalance - $p12JuanBefore) -lt 0.005) `
        "a wrong transfer password is refused and writes nothing (rows=$p12WrongRows, balance=$p12WrongBalance)"

    # P12-T2 - the real transfer: fee 8, three ledger legs, the counter moves.
    $p12Post = Invoke-App -Method 'POST' -Path '/wallet/transfer' -Session 'p10a' -Form @{
        '_csrf'     = $transferToken
        'recipient' = 'peter'
        'amount'    = '200.00'
        'note'      = 'Smoke transfer'
        'password'  = 'Demo@1234'
    }
    $p12Row   = (Invoke-MySql "SELECT CONCAT(amount, '|', fee, '|', net_amount, '|', status) FROM ewallet_transfers WHERE id > $p12TransferStart ORDER BY id DESC LIMIT 1;" | Out-String).Trim()
    $p12Legs  = (Invoke-MySql "SELECT CONCAT(COUNT(*), '|', COUNT(CASE WHEN type = 'credit' THEN 1 END), '|', COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0)) FROM ewallet_ledger WHERE id > $p12LedgerStart AND ref_type = 'transfer';" | Out-String).Trim()
    $p12JuanNow  = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'juan';" | Out-String).Trim())
    $p12PeterNow = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'peter';" | Out-String).Trim())
    $p12AdminNow = [double]((Invoke-MySql "SELECT ewallet_balance FROM users WHERE username = 'admin';" | Out-String).Trim())
    $p12Counter  = (Invoke-MySql "SELECT ewallet_sent_today FROM users WHERE username = 'juan';" | Out-String).Trim()
    $p12MoneyOk  = ([math]::Abs($p12JuanNow - ($p12JuanBefore - 208)) -lt 0.005) `
        -and ([math]::Abs($p12PeterNow - ($p12PeterBefore + 200)) -lt 0.005) `
        -and ([math]::Abs($p12AdminNow - ($p12AdminBefore + 8)) -lt 0.005) `
        -and $p12Counter -eq '200.00'
    Assert-That 'P12-T2' ($p12Post.Status -eq 302 -and $p12Post.Location -match '/wallet/transfer$' `
            -and $p12Row -eq '200.00|8.00|200.00|completed' -and $p12Legs -eq '3|2|208.00' -and $p12MoneyOk) `
        "juan sends 200 to peter with a fee: -208/+200/+8 and 3 ledger rows (row=$p12Row, legs=$p12Legs, counter=$p12Counter)"

    # P12-T10 - the admin monitor lists the transfer, and only admins may look.
    $p12Monitor = Invoke-App -Path '/admin/wallets?tab=transfers' -Session 'admin'
    $p12MonitorMember = Invoke-App -Path '/admin/wallets?tab=transfers' -Session 'p10a'
    $p12MonitorOk = ($p12Monitor.Status -eq 200) -and ($p12Monitor.Body -match 'Member-to-member movements') `
        -and ($p12Monitor.Body -match '@juan') -and ($p12Monitor.Body -match '@peter') `
        -and ($p12Monitor.Body -match 'Transfers today') -and ($p12Monitor.Body -match 'name="from"') `
        -and ($p12Monitor.Body -match 'Smoke transfer')
    Assert-That 'P12-T10' ($p12MonitorOk -and $p12MonitorMember.Status -eq 403) `
        "the wallet monitor transfers tab lists the movement; member=$($p12MonitorMember.Status)"

    # P12-T9 - the reset cron from its guarded web entry, twice (idempotent).
    $p12CronDryRaw = Invoke-App -Path '/cron/reset_transfer_limits.php?dry-run=1&json=1'
    $p12CronDry    = $null
    try { $p12CronDry = $p12CronDryRaw.Body | ConvertFrom-Json } catch { }

    $p12CronFirstRaw  = Invoke-App -Path '/cron/reset_transfer_limits.php?json=1'
    $p12CronSecondRaw = Invoke-App -Path '/cron/reset_transfer_limits.php?json=1'
    $p12CronFirst     = $null
    $p12CronSecond    = $null
    try { $p12CronFirst = $p12CronFirstRaw.Body | ConvertFrom-Json } catch { }
    try { $p12CronSecond = $p12CronSecondRaw.Body | ConvertFrom-Json } catch { }
    $p12CounterAfter = (Invoke-MySql "SELECT CONCAT(ewallet_sent_today, '|', ewallet_sent_this_week) FROM users WHERE username = 'juan';" | Out-String).Trim()
    $p12CronOk = $p12CronDryRaw.Status -eq 200 -and $p12CronDry.ok -eq $true -and $p12CronDry.dry_run -eq $true `
        -and $p12CronFirstRaw.Status -eq 200 -and $p12CronFirst.ok -eq $true -and $p12CronFirst.daily_zeroed -ge 1 `
        -and $p12CronSecond.ok -eq $true -and $p12CronSecond.daily_zeroed -eq 0 `
        -and $p12CounterAfter -eq '0.00|0.00'
    Assert-That 'P12-T9' $p12CronOk `
        "the transfer-limit cron zeroes the counters and a second run changes nothing (first=$($p12CronFirst.daily_zeroed), second=$($p12CronSecond.daily_zeroed), juan=$p12CounterAfter)"

    # P12-T4 - an amount the balance cannot cover is refused.
    $p12TooMuch = Invoke-App -Method 'POST' -Path '/wallet/transfer' -Session 'p10a' -Form @{
        '_csrf'     = $transferToken
        'recipient' = 'peter'
        'amount'    = '999999.00'
        'note'      = ''
        'password'  = 'Demo@1234'
    }
    $p12TooMuchPage = Invoke-App -Path '/wallet/transfer' -Session 'p10a'
    $p12TooMuchRows = [int]((Invoke-MySql "SELECT COUNT(*) FROM ewallet_transfers WHERE id > $p12TransferStart;" | Out-String).Trim())
    Assert-That 'P12-T4' ($p12TooMuch.Status -eq 302 -and $p12TooMuchRows -eq 1 `
            -and ($p12TooMuchPage.Body -match 'Insufficient wallet balance')) `
        "a transfer above the balance is refused (rows=$p12TooMuchRows)"

    # P12-T3 - the finance report reconciles the transfer legs too.
    $p12ReconcileOutput = & $PhpPath (Join-Path (Split-Path -Parent $PSScriptRoot) 'bin\wallet_reconcile.php') --json 2>&1
    $p12ReconcileExit   = $LASTEXITCODE
    $p12ReconcileJson   = $null
    try { $p12ReconcileJson = ($p12ReconcileOutput | Out-String) | ConvertFrom-Json } catch { }
    Assert-That 'P12-T3' ($p12ReconcileExit -eq 0 -and $p12ReconcileJson.ok -eq $true `
            -and $p12ReconcileJson.transfers.ok -eq $true -and $p12ReconcileJson.transfers.checked -ge 1) `
        "bin/wallet_reconcile.php reconciles transfers (exit=$p12ReconcileExit, checked=$($p12ReconcileJson.transfers.checked))"

    # Leave the cart clean for the maintenance checks.
    Invoke-MySql "DELETE ci FROM cart_items ci JOIN carts c ON c.id = ci.cart_id WHERE c.user_id IN ($cartMemberId);" | Out-Null

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
    foreach ($key in $settingsSnapshot.Keys) {
        try { Set-SettingValue $key ([string] $settingsSnapshot[$key]) } catch { }
    }
    try { if ($cartMemberId -gt 0) { Invoke-MySql "DELETE FROM shop_orders WHERE user_id = $cartMemberId;" | Out-Null } } catch { }
    try { if ($selfOrderId -gt 0) { Invoke-MySql "DELETE FROM shop_orders WHERE id = $selfOrderId;" | Out-Null } } catch { }
    try {
        # The orders and activations are gone, so any proof file without a
        # row is an orphan this run (or an earlier aborted run) left behind.
        $knownProofs = @{}
        foreach ($relative in @(Invoke-MySql "SELECT file_path FROM shop_payment_proofs;")) {
            $knownProofs[([string] $relative).Trim()] = $true
        }
        foreach ($relative in @(Invoke-MySql "SELECT proof_path FROM activations WHERE proof_path IS NOT NULL;")) {
            $knownProofs[([string] $relative).Trim()] = $true
        }
        $proofDir = Join-Path (Split-Path -Parent $PSScriptRoot) 'public\uploads\proofs'
        Get-ChildItem -Path $proofDir -File -ErrorAction SilentlyContinue | ForEach-Object {
            if ($_.Name -ne '.gitkeep' -and -not $knownProofs.ContainsKey('uploads/proofs/' + $_.Name)) {
                Remove-Item $_.FullName -Force -ErrorAction SilentlyContinue
            }
        }
    } catch { }
    try { if ($cartMemberId -gt 0) { Invoke-MySql "DELETE FROM audit_log WHERE action = 'wallet.topup' AND subject_id = $cartMemberId;" | Out-Null } } catch { }
    # Phase 9 fixtures: member accounts first (activations cascade), then codes.
    foreach ($p9Name in $p9Users) {
        try { Invoke-MySql "DELETE FROM users WHERE username = '$p9Name';" | Out-Null } catch { }
    }
    try { if ($p9CodeStart -gt 0) { Invoke-MySql "DELETE FROM reg_codes WHERE id > $p9CodeStart;" | Out-Null } } catch { }
    # Phase 10: the awards this run paid to demo members, their ledger rows and
    # the balances they moved. Without this every smoke run would inflate juan's
    # wallet by 1,000.
    try {
        Invoke-MySql "DELETE FROM commissions WHERE id > $p10CommissionStart;" | Out-Null
        Invoke-MySql "DELETE FROM ewallet_ledger WHERE id > $p10LedgerStart AND ref_type = 'commission';" | Out-Null

        foreach ($walletName in $p10WalletSnapshot.Keys) {
            $walletParts = ([string] $p10WalletSnapshot[$walletName]).Split('|')
            if ($walletParts.Count -eq 2) {
                Invoke-MySql "UPDATE users SET ewallet_balance = $($walletParts[0]), withdrawable_balance = $($walletParts[1]) WHERE username = '$walletName';" | Out-Null
            }
        }
    } catch { }
    try { Invoke-MySql "DELETE FROM users WHERE username = '$memberUsername';" | Out-Null } catch { }
    try { Invoke-MySql "DELETE FROM users WHERE username = '$otherUsername';" | Out-Null } catch { }
    # Phase 12: the transfer, its ledger legs, its audit rows, and every limit
    # counter the reset cron zeroed during the run. The flag (not the id) decides
    # whether the block ran: an empty table starts at id 0.
    try {
        if ($p12Ran) {
            Invoke-MySql "DELETE FROM ewallet_ledger WHERE ref_type = 'transfer' AND id > $p12LedgerStart;" | Out-Null
            Invoke-MySql "DELETE FROM ewallet_transfers WHERE id > $p12TransferStart;" | Out-Null
        }
        if ($p12AuditStart -gt 0) {
            Invoke-MySql "DELETE FROM audit_log WHERE id > $p12AuditStart AND action = 'wallet.transfer';" | Out-Null
        }
        foreach ($counterName in $p12CounterSnapshot.Keys) {
            $counterParts = ([string] $p12CounterSnapshot[$counterName]).Split('|')
            if ($counterParts.Count -eq 2) {
                Invoke-MySql "UPDATE users SET ewallet_sent_today = $($counterParts[0]), ewallet_sent_this_week = $($counterParts[1]) WHERE username = '$counterName';" | Out-Null
            }
        }
    } catch { }
    try { Invoke-MySql "DELETE ci FROM cart_items ci JOIN products p ON p.id = ci.product_id WHERE p.sku LIKE 'SMOKE-%';" | Out-Null } catch { }
    try { Invoke-MySql "DELETE FROM products WHERE sku LIKE 'SMOKE-%';" | Out-Null } catch { }
    try { Invoke-MySql "DELETE FROM packages WHERE name LIKE 'Smoke Package%';" | Out-Null } catch { }
    Remove-Item $script:TempDir -Recurse -Force -ErrorAction SilentlyContinue
}

Write-Host ''
Write-Host ("Passed: {0}   Failed: {1}" -f $script:Passed, $script:Failures) -ForegroundColor Cyan
Write-Host ''

if ($script:Failures -eq 0) { exit 0 } else { exit 1 }
