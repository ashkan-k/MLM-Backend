$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

$env:FINOPAL_WS_PORT = if ($env:FINOPAL_WS_PORT) { $env:FINOPAL_WS_PORT } else { "6001" }
$env:FINOPAL_WS_SECRET = if ($env:FINOPAL_WS_SECRET) { $env:FINOPAL_WS_SECRET } else { "abba6c683f4a9422cca1724c5cd8d535" }
$env:FINOPAL_API_URL = if ($env:FINOPAL_API_URL) { $env:FINOPAL_API_URL } else { "https://bamiz.ir" }

if (-not (Test-Path "node_modules\ws")) {
  npm install ws --omit=dev
}

Write-Host "Starting Finopal WS on :$($env:FINOPAL_WS_PORT) -> API $($env:FINOPAL_API_URL)"
node realtime/ws-server.mjs
