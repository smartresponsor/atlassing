param(
    [ValidateSet('chatgpt-cli', 'responses', 'dry-run')]
    [string]$Mode = 'dry-run',
    [switch]$SelectionOnly,
    [switch]$NoPublish,
    [switch]$PreflightOnly
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$repoRoot = Split-Path -Parent $PSScriptRoot
$selectionPlan = Join-Path $repoRoot 'var\atlas\generated\selection-plan.json'
$logDir = Join-Path $repoRoot 'var\log\atlas'
$logFile = Join-Path $logDir 'scheduled-assessment.log'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null

function Write-AtlasLog([string]$Message) {
    $line = ('{0:o} {1}' -f [DateTimeOffset]::Now, $Message)
    Add-Content -Path $logFile -Value $line
    Write-Host $line
}

Push-Location $repoRoot
try {
    Write-AtlasLog "scheduled run started mode=$Mode"

    if ($PreflightOnly) {
        $apiKeyPresent = -not [string]::IsNullOrWhiteSpace($env:OPENAI_API_KEY)
        & git remote get-url origin *> $null
        $originPresent = ($LASTEXITCODE -eq 0)
        & py -3 -c 'import yaml' *> $null
        $pyYamlPresent = ($LASTEXITCODE -eq 0)
        $consoleMcpRoot = Join-Path (Split-Path -Parent $repoRoot) 'mcp\console-mcp'
        $consoleMcpDevConsole = Join-Path $consoleMcpRoot 'tool\dev-console.ps1'
        $consoleMcpPresent = Test-Path -LiteralPath $consoleMcpDevConsole -PathType Leaf
        $scoreCli = Join-Path $repoRoot 'tool\atlas-console-mcp-score-cli.ps1'
        $highLevelRawScoringCliReady = $false
        $consoleMcpSystemStatus = $null
        $consoleMcpSystemReason = $null
        $scorePreflightDiagnostic = $null
        if ($Mode -eq 'chatgpt-cli' -and $consoleMcpPresent -and (Test-Path -LiteralPath $scoreCli -PathType Leaf)) {
            try {
                $scorePreflightRaw = & pwsh -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $scoreCli -Preflight 2>&1
                $scorePreflightText = ($scorePreflightRaw | Out-String).Trim()
                try {
                    $scorePreflight = $scorePreflightText | ConvertFrom-Json
                    $highLevelRawScoringCliReady = ($scorePreflight.ok -eq $true)
                    $consoleMcpSystemStatus = [string]$scorePreflight.status
                    $consoleMcpSystemReason = [string]$scorePreflight.reason
                } catch {
                    $scorePreflightDiagnostic = $scorePreflightText
                }
            } catch {
                $highLevelRawScoringCliReady = $false
                $scorePreflightDiagnostic = $_.Exception.Message
            }
        }
        [pscustomobject]@{
            OpenAiApiKeyPresent = $apiKeyPresent
            OriginPresent = $originPresent
            PyYamlPresent = $pyYamlPresent
            ConsoleMcpPresent = $consoleMcpPresent
            ConsoleMcpSystemStatus = $consoleMcpSystemStatus
            ConsoleMcpSystemReason = $consoleMcpSystemReason
            HighLevelRawScoringCliReady = $highLevelRawScoringCliReady
            RawScoringPreflightDiagnostic = $scorePreflightDiagnostic
            Mode = $Mode
        } | Format-List
        if ($Mode -eq 'responses' -and -not $apiKeyPresent) { exit 21 }
        if ($Mode -eq 'chatgpt-cli' -and -not $highLevelRawScoringCliReady) {
            Write-AtlasLog 'chatgpt-cli Console MCP system-ready preflight failed; local task-bank scoring dispatch is paused'
            exit 24
        }
        if (-not $originPresent) { exit 22 }
        if (-not $pyYamlPresent) { exit 23 }
        exit 0
    }

    & php bin/console atlas:assessment:select --event-name schedule --output $selectionPlan
    if ($LASTEXITCODE -ne 0) {
        Write-AtlasLog "selection failed exit=$LASTEXITCODE"
        exit 20
    }

    $plan = Get-Content -Raw -Path $selectionPlan | ConvertFrom-Json
    $selected = @($plan.selected_components)
    Write-AtlasLog ("selected components=" + ($selected -join ','))

    if ($SelectionOnly) {
        $plan | ConvertTo-Json -Depth 8
        exit 0
    }

    if ($selected.Count -eq 0) {
        Write-AtlasLog 'no repositories selected; nothing to assess'
        exit 0
    }

    if ($Mode -eq 'responses' -and [string]::IsNullOrWhiteSpace($env:OPENAI_API_KEY)) {
        Write-AtlasLog 'OPENAI_API_KEY is missing; refusing to silently downgrade a scored scheduled run to dry-run'
        exit 21
    }

    if (-not $NoPublish) {
        & git remote get-url origin *> $null
        if ($LASTEXITCODE -ne 0) {
            Write-AtlasLog 'Git origin is not configured; refusing to spend assessment resources on an unpublished scheduled run'
            exit 22
        }
    }

    $arguments = @('bin/console', 'atlas:assessment:run', '--mode', $Mode, '--selection-plan', $selectionPlan)
    foreach ($component in $selected) {
        $arguments += @('--component', [string]$component)
    }

    & php @arguments
    $assessmentExit = $LASTEXITCODE
    if ($assessmentExit -ne 0) {
        Write-AtlasLog "assessment completed with one or more component failures exit=$assessmentExit; successful component artifacts will still be published"
    }

    if ($NoPublish) {
        if ($assessmentExit -ne 0) {
            Write-AtlasLog 'assessment completed with publication disabled and one or more component failures'
            exit 30
        }
        Write-AtlasLog 'assessment completed with publication disabled'
        exit 0
    }

    & git add -f -- var/atlas
    if ($LASTEXITCODE -ne 0) {
        Write-AtlasLog "git add failed exit=$LASTEXITCODE"
        exit 40
    }

    & git diff --cached --quiet -- var/atlas
    if ($LASTEXITCODE -eq 0) {
        Write-AtlasLog 'assessment completed; no Atlas state changes to publish'
        exit 0
    }
    if ($LASTEXITCODE -ne 1) {
        Write-AtlasLog "git diff failed exit=$LASTEXITCODE"
        exit 41
    }

    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm'
    & git commit -m "chore(atlas): refresh local assessment snapshots $stamp"
    if ($LASTEXITCODE -ne 0) {
        Write-AtlasLog "git commit failed exit=$LASTEXITCODE"
        exit 42
    }

    $upstream = (& git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>$null).Trim()
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($upstream)) {
        Add-Content -Path $logFile -Value "$(Get-Date -Format o) git push skipped: current branch has no upstream"
        exit 43
    }
    $upstreamParts = $upstream -split '/', 2
    if ($upstreamParts.Count -ne 2 -or $upstreamParts[0] -ne 'origin') {
        Add-Content -Path $logFile -Value "$(Get-Date -Format o) git push skipped: unsupported upstream=$upstream"
        exit 43
    }
    & git push origin "HEAD:$($upstreamParts[1])"
    if ($LASTEXITCODE -ne 0) {
        Write-AtlasLog "git push failed exit=$LASTEXITCODE"
        exit 43
    }

    Write-AtlasLog ("scheduled run published components=" + ($selected -join ','))
    if ($assessmentExit -ne 0) {
        Write-AtlasLog 'scheduled run published successful component artifacts but completed with one or more component failures'
        exit 30
    }
    exit 0
}
finally {
    Pop-Location
}
