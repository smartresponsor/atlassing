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
$browserCli = Join-Path $consoleRoot 'dist\cli\chatgpt-browser-session-cli.js'

if (-not (Test-Path -LiteralPath $devConsole -PathType Leaf)) { throw "Console MCP CLI not found: $devConsole" }
if (-not (Test-Path -LiteralPath $browserCli -PathType Leaf)) { throw "Console MCP browser CLI not found: $browserCli" }

function Invoke-ConsoleJson {
    param([Parameter(Mandatory=$true)][string[]]$Arguments)
    $raw = & pwsh -NoProfile -File $devConsole @Arguments 2>&1
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
    $warmth = Invoke-ConsoleJson -Arguments @('chatgpt-session-warmth')
    [pscustomobject]@{
        ok = ($warmth.ok -eq $true)
        status = 'CONSOLE_MCP_SCORING_PREFLIGHT'
        sessionStatus = [string]$warmth.status
        authenticated = [bool]$warmth.authenticated
        guestMode = [bool]$warmth.guest_mode
        loginRequired = [bool]$warmth.login_required
        rootTargetCount = [int]$warmth.root_target_count
        chatTargetCount = [int]$warmth.chat_target_count
    } | ConvertTo-Json -Compress
    exit $(if ($warmth.ok -eq $true) { 0 } else { 24 })
}

if ([string]::IsNullOrWhiteSpace($Component)) { throw '-Component is required.' }
if ([string]::IsNullOrWhiteSpace($Workspace)) { throw '-Workspace is required.' }
if ([string]::IsNullOrWhiteSpace($PromptFile)) { throw '-PromptFile is required.' }
if (-not (Test-Path -LiteralPath $Workspace -PathType Container)) { throw "Workspace not found: $Workspace" }
if (-not (Test-Path -LiteralPath $PromptFile -PathType Leaf)) { throw "Prompt file not found: $PromptFile" }

$open = Invoke-ConsoleJson -Arguments @('chatgpt-open-new-chat', '-ConfirmOpen', '-PromptTransport', 'FILE_ATTACHMENT')
if ($open.ok -ne $true) { throw "Console MCP could not prepare a scoring chat: $($open | ConvertTo-Json -Depth 20 -Compress)" }

$submit = Invoke-ConsoleJson -Arguments @('chatgpt-submit-ready-chat', '-PromptFile', $PromptFile, '-PromptTransport', 'FILE_ATTACHMENT', '-ConfirmSend')
if ($submit.ok -ne $true -or [string]::IsNullOrWhiteSpace([string]$submit.chat_id)) {
    throw "Console MCP scoring prompt submission failed: $($submit | ConvertTo-Json -Depth 20 -Compress)"
}

$chatId = [string]$submit.chat_id
$deadline = [DateTimeOffset]::UtcNow.AddMinutes(10)
$stableText = $null
$stableCount = 0
$latestCapture = $null
while ([DateTimeOffset]::UtcNow -lt $deadline) {
    $rawCapture = & node --enable-source-maps $browserCli chatgpt-capture -ChatId $chatId -TimeoutMs 10000 2>&1
    try { $latestCapture = (($rawCapture | Out-String).Trim() | ConvertFrom-Json) } catch { $latestCapture = $null }
    if ($latestCapture -and $latestCapture.ok -eq $true) {
        $assistant = @($latestCapture.messages | Where-Object { $_.role -eq 'assistant' }) | Select-Object -Last 1
        $text = if ($assistant) { [string]$assistant.text } else { '' }
        if (-not [string]::IsNullOrWhiteSpace($text) -and $text.Trim() -notmatch '^(?i:thinking(?:[.\s]|…)*$)') {
            if ($stableText -eq $text) { $stableCount++ } else { $stableText = $text; $stableCount = 1 }
            if ($stableCount -ge 2) { break }
        }
    }
    Start-Sleep -Seconds 5
}

if ([string]::IsNullOrWhiteSpace($stableText) -or $stableCount -lt 2) { throw "Console MCP scoring answer did not stabilize for chat $chatId." }

$verdict = ConvertFrom-AssistantJson -Text $stableText
[pscustomobject]@{
    ok = $true
    transport = 'console-mcp-cli'
    component = $Component
    verdict = $verdict
    lifecycle = [pscustomobject]@{
        openStatus = [string]$open.status
        submitStatus = [string]$submit.status
        captureStatus = [string]$latestCapture.status
    }
} | ConvertTo-Json -Depth 100 -Compress
