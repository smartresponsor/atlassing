$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$prompt = Get-ChildItem -Path (Join-Path $root 'var\atlas\generated') -Recurse -File |
    Where-Object { $_.Name -match 'accessing' -and $_.Extension -in @('.txt','.md','.prompt') } |
    Sort-Object LastWriteTimeUtc -Descending |
    Select-Object -First 1
if (-not $prompt) { throw 'No generated Accessing prompt file found.' }
Write-Host ('prompt=' + $prompt.FullName)
& (Join-Path $root 'tool\atlas-console-mcp-score-cli.ps1') `
    -Component 'accessing' `
    -Workspace 'D:\PhpstormProjects\www\Accessing' `
    -PromptFile $prompt.FullName
exit $LASTEXITCODE
