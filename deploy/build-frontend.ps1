<#
.SYNOPSIS
  Checks and builds the web app for production and zips it, ready to upload to
  Hostinger (see docs/DEPLOY-HOSTINGER.md, step 9).

.EXAMPLE
  .\deploy\build-frontend.ps1

  Produces deploy\out\web-app.zip. Extract it into the backend's public folder
  on the server (the loans subdomain's folder), next to index.php.
#>
$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$frontend = Join-Path $root 'frontend'
$out = Join-Path $PSScriptRoot 'out'
New-Item -ItemType Directory -Force $out | Out-Null

# The app and API share one address in production, so API calls stay relative.
# Clear any local override so the build never points at a development server.
Remove-Item Env:\VITE_API_BASE_URL -ErrorAction SilentlyContinue

Push-Location $frontend
try {
  Write-Host "`n=== Installing dependencies ===" -ForegroundColor Cyan
  npm install --no-audit --no-fund
  if ($LASTEXITCODE -ne 0) { throw 'npm install failed' }

  # Types and lint first: nothing ships that fails them.
  Write-Host "`n=== Type-checking and linting ===" -ForegroundColor Cyan
  npx tsc -b
  if ($LASTEXITCODE -ne 0) { throw 'TypeScript errors. Fix them, then build again.' }
  npx eslint src
  if ($LASTEXITCODE -ne 0) { throw 'Lint errors. Fix them, then build again.' }

  Write-Host "`n=== Building ===" -ForegroundColor Cyan
  npm run build
  if ($LASTEXITCODE -ne 0) { throw 'npm run build failed' }
}
finally {
  Pop-Location
}

$dist = Join-Path $frontend 'dist'
if (-not (Test-Path (Join-Path $dist 'index.html'))) { throw 'frontend\dist\index.html is missing after the build.' }
if (Select-String -Path (Join-Path $dist 'assets\*.js') -Pattern 'Demo-Password|demo\.test' -Quiet) {
  throw 'The build contains demo credentials. Remove them from the frontend source before deploying.'
}

$zip = Join-Path $out 'web-app.zip'
if (Test-Path $zip) { Remove-Item $zip -Force }
# Zip the CONTENTS of dist (index.html at the top level). Windows' tar is used
# because Compress-Archive in Windows PowerShell 5 writes backslash paths that
# Linux extracts as oddly named files.
$items = Get-ChildItem -Force $dist | ForEach-Object Name
$tar = Join-Path $env:SystemRoot 'System32\tar.exe'
& $tar -a -c -f $zip -C $dist @items
if ($LASTEXITCODE -ne 0) { throw "Could not create $zip" }

Write-Host "`nCreated $zip" -ForegroundColor Green
Write-Host 'Upload it to the loans subdomain folder on Hostinger (it is Laravel''s public folder) and Extract there.'
Write-Host 'index.html and the assets folder must sit next to index.php.'
