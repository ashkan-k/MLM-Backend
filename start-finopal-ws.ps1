$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

$env:FINOPAL_WS_PORT = if ($env:FINOPAL_WS_PORT) { $env:FINOPAL_WS_PORT } else { "6001" }
$env:FINOPAL_WS_SECRET = if ($env:FINOPAL_WS_SECRET) { $env:FINOPAL_WS_SECRET } else { "finopal-ws-secret" }
if (-not $env:FINOPAL_API_URL) {
  if (Test-Path .env) {
    $appUrl = (Select-String -Path .env -Pattern '^APP_URL=(.+)$' | Select-Object -First 1)?.Matches.Groups[1].Value
    if ($appUrl) { $env:FINOPAL_API_URL = $appUrl.Trim() }
  }
  if (-not $env:FINOPAL_API_URL) { $env:FINOPAL_API_URL = "https://finonet.ir" }
}

if (-not (Test-Path "node_modules\ws")) {
  npm install ws --omit=dev
}

Write-Host "Starting Finopal WS on :$($env:FINOPAL_WS_PORT) -> API $($env:FINOPAL_API_URL)"
node realtime/ws-server.mjs
