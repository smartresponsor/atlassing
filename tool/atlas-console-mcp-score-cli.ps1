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
$consoleRoot = Join-Path $workspaceRoot 'mcp\console-mcp'
$devConsole = Join-Path $consoleRoot 'tool\dev-console.ps1'
$chatgptLoopRoot = Join-Path $workspaceRoot 'mcp\chatgpt-loop'
$taskBankRunner = Join-Path $chatgptLoopRoot 'tool\runner-task-bank-loop.ps1'
$consoleMcpBridge = Join-Path $chatgptLoopRoot 'tool\runner-console-mcp-bridge.ps1'
$cleanupRuntimeDir = Join-Path $repoRoot 'var\atlas\generated\chatgpt-cli\cleanup'

if (-not (Test-Path -LiteralPath $devConsole -PathType Leaf)) { throw "Console MCP CLI not found: $devConsole" }
if (-not (Test-Path -LiteralPath $taskBankRunner -PathType Leaf)) { throw "ChatGPT task-bank runner not found: $taskBankRunner" }
if (-not (Test-Path -LiteralPath $consoleMcpBridge -PathType Leaf)) { throw "Console MCP bridge not found: $consoleMcpBridge" }
New-Item -ItemType Directory -Force -Path $cleanupRuntimeDir | Out-Null

function Invoke-ConsoleJson {
    param([Parameter(Mandatory=$true)][string[]]$Arguments)
    Push-Location $consoleRoot
    try {
        $raw = & $devConsole @Arguments 2>&1
    } finally {
        Pop-Location
    }
    $text = ($raw | Out-String).Trim()
    try { return $text | ConvertFrom-Json } catch { throw "Console MCP CLI returned invalid JSON: $text" }
}

function Invoke-TechnicalChatCleanup {
    param([Parameter(Mandatory=$true)][string]$ChatId)

    $stamp = '{0}-{1}' -f ([DateTimeOffset]::UtcNow.ToString('yyyyMMddTHHmmssfffZ')), ([guid]::NewGuid().ToString('N').Substring(0, 8))
    $payloadPath = Join-Path $cleanupRuntimeDir "$stamp-delete.payload.json"
    $resultPath = Join-Path $cleanupRuntimeDir "$stamp-delete.result.json"
    $payload = [pscustomobject]@{
        runnerExecutionPlan = [pscustomobject]@{
            tool = 'console.write.browser.chatgpt.chat.delete.execute'
            arguments = [pscustomobject]@{
                expectedChatId = $ChatId
                confirmDelete = $true
                closeTarget = $true
                timeoutMs = 10000
            }
        }
    }
    [IO.File]::WriteAllText($payloadPath, ($payload | ConvertTo-Json -Depth 20), [Text.UTF8Encoding]::new($false))
    try {
        $raw = & $consoleMcpBridge -PayloadPath $payloadPath -ResultPath $resultPath 2>&1
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $resultPath -PathType Leaf)) {
            return [pscustomobject]@{ ok = $false; status = 'TECHNICAL_CHAT_CLEANUP_FAILED'; chatId = $ChatId; error = (($raw | Out-String).Trim()) }
        }
        return Get-Content -Raw -LiteralPath $resultPath | ConvertFrom-Json
    } catch {
        return [pscustomobject]@{ ok = $false; status = 'TECHNICAL_CHAT_CLEANUP_EXCEPTION'; chatId = $ChatId; error = $_.Exception.Message }
    }
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
    $ready = Invoke-ConsoleJson -Arguments @('system-ready-status')
    $reason = if ($ready.failure_classification -and $ready.failure_classification.reason) {
        [string]$ready.failure_classification.reason
    } elseif ($ready.not_ready) {
        (@($ready.not_ready) -join ',')
    } else { $null }
    [pscustomobject]@{
        ok = ($ready.ok -eq $true -and $ready.status -eq 'SYSTEM_READY')
        status = [string]$ready.status
        reason = $reason
    } | ConvertTo-Json -Compress
    exit $(if ($ready.ok -eq $true -and $ready.status -eq 'SYSTEM_READY') { 0 } else { 24 })
}

if ([string]::IsNullOrWhiteSpace($Component)) { throw '-Component is required.' }
if ([string]::IsNullOrWhiteSpace($Workspace)) { throw '-Workspace is required.' }
if ([string]::IsNullOrWhiteSpace($PromptFile)) { throw '-PromptFile is required.' }
if (-not (Test-Path -LiteralPath $Workspace -PathType Container)) { throw "Workspace not found: $Workspace" }
if (-not (Test-Path -LiteralPath $PromptFile -PathType Leaf)) { throw "Prompt file not found: $PromptFile" }

