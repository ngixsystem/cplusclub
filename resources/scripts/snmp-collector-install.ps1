# C+CLub: установка сборщика SNMP на компьютер в сети клуба (Windows 10+/Server 2016+).
# Сборщик раз в 2 минуты читает с коммутаторов состояние портов и таблицу MAC-адресов (только чтение)
# и отправляет в C+CLub по HTTPS. Ставить на компьютер, который всегда включён (например, сервер CCBoot).
param([Parameter(Mandatory = $true)][string]$Token)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { throw 'Запустите PowerShell от имени администратора.' }

$Api = '__API__'
$Dir = Join-Path $env:ProgramData 'CClubSNMP'
$Exe = Join-Path $Dir 'cclub-snmp.exe'
$TaskName = 'C+CLub SNMP Collector'

New-Item -ItemType Directory -Force -Path $Dir | Out-Null
if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Get-Process cclub-snmp -ErrorAction SilentlyContinue | Stop-Process -Force
    Start-Sleep -Seconds 2
}

Write-Host 'Скачиваю сборщик...'
Invoke-WebRequest "$Api/snmp/cclub-snmp.exe" -OutFile $Exe -UseBasicParsing
$cfg = @{ server = $Api; token = $Token } | ConvertTo-Json
[IO.File]::WriteAllText((Join-Path $Dir 'config.json'), $cfg, (New-Object Text.UTF8Encoding $false))
# Токен читают только администраторы и SYSTEM.
icacls (Join-Path $Dir 'config.json') /inheritance:r /grant:r 'SYSTEM:F' '*S-1-5-32-544:F' | Out-Null

Write-Host 'Регистрирую автозапуск...'
$action = New-ScheduledTaskAction -Execute $Exe
$trigger = New-ScheduledTaskTrigger -AtStartup
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -StartWhenAvailable
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName

Start-Sleep -Seconds 15
$log = Join-Path $Dir 'collector.log'
if (Test-Path $log) { Get-Content $log -Tail 5 }
Write-Host "Готово. Сборщик работает в фоне и стартует вместе с Windows. Журнал: $log" -ForegroundColor Green
Write-Host "Проверить коммутатор вручную: & '$Exe' -test 192.168.1.10 -community <community>"
