<#
.SYNOPSIS
    EcoWealth HTTP smoke test (Phase 1 + 2 + 3: P1-T1 ... P1-T11, P2-T1 ... P2-T10, P3-T1 ... P3-T8).

.DESCRIPTION
    Hits the running app through Apache (or `php -S`) and checks status
    codes, redirects, guards, menu visibility, asset base paths,
    maintenance mode, the product catalogue and the package matrix end to
    end. Everything the script changes is restored in a finally block: a
    throw-away member account, products with SKU prefix SMOKE-, packages
    named "Smoke Package …" and `maintenance_mode`.

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
Write-Host 'EcoWealth HTTP smoke test (phase 1 + 2 + 3)' -ForegroundColor Cyan
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
    Assert-That 'P1-T3' ($adminPage.Status -eq 200 -and $adminPage.Body -match 'Back office' -and $adminPage.Body -match 'Products') `
        "GET /admin as admin -> 200 with the back-office menu (got $($adminPage.Status))"

    $memberAsAdmin = Invoke-App -Path '/shop' -Session 'admin'
    Assert-That 'P1-T8' ($memberAsAdmin.Status -eq 200 -and $memberAsAdmin.Body -notmatch 'E-wallet monitor') `
        "member portal does not leak admin items (got $($memberAsAdmin.Status))"

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
    $cards1 = [regex]::Matches($page1.Body, 'class="card h-100 product-card"').Count

    Assert-That 'P2-T8' ($page1.Status -eq 200 -and $cards1 -eq 12 -and $page2.Status -eq 200 `
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
    try { Invoke-MySql "DELETE FROM products WHERE sku LIKE 'SMOKE-%';" | Out-Null } catch { }
    try { Invoke-MySql "DELETE FROM packages WHERE name LIKE 'Smoke Package%';" | Out-Null } catch { }
    Remove-Item $script:TempDir -Recurse -Force -ErrorAction SilentlyContinue
}

Write-Host ''
Write-Host ("Passed: {0}   Failed: {1}" -f $script:Passed, $script:Failures) -ForegroundColor Cyan
Write-Host ''

if ($script:Failures -eq 0) { exit 0 } else { exit 1 }
