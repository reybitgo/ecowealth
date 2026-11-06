<#
.SYNOPSIS
    EcoWealth HTTP smoke test (Phase 1-7: P1-T1 ... P1-T11, P2-T1 ... P2-T10,
    P3-T1 ... P3-T8, P4-T1 ... P4-T13, P5-T7 / P5-T9 / P5-T11,
    P6-T1 ... P6-T11, P7-T1 ... P7-T12).

.DESCRIPTION
    Hits the running app through Apache (or `php -S`) and checks status
    codes, redirects, guards, menu visibility, asset base paths,
    maintenance mode, the product catalogue, the package matrix, the cart,
    the e-wallet, checkout/orders and the manual payment loop end to end,
    including the expiry job from both the CLI and its web entry.
    Everything the script changes is restored in a finally block: a
    throw-away member account, products with SKU prefix SMOKE-,
    packages named "Smoke Package …", `maintenance_mode`, the payment
    settings it edits, the smoke proof files and its orders.

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
Write-Host 'EcoWealth HTTP smoke test (phase 1 + 2 + 3 + 4 + 5 + 6 + 7)' -ForegroundColor Cyan
Write-Host "  app:      $base"
Write-Host "  database: $DbName"
Write-Host ''

$memberUsername    = 'smoke_' + (Get-Random -Minimum 100000 -Maximum 999999)
$otherUsername     = 'smoke_other_' + (Get-Random -Minimum 100000 -Maximum 999999)
$memberPassword    = 'Smoke@12345'
$maintenanceBefore = '0'
$selfOrderId       = 0
$settingsSnapshot  = @{}

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
        'username'         = $otherUsername
        'email'            = "$otherUsername@example.com"
        'password'         = $memberPassword
        'confirm-password' = $memberPassword
        'terms'            = '1'
    } | Out-Null
    $otherSignedIn = Sign-In -Session 'other' -Username $otherUsername -Password $memberPassword

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

    # P7-T4 - the settings page saves the payment windows and validates them.
    $settingsBefore = Get-SettingValue 'shop_payment_deadline_hours'
    $settingsPage   = Invoke-App -Path '/admin/settings' -Session 'admin'
    $settingsToken  = Get-Csrf $settingsPage.Body

    foreach ($key in @(
            'shop_enabled', 'shop_expiry_enabled', 'payment_gcash_number', 'payment_gcash_name',
            'payment_maya_number', 'payment_maya_name', 'payment_bank_name',
            'payment_bank_account_name', 'payment_bank_account_number',
            'shop_payment_deadline_hours', 'shop_correction_window_hours',
            'shop_max_proof_attempts')) {
        $settingsSnapshot[$key] = Get-SettingValue $key
    }

    $saveSettings = Invoke-App -Method 'POST' -Path '/admin/settings' -Session 'admin' -Form @{
        '_csrf'                         = $settingsToken
        'shop_enabled'                  = '1'
        'shop_expiry_enabled'           = '1'
        'payment_gcash_number'          = '0917 123 4567'
        'payment_gcash_name'            = 'EcoWealth Smoke'
        'payment_maya_number'           = '0917 765 4321'
        'payment_maya_name'             = 'EcoWealth Smoke'
        'payment_bank_name'             = 'BDO'
        'payment_bank_account_name'     = 'EcoWealth Smoke Inc.'
        'payment_bank_account_number'   = '1234 5678 9012'
        'shop_payment_deadline_hours'   = '30'
        'shop_correction_window_hours'  = '12'
        'shop_max_proof_attempts'       = '4'
    }
    $settingsSaved = (Invoke-MySql "SELECT CONCAT(value, '|') FROM settings WHERE key_name = 'shop_payment_deadline_hours';" | Out-String).Trim()
    $attemptsSaved = Get-SettingValue 'shop_max_proof_attempts'
    $gcashSaved    = Get-SettingValue 'payment_gcash_number'
    $expirySaved   = Get-SettingValue 'shop_expiry_enabled'

    Assert-That 'P7-T4' ($settingsPage.Status -eq 200 -and $settingsPage.Body -match 'Receiving accounts' `
            -and $settingsPage.Body -match 'name="shop_payment_deadline_hours"' `
            -and $settingsPage.Body -match 'name="shop_expiry_enabled"' `
            -and $saveSettings.Status -eq 302 -and $settingsSaved -eq '30|' `
            -and $attemptsSaved -eq '4' -and $gcashSaved -eq '0917 123 4567' -and $expirySaved -eq '1') `
        "POST /admin/settings saves the accounts, windows and expiry switch (deadline=$settingsSaved, attempts=$attemptsSaved, expiry=$expirySaved)"

    $settingsAfter   = Invoke-App -Path '/admin/settings' -Session 'admin'
    $badSettings     = Invoke-App -Method 'POST' -Path '/admin/settings' -Session 'admin' -Form @{
        '_csrf'                       = (Get-Csrf $settingsAfter.Body)
        'shop_enabled'                = '1'
        'shop_expiry_enabled'         = '1'
        'shop_payment_deadline_hours' = '0'
        'shop_correction_window_hours' = '12'
        'shop_max_proof_attempts'     = '4'
    }
    $settingsKept = Get-SettingValue 'shop_payment_deadline_hours'
    $settingsErrorPage = Invoke-App -Path '/admin/settings' -Session 'admin'

    Assert-That 'P7-T4' ($badSettings.Status -eq 302 -and $settingsKept -eq '30' `
            -and $settingsErrorPage.Body -match 'must be at least 1') `
        "an invalid window is refused and keeps the saved value (deadline=$settingsKept)"

    $memberSettings = Invoke-App -Path '/admin/settings' -Session 'member'
    Assert-That 'P7-T4' ($memberSettings.Status -eq 403) `
        "a member cannot open /admin/settings (got $($memberSettings.Status))"

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
        # The orders are gone, so any proof file without a row is an orphan
        # this run (or an earlier aborted run) left behind.
        $knownProofs = @{}
        foreach ($relative in @(Invoke-MySql "SELECT file_path FROM shop_payment_proofs;")) {
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
    try { Invoke-MySql "DELETE FROM users WHERE username = '$memberUsername';" | Out-Null } catch { }
    try { Invoke-MySql "DELETE FROM users WHERE username = '$otherUsername';" | Out-Null } catch { }
    try { Invoke-MySql "DELETE ci FROM cart_items ci JOIN products p ON p.id = ci.product_id WHERE p.sku LIKE 'SMOKE-%';" | Out-Null } catch { }
    try { Invoke-MySql "DELETE FROM products WHERE sku LIKE 'SMOKE-%';" | Out-Null } catch { }
    try { Invoke-MySql "DELETE FROM packages WHERE name LIKE 'Smoke Package%';" | Out-Null } catch { }
    Remove-Item $script:TempDir -Recurse -Force -ErrorAction SilentlyContinue
}

Write-Host ''
Write-Host ("Passed: {0}   Failed: {1}" -f $script:Passed, $script:Failures) -ForegroundColor Cyan
Write-Host ''

if ($script:Failures -eq 0) { exit 0 } else { exit 1 }
