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
$cmcp = Join-Path $consoleRoot 'bin\cmcp.ps1'

if (-not (Test-Path -LiteralPath $devConsole -PathType Leaf)) { throw "Console MCP CLI not found: $devConsole" }
if (-not (Test-Path -LiteralPath $cmcp -PathType Leaf)) { throw "Console MCP go CLI not found: $cmcp" }

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

$startedAt = [DateTimeOffset]::UtcNow
$promptFileRelative = [IO.Path]::GetRelativePath($consoleRoot, [IO.Path]::GetFullPath($PromptFile))
$pwshPath = (Get-Command pwsh -ErrorAction Stop).Source
$cmcpArgs = @(
    '-NoProfile',
    '-ExecutionPolicy', 'Bypass',
    '-File', ('"' + $cmcp + '"'),
    'go', $Component, 'M5',
    ('"--prompt-file=' + $promptFileRelative + '"'),
    '--prompt-mode=raw',
    '--verbose'
)
$process = Start-Process -FilePath $pwshPath -ArgumentList $cmcpArgs -WorkingDirectory $consoleRoot -PassThru

$taskId = $null
$deadline = [DateTimeOffset]::UtcNow.AddMinutes(2)
do {
    Start-Sleep -Milliseconds 500
    $events = Invoke-ConsoleJson -Arguments @('engine', 'event-tail', '--limit=500')
    $taskQueued = @($events.events | Where-Object {
        $_.event -eq 'task_queued' -and
        ([DateTimeOffset]$_.ts) -ge $startedAt -and
        [string]$_.data.component -eq $Component.ToLowerInvariant()
    } | Sort-Object { [DateTimeOffset]$_.ts } | Select-Object -Last 1)
    if ($taskQueued) { $taskId = [string]$taskQueued.task_id; break }
} while ([DateTimeOffset]::UtcNow -lt $deadline -and -not $process.HasExited)

if ([string]::IsNullOrWhiteSpace($taskId)) {
    throw "cmcp go dispatch pid=$($process.Id) did not materialize an engine task for component $Component."
}

$deadline = [DateTimeOffset]::UtcNow.AddMinutes(12)
do {
    Start-Sleep -Seconds 2
    $taskStatus = Invoke-ConsoleJson -Arguments @('engine', 'task-status', "--task-id=$taskId")
    $status = [string]$taskStatus.task.status
    if ($status -in @('done','completed','failed','blocked','waiting_user')) { break }
} while ([DateTimeOffset]::UtcNow -lt $deadline)

$events = Invoke-ConsoleJson -Arguments @('engine', 'event-tail', "--task-id=$taskId", '--limit=500')
$answerEvent = @($events.events | Where-Object {
    $_.task_id -eq $taskId -and $_.event -eq 'executor_answer_captured'
} | Sort-Object { [DateTimeOffset]$_.ts } | Select-Object -Last 1)
if (-not $answerEvent -or -not $answerEvent.data.latest_assistant -or [string]::IsNullOrWhiteSpace([string]$answerEvent.data.latest_assistant.text)) {
    $terminalStatus = if ($taskStatus -and $taskStatus.task) { [string]$taskStatus.task.status } else { 'unknown' }
    throw "cmcp go task $taskId reached status=$terminalStatus without a captured assistant answer."
}

$stableText = [string]$answerEvent.data.latest_assistant.text
$verdict = ConvertFrom-AssistantJson -Text $stableText
[pscustomobject]@{
    ok = $true
    transport = 'console-mcp-go-cli'
    component = $Component
    verdict = $verdict
    lifecycle = [pscustomobject]@{
        taskId = $taskId
        cmcp = 'bin/cmcp.ps1 go'
        promptMode = 'raw'
        promptFile = $PromptFile
    }
} | ConvertTo-Json -Depth 100 -Compress
