$ErrorActionPreference = "Stop"

$repo = Get-Location
$jsPath = Join-Path $repo "assets/js/maps-leaflet.js"
$htmlPath = Join-Path $repo "maps-leaflet.html"

if (!(Test-Path $jsPath) -or !(Test-Path $htmlPath)) {
    throw "Run this script from the root of reybitgo/sneat."
}

$js = Get-Content $jsPath -Raw
$html = Get-Content $htmlPath -Raw

# The custom Leaflet icons referenced by maps-leaflet.js are missing from the
# repository. Replace the broken PNG references with the local SVG assets.
$js = $js.Replace("leaf-green.png", "leaf-green.svg")
$js = $js.Replace("leaf-red.png", "leaf-red.svg")
$js = $js.Replace("leaf-orange.png", "leaf-orange.svg")
$js = $js.Replace("leaf-shadow.png", "leaf-shadow.svg")

# Leaflet 1.9.4's default L.Icon.Default expects:
#   assets/vendor/libs/leaflet/images/marker-icon.png
#   assets/vendor/libs/leaflet/images/marker-icon-2x.png
#   assets/vendor/libs/leaflet/images/marker-shadow.png
# Those files are absent in this repository. Set a local SVG icon before
# maps-leaflet.js initializes any L.marker() instances.
$leafletTag = '    <script src="assets/vendor/libs/leaflet/leaflet.js"></script>'

$markerOverride = @'
    <script>
      // Local Leaflet marker assets. The bundled Leaflet distribution does not
      // include its expected images/ directory in this repository.
      (function () {
        if (!window.L || !window.L.Marker) return;

        var mapAssetsPath =
          document.documentElement.getAttribute("data-assets-path") || "assets/";

        var defaultMarkerIcon = L.icon({
          iconUrl:
            mapAssetsPath + "img/icons/misc/leaflet-marker-icon.svg",
          iconRetinaUrl:
            mapAssetsPath + "img/icons/misc/leaflet-marker-icon.svg",
          shadowUrl:
            mapAssetsPath + "img/icons/misc/leaflet-marker-shadow.svg",
          iconSize: [25, 41],
          iconAnchor: [12, 41],
          popupAnchor: [1, -34],
          tooltipAnchor: [16, -28],
          shadowSize: [41, 41]
        });

        L.Marker.prototype.options.icon = defaultMarkerIcon;
      })();
    </script>
'@

if ($html -notmatch "leaflet-marker-icon\.svg") {
    if (!$html.Contains($leafletTag)) {
        throw "Could not find the Leaflet script tag in maps-leaflet.html."
    }
    $html = $html.Replace($leafletTag, $leafletTag + "`r`n" + $markerOverride)
}

Set-Content -Path $jsPath -Value $js -Encoding UTF8
Set-Content -Path $htmlPath -Value $html -Encoding UTF8

Write-Host "Leaflet marker references fixed."
Write-Host "Added local SVG assets under assets/img/icons/misc/."
