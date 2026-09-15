param(
    [string]$TaskName = 'SmartResponsor Atlassing Quality Atlas',
    [string]$StartTime = '04:27',
    [ValidateRange(1, 365)]
    [int]$EveryDays = 1,
    [ValidateSet('chatgpt-cli', 'responses', 'dry-run')]
    [string]$Mode = 'dry-run',
    [switch]$StatusOnly
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

if ($StatusOnly) {
    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction Stop
    $info = Get-ScheduledTaskInfo -TaskName $TaskName -ErrorAction Stop
    $actions = @($task.Actions)
    $actionArguments = @($actions | ForEach-Object { $_.Arguments })
    $registeredMode = $null
    foreach ($argumentsValue in $actionArguments) {
        if ($argumentsValue -match '(?i)(?:^|\s)-Mode\s+(chatgpt-cli|responses|dry-run)(?:\s|$)') {
            $registeredMode = $Matches[1].ToLowerInvariant()
            break
        }
    }
    [pscustomobject]@{
        TaskName = $task.TaskName
        State = $task.State
        NextRunTime = $info.NextRunTime
        LastRunTime = $info.LastRunTime
        LastTaskResult = $info.LastTaskResult
        RegisteredMode = $registeredMode
        TriggerStartBoundary = @($task.Triggers | ForEach-Object { $_.StartBoundary })
        ExecutionTimeLimit = [string]$task.Settings.ExecutionTimeLimit
        LogonType = [string]$task.Principal.LogonType
        RunLevel = [string]$task.Principal.RunLevel
        ActionExecute = @($actions | ForEach-Object { $_.Execute })
        ActionArguments = $actionArguments
    }
    return
}

$repoRoot = Split-Path -Parent $PSScriptRoot
$runner = Join-Path $PSScriptRoot 'atlas-local-scheduled.ps1'
if (-not (Test-Path -LiteralPath $runner -PathType Leaf)) {
    throw "Scheduled runner not found: $runner"
}

& py -3 -c 'import yaml'
if ($LASTEXITCODE -ne 0) {
    Write-Host 'Installing required Python dependency: PyYAML'
    & py -3 -m pip install --user PyYAML
    if ($LASTEXITCODE -ne 0) {
        throw "Unable to install PyYAML; exit code $LASTEXITCODE"
    }
}

$engineRoot = Join-Path $repoRoot 'tools\atlas\python-engine'
& py -3 -m py_compile (Join-Path $engineRoot 'select_quality_atlas_targets.py') (Join-Path $engineRoot 'run_quality_atlas_assessment.py')
if ($LASTEXITCODE -ne 0) {
    throw "Atlas Python engine syntax validation failed; exit code $LASTEXITCODE"
}

$time = [DateTime]::ParseExact($StartTime, 'HH:mm', [Globalization.CultureInfo]::InvariantCulture)
$startBoundary = (Get-Date).Date.AddHours($time.Hour).AddMinutes($time.Minute)
if ($startBoundary -le (Get-Date)) {
    $startBoundary = $startBoundary.AddDays(1)
}

$powershell = (Get-Command pwsh.exe -ErrorAction Stop).Source
$arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "{0}" -Mode {1}' -f $runner, $Mode
$action = New-ScheduledTaskAction -Execute $powershell -Argument $arguments -WorkingDirectory $repoRoot
$trigger = New-ScheduledTaskTrigger -Daily -At $startBoundary -DaysInterval $EveryDays
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 4)
$userId = if ($env:USERDOMAIN) { "$env:USERDOMAIN\$env:USERNAME" } else { $env:USERNAME }
$principal = New-ScheduledTaskPrincipal -UserId $userId -LogonType Interactive -RunLevel Limited

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description 'Local-first Atlassing Quality Atlas scan, assessment, commit and push.' -Force | Out-Null

$task = Get-ScheduledTask -TaskName $TaskName
$info = Get-ScheduledTaskInfo -TaskName $TaskName
[pscustomobject]@{
    TaskName = $task.TaskName
    State = $task.State
    NextRunTime = $info.NextRunTime
    LastRunTime = $info.LastRunTime
    LastTaskResult = $info.LastTaskResult
    Mode = $Mode
    EveryDays = $EveryDays
}