$promptPath = [IO.Path]::GetFullPath($PromptFile)
$bootstrap = @"
Quality Atlas scoring task for component $Component.
The authoritative task prompt is stored at this local file:
$promptPath
Read that file through the available Console MCP repository/file capabilities and follow it exactly.
Assessment only: do not modify, stage, commit, or push the target repository.
Return only the strict JSON verdict required by the authoritative prompt, with no markdown or commentary.
"@

$runnerArgs = @{
    TargetRepo = [IO.Path]::GetFullPath($Workspace)
    MaxIterations = 1
    Name = $Component
    InitialPrompt = $bootstrap
    PromptMode = 'raw'
    InitialPromptMode = 'raw'
    ContinuePromptMode = 'raw'
    ReasoningEnforcement = 'set_and_require'
}
$runner = $null
$runnerText = $null
$transientStatuses = @('CMCP_GO_CHAT_EXPERIENCE_BLOCKED', 'CMCP_GO_DRAFTED_BUT_BLOCKED_RATE_LIMIT', 'CMCP_GO_DRAFT_BLOCKED', 'CMCP_GO_SUBMIT_BLOCKED')
for ($runnerAttempt = 1; $runnerAttempt -le 3; $runnerAttempt++) {
    $runnerRaw = & $taskBankRunner @runnerArgs 6>$null
    $runnerText = ($runnerRaw | Out-String).Trim()
    try {
        $runner = $runnerText | ConvertFrom-Json
    } catch {
        throw "ChatGPT task-bank runner returned invalid JSON: $runnerText"
    }
    if ([int]$runner.submittedCount -ge 1 -and [int]$runner.assistantCapturedCount -ge 1) { break }
    if ($runnerAttempt -ge 3 -or $transientStatuses -notcontains [string]$runner.finalStatus) { break }
    Start-Sleep -Seconds (5 * $runnerAttempt)
}

if ([int]$runner.submittedCount -lt 1 -or [int]$runner.assistantCapturedCount -lt 1) {
    throw "ChatGPT task-bank runner did not capture a scoring answer after bounded retry: status=$($runner.status) finalStatus=$($runner.finalStatus) failures=$(@($runner.acceptanceFailures) -join ',')"
}
if ([string]::IsNullOrWhiteSpace([string]$runner.acceptanceArtifactPath) -or -not (Test-Path -LiteralPath $runner.acceptanceArtifactPath -PathType Leaf)) {
    throw "ChatGPT task-bank runner did not return an acceptance artifact path."
}

$acceptance = Get-Content -Raw -LiteralPath $runner.acceptanceArtifactPath | ConvertFrom-Json
$answerCheck = @($acceptance.answerChecks | Where-Object { $_.captured -eq $true -and [int]$_.textLength -gt 0 } | Select-Object -First 1)
if (-not $answerCheck -or [string]::IsNullOrWhiteSpace([string]$answerCheck.path) -or -not (Test-Path -LiteralPath $answerCheck.path -PathType Leaf)) {
    throw "ChatGPT task-bank runner captured an answer but its answer artifact is unavailable."
}
$answer = Get-Content -Raw -LiteralPath $answerCheck.path | ConvertFrom-Json
$stableText = [string]$answer.assistantText
$verdict = if ($answer.assistantJson) { $answer.assistantJson } else { ConvertFrom-AssistantJson -Text $stableText }
$cleanup = if (-not [string]::IsNullOrWhiteSpace([string]$runner.chatId)) {
    Invoke-TechnicalChatCleanup -ChatId ([string]$runner.chatId)
} else {
    [pscustomobject]@{ ok = $false; status = 'TECHNICAL_CHAT_CLEANUP_SKIPPED_CHAT_ID_MISSING'; chatId = $null }
}

[pscustomobject]@{
    ok = $true
    transport = 'chatgpt-loop-task-bank-raw'
    component = $Component
    verdict = $verdict
    lifecycle = [pscustomobject]@{
        runner = 'tool/runner-task-bank-loop.ps1'
        promptMode = 'raw'
        promptFile = $promptPath
        chatId = $runner.chatId
        targetId = $runner.targetId
        acceptanceStatus = $runner.acceptanceStatus
        acceptanceFailures = @($runner.acceptanceFailures)
        acceptanceArtifactPath = $runner.acceptanceArtifactPath
        cleanup = $cleanup
    }
} | ConvertTo-Json -Depth 100 -Compress
