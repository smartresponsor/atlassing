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
$cmcpCli = Join-Path $consoleRoot 'bin\cmcp.ps1'
$chatgptLoopRoot = Join-Path $workspaceRoot 'mcp\chatgpt-loop'
$consoleMcpBridge = Join-Path $chatgptLoopRoot 'tool\runner-console-mcp-bridge.ps1'
$cleanupRuntimeDir = Join-Path $repoRoot 'var\atlas\generated\chatgpt-cli\cleanup'

if (-not (Test-Path -LiteralPath $devConsole -PathType Leaf)) { throw "Console MCP CLI not found: $devConsole" }
if (-not (Test-Path -LiteralPath $cmcpCli -PathType Leaf)) { throw "CMCP CLI not found: $cmcpCli" }
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

function Invoke-TechnicalChatDelete {
    param(
        [Parameter(Mandatory=$true)][string]$ChatId,
        [Parameter(Mandatory=$true)][bool]$CloseTarget
    )

    $stamp = '{0}-{1}' -f ([DateTimeOffset]::UtcNow.ToString('yyyyMMddTHHmmssfffZ')), ([guid]::NewGuid().ToString('N').Substring(0, 8))
    $phase = if ($CloseTarget) { 'close' } else { 'delete' }
    $payloadPath = Join-Path $cleanupRuntimeDir "$stamp-$phase.payload.json"
    $resultPath = Join-Path $cleanupRuntimeDir "$stamp-$phase.result.json"
    $payload = [pscustomobject]@{
        runnerExecutionPlan = [pscustomobject]@{
            tool = 'console.write.browser.chatgpt.chat.delete.execute'
            arguments = [pscustomobject]@{
                expectedChatId = $ChatId
                confirmDelete = $true
                closeTarget = $CloseTarget
                timeoutMs = 10000
            }
        }
    }
    [IO.File]::WriteAllText($payloadPath, ($payload | ConvertTo-Json -Depth 20), [Text.UTF8Encoding]::new($false))
    $raw = & $consoleMcpBridge -PayloadPath $payloadPath -ResultPath $resultPath 2>&1
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $resultPath -PathType Leaf)) {
        throw "Console MCP technical chat $phase failed: $($raw | Out-String)"
    }
    return Get-Content -Raw -LiteralPath $resultPath | ConvertFrom-Json
}

function Invoke-TechnicalChatCleanup {
    param([Parameter(Mandatory=$true)][string]$ChatId)

    try {
        $deleteResult = Invoke-TechnicalChatDelete -ChatId $ChatId -CloseTarget $false
        $deleteReceipt = if ($deleteResult.delete) { $deleteResult.delete } else { $deleteResult }
        $deleteConfirmed = ($deleteReceipt.ok -eq $true) -and (@('CHAT_SOFT_DELETED', 'CHAT_ALREADY_DELETED') -contains [string]$deleteReceipt.status)
        if (-not $deleteConfirmed) {
            return [pscustomobject]@{
                ok = $false
                status = 'TECHNICAL_CHAT_CONVERSATION_DELETE_NOT_CONFIRMED'
                chatId = $ChatId
                conversationDelete = $deleteResult
                targetClose = $null
            }
        }

        $closeResult = Invoke-TechnicalChatDelete -ChatId $ChatId -CloseTarget $true
        $closeReceipt = if ($closeResult.delete) { $closeResult.delete } else { $closeResult }
        $closeDeleteConfirmed = ($closeReceipt.ok -eq $true) -and (@('CHAT_SOFT_DELETED', 'CHAT_ALREADY_DELETED') -contains [string]$closeReceipt.status)
        $targetCloseRequested = $closeResult.target_close -and ($closeResult.target_close.ok -eq $true)

        return [pscustomobject]@{
            ok = $deleteConfirmed -and $closeDeleteConfirmed -and $targetCloseRequested
            status = if ($deleteConfirmed -and $closeDeleteConfirmed -and $targetCloseRequested) { 'TECHNICAL_CHAT_CONVERSATION_DELETED_AND_TARGET_CLOSE_REQUESTED' } else { 'TECHNICAL_CHAT_TARGET_CLOSE_NOT_CONFIRMED' }
            chatId = $ChatId
            conversationDelete = $deleteResult
            targetClose = $closeResult
        }
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
$workspacePath = [IO.Path]::GetFullPath($Workspace)
$cmcpRaw = & $cmcpCli go $Component 'M5' "--workspace=$workspacePath" "--prompt-file=$promptPath" '--native-engine' '--first-answer-only' '--recover-composer' '--readiness-profile=long_run' 2>&1
$cmcpText = ($cmcpRaw | Out-String).Trim()
try {
    $cmcp = $cmcpText | ConvertFrom-Json -Depth 100
} catch {
    throw "CMCP native engine returned invalid JSON: $cmcpText"
}
if ([string]::IsNullOrWhiteSpace([string]$cmcp.task_id)) {
    throw "CMCP native engine did not return a task id: status=$($cmcp.status) blockedStage=$($cmcp.blocked_stage) blockedReason=$($cmcp.blocked_reason)"
}

$taskId = [string]$cmcp.task_id
$taskStatus = Invoke-ConsoleJson -Arguments @('engine', 'task-status', $taskId)
$chatId = [string]$taskStatus.task.chat_id
$targetId = [string]$taskStatus.task.target_id
$cleanup = $null
try {
    $eventTail = Invoke-ConsoleJson -Arguments @('engine', 'event-tail', $taskId, '--limit=50')
    $answerEvent = @($eventTail.events | Where-Object { $_.event -eq 'executor_answer_captured' } | Select-Object -Last 1)
    if (-not $answerEvent) {
        throw "CMCP native engine did not capture a scoring answer: taskId=$taskId status=$($cmcp.status) blockedStage=$($cmcp.blocked_stage) blockedReason=$($cmcp.blocked_reason)"
    }

    $stableText = [string]$answerEvent.data.latest_assistant.text
    if ([string]::IsNullOrWhiteSpace($stableText)) { throw "CMCP native engine captured an empty scoring answer: taskId=$taskId" }
    $verdict = ConvertFrom-AssistantJson -Text $stableText
} finally {
    $cleanup = if (-not [string]::IsNullOrWhiteSpace($chatId)) {
        Invoke-TechnicalChatCleanup -ChatId $chatId
    } else {
        [pscustomobject]@{ ok = $false; status = 'TECHNICAL_CHAT_CLEANUP_SKIPPED_CHAT_ID_MISSING'; chatId = $null }
    }
}

[pscustomobject]@{
    ok = $true
    transport = 'native-cmcp-engine-file-attachment'
    component = $Component
    verdict = $verdict
    lifecycle = [pscustomobject]@{
        runner = 'bin/cmcp.ps1'
        promptTransport = 'FILE_ATTACHMENT'
        promptFile = $promptPath
        taskId = $taskId
        chatId = $chatId
        targetId = $targetId
        cmcpStatus = $cmcp.status
        blockedStage = $cmcp.blocked_stage
        blockedReason = $cmcp.blocked_reason
        cleanup = $cleanup
    }
} | ConvertTo-Json -Depth 100 -Compress
