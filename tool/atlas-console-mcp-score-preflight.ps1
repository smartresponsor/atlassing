$ErrorActionPreference = 'Continue'
$repoRoot = Split-Path -Parent $PSScriptRoot
$consoleRoot = Join-Path (Split-Path -Parent $repoRoot) 'mcp\console-mcp'
$scoreCli = Join-Path $PSScriptRoot 'atlas-console-mcp-score.mjs'
& node $scoreCli --preflight=1 "--console-root=$consoleRoot"
$code = $LASTEXITCODE
Write-Host "atlas-console-mcp-score preflight exit=$code"
exit $code
