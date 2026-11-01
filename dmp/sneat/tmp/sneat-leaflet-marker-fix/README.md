# sneat Leaflet marker fix

## Root cause

`assets/vendor/libs/leaflet/leaflet.css` expects Leaflet marker images under:

`assets/vendor/libs/leaflet/images/`

That directory is not present in the repository. Leaflet 1.9.4 therefore creates marker `<img>` elements whose `src` points at missing PNG files.

The Custom Icons section has a second missing-asset problem: `assets/js/maps-leaflet.js` references `leaf-green.png`, `leaf-red.png`, `leaf-orange.png`, and `leaf-shadow.png`, but those files are also absent.

## Fix

This patch:

1. Adds local SVG marker/shadow assets.
2. Changes the Custom Icons references from `.png` to `.svg`.
3. Sets `L.Marker.prototype.options.icon` to a local SVG icon after Leaflet loads and before `maps-leaflet.js` runs.
4. Leaves Leaflet itself untouched.

## Apply

From the repository root in PowerShell:

```powershell
Expand-Archive .\sneat-leaflet-marker-fix.zip -DestinationPath .\leaflet-fix -Force
Copy-Item .\leaflet-fix\assets .\assets -Recurse -Force
powershell -ExecutionPolicy Bypass -File .\leaflet-fix\apply-leaflet-marker-fix.ps1
```

Or simply copy the SVG files into their corresponding `assets/img/icons/misc/` locations and make the two source-file changes described above.

Then hard-refresh `maps-leaflet.html` with Ctrl+F5.
