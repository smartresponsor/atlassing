[CmdletBinding()]
param(
    [string]$Component,
    [string]$Workspace,
    [string]$PromptFile,
    [switch]$Preflight
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$repoRoot = Split-Path -Parent $PSScriptRoot
$workspaceRoot = Split-Path -Parent $repoRoot
$bridge = Join-Path $workspaceRoot 'mcp\chatgpt-loop\tool\runner-console-mcp-bridge.ps1'
$runtimeDir = Join-Path $repoRoot 'var\atlas\generated\chatgpt-cli\bridge'
New-Item -ItemType Directory -Force -Path $runtimeDir | Out-Null

if (-not (Test-Path -LiteralPath $bridge -PathType Leaf)) {
    throw "Console MCP bridge not found: $bridge"
}

$secretRuntime = Join-Path $workspaceRoot 'mcp\AwsSecretContract\tool\secret-runtime.ps1'
$nodeScorer = Join-Path $PSScriptRoot 'atlas-console-mcp-score.mjs'
if (-not (Test-Path -LiteralPath $secretRuntime -PathType Leaf)) { throw "AwsSecretContract runtime not found: $secretRuntime" }
if (-not (Test-Path -LiteralPath $nodeScorer -PathType Leaf)) { throw "Console MCP node scorer not found: $nodeScorer" }
. $secretRuntime -Command export-env -Consumer console-mcp -IncludePrevious
[Environment]::SetEnvironmentVariable('CONSOLE_MCP_ENDPOINT', 'http://127.0.0.1:3334/mcp', 'Process')
Set-Item -Path Env:\CONSOLE_MCP_ENDPOINT -Value 'http://127.0.0.1:3334/mcp'
[Environment]::SetEnvironmentVariable('CONSOLE_MCP_BEARER_TOKEN_SOURCE', 'aws-secret-runtime', 'Process')
Set-Item -Path Env:\CONSOLE_MCP_BEARER_TOKEN_SOURCE -Value 'aws-secret-runtime'

if ($Preflight) {
    & node $nodeScorer --preflight=1
    exit $LASTEXITCODE
}

if ([string]::IsNullOrWhiteSpace($Component)) { throw '-Component is required.' }
if ([string]::IsNullOrWhiteSpace($Workspace)) { throw '-Workspace is required.' }
if ([string]::IsNullOrWhiteSpace($PromptFile)) { throw '-PromptFile is required.' }
if (-not (Test-Path -LiteralPath $Workspace -PathType Container)) { throw "Workspace not found: $Workspace" }
if (-not (Test-Path -LiteralPath $PromptFile -PathType Leaf)) { throw "Prompt file not found: $PromptFile" }
& node $nodeScorer "--component=$Component" "--workspace=$Workspace" "--prompt-file=$PromptFile"
exit $LASTEXITCODE

function Invoke-ConsoleMcpBridge {
    param(
        [Parameter(Mandatory=$true)][string]$Tool,
        [Parameter(Mandatory=$true)]$Arguments,
        [Parameter(Mandatory=$true)][string]$Label
    )

    $stamp = '{0}-{1}' -f ([DateTimeOffset]::UtcNow.ToString('yyyyMMddTHHmmssfffZ')), ([guid]::NewGuid().ToString('N').Substring(0, 8))
    $payloadPath = Join-Path $runtimeDir "$stamp-$Label.payload.json"
    $resultPath = Join-Path $runtimeDir "$stamp-$Label.result.json"
    $payloadJson = [pscustomobject]@{
        runnerExecutionPlan = [pscustomobject]@{
            tool = $Tool
            arguments = $Arguments
        }
    } | ConvertTo-Json -Depth 30
    [IO.File]::WriteAllText($payloadPath, $payloadJson, [Text.UTF8Encoding]::new($false))

    $bridgeRaw = & $bridge -PayloadPath $payloadPath -ResultPath $resultPath 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw (($bridgeRaw | Out-String).Trim())
    }
    if (-not (Test-Path -LiteralPath $resultPath -PathType Leaf)) {
        throw "Console MCP bridge did not materialize result: $resultPath"
    }
    return Get-Content -Raw -LiteralPath $resultPath | ConvertFrom-Json
}

function Get-OptionalProperty {
    param($InputObject, [Parameter(Mandatory=$true)][string]$Name)
    if ($null -eq $InputObject) { return $null }
    if ($InputObject.PSObject.Properties.Name -contains $Name) { return $InputObject.$Name }
    return $null
}

function ConvertFrom-AssistantJson {
    param([Parameter(Mandatory=$true)][string]$Text)
    $candidate = $Text.Trim()
    try { return $candidate | ConvertFrom-Json -Depth 100 } catch {}
    if ($candidate -match '(?s)```(?:json)?\s*(\{.*?\})\s*```') {
        try { return $Matches[1] | ConvertFrom-Json -Depth 100 } catch {}
    }
    $start = $candidate.IndexOf('{')
    $end = $candidate.LastIndexOf('}')
    if ($start -ge 0 -and $end -gt $start) {
        return $candidate.Substring($start, $end - $start + 1) | ConvertFrom-Json -Depth 100
    }
    throw 'Stable assistant answer did not contain a JSON object.'
}

if ($Preflight) {
    $probe = Invoke-ConsoleMcpBridge -Tool 'console.read_.repo.workspace.status' -Arguments ([pscustomobject]@{ workspacePath = $repoRoot }) -Label 'preflight'
    [pscustomobject]@{
        ok = ($probe.ok -eq $true)
        status = 'CONSOLE_MCP_RAW_SCORING_PREFLIGHT'
        bridgeStatus = [string]$probe.status
        secretSource = if ($probe.envBootstrap) { [string]$probe.envBootstrap.tokenSource } else { $null }
        endpoint = if ($probe.envBootstrap) { [string]$probe.envBootstrap.endpoint } else { $null }
    } | ConvertTo-Json -Compress
    exit $(if ($probe.ok -eq $true) { 0 } else { 1 })
}

if ([string]::IsNullOrWhiteSpace($Component)) { throw '-Component is required.' }
if ([string]::IsNullOrWhiteSpace($Workspace)) { throw '-Workspace is required.' }
if ([string]::IsNullOrWhiteSpace($PromptFile)) { throw '-PromptFile is required.' }
if (-not (Test-Path -LiteralPath $Workspace -PathType Container)) { throw "Workspace not found: $Workspace" }
if (-not (Test-Path -LiteralPath $PromptFile -PathType Leaf)) { throw "Prompt file not found: $PromptFile" }

$rawCommand = @"
Quality Atlas scoring task for component $Component.
Target workspace: $Workspace
Read the complete scoring specification from this local file using Console MCP repository/file capabilities: $PromptFile
Follow that specification exactly. Assessment only: do not modify the target repository, commit, or push.
Return only the strict JSON verdict requested by the scoring specification, without markdown or commentary.
"@.Trim()

$started = Invoke-ConsoleMcpBridge -Tool 'console.write.browser.chatgpt.chat.create.send' -Arguments ([pscustomobject]@{
    prompt = $rawCommand
    component = $Component
    taskId = ('quality-atlas-' + $Component)
    promptId = 'quality-atlas-score'
    allowOverwrite = $false
    allowGuestRootSession = $false
    activate = $true
    confirmSend = $true
    timeoutMs = 30000
}) -Label 'launch'

$chatId = Get-OptionalProperty -InputObject $started -Name 'chat_id'
if ([string]::IsNullOrWhiteSpace([string]$chatId)) { $chatId = Get-OptionalProperty -InputObject $started -Name 'chatId' }
if ($started.ok -ne $true -or [string]::IsNullOrWhiteSpace([string]$chatId)) {
    throw "Console MCP scoring chat launch failed: $($started | ConvertTo-Json -Depth 12 -Compress)"
}

$settled = Invoke-ConsoleMcpBridge -Tool 'console.read_.browser.chatgpt.answer.settle' -Arguments ([pscustomobject]@{
    preferredChatId = $chatId
    requireChatId = $true
    maxMessages = 30
    readinessProfile = 'long_run'
    maxWaitMs = 600000
    observationBudgetMs = 60000
    pollMs = 2000
    minStableSamples = 2
    requireComposerSendMode = $false
    timeoutMs = 10000
}) -Label 'settle'

if ($settled.ok -ne $true -or $settled.settled -ne $true -or -not $settled.latest_assistant) {
    throw "Console MCP answer did not settle: $($settled | ConvertTo-Json -Depth 12 -Compress)"
}

$verdict = ConvertFrom-AssistantJson -Text ([string]$settled.latest_assistant.text)
[pscustomobject]@{
    ok = $true
    transport = 'console-mcp-cmcp-go-raw'
    component = $Component
    verdict = $verdict
    lifecycle = [pscustomobject]@{
        launchStatus = [string]$started.status
        settleStatus = [string]$settled.status
        secretSource = if ($started.envBootstrap) { [string]$started.envBootstrap.tokenSource } else { $null }
    }
} | ConvertTo-Json -Depth 100 -Compress
