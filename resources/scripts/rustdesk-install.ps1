# C+CLub: установка RustDesk на компьютер клуба (Windows Server 2016+/Windows 10+).
# Ставит RustDesk службой, подключает к серверу C+CLub и регистрирует компьютер в админке.
# Запускать в PowerShell от имени администратора командой из раздела «Удалённый доступ».
param([Parameter(Mandatory = $true)][string]$Token)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { throw 'Запустите PowerShell от имени администратора.' }

$Api = '__API__'
$Config = '__CONFIG__'
$exe = Join-Path $env:ProgramFiles 'RustDesk\rustdesk.exe'

if (-not (Test-Path $exe)) {
    Write-Host 'Скачиваю RustDesk...'
    $rel = Invoke-RestMethod 'https://api.github.com/repos/rustdesk/rustdesk/releases/latest' -Headers @{ 'User-Agent' = 'cplusclub-installer' }
    $asset = $rel.assets | Where-Object { $_.name -match 'x86_64\.exe$' -and $_.name -notmatch 'sciter' } | Select-Object -First 1
    if (-not $asset) { throw 'Не нашёл установщик RustDesk для Windows x64 на GitHub.' }
    $tmp = Join-Path $env:TEMP $asset.name
    Invoke-WebRequest $asset.browser_download_url -OutFile $tmp -UseBasicParsing
    Write-Host "Устанавливаю $($asset.name)..."
    Start-Process $tmp -ArgumentList '--silent-install' -Wait
    for ($i = 0; $i -lt 60 -and -not (Test-Path $exe); $i++) { Start-Sleep -Seconds 2 }
    if (-not (Test-Path $exe)) { throw 'RustDesk не установился.' }
    Start-Sleep -Seconds 10
}

Write-Host 'Подключаю к серверу C+CLub...'
& $exe --config $Config | Out-Null
Start-Sleep -Seconds 3
$id = ''
for ($i = 0; $i -lt 20 -and -not $id; $i++) {
    $id = ((& $exe --get-id | Out-String).Trim() -replace '\D', '')
    if (-not $id) { Start-Sleep -Seconds 3 }
}
if (-not $id) { throw 'Не удалось получить ID RustDesk.' }

$body = @{ token = $Token; id = $id; hostname = $env:COMPUTERNAME } | ConvertTo-Json
$r = Invoke-RestMethod -Method Post -Uri "$Api/api/v1/remote/enroll" -ContentType 'application/json; charset=utf-8' -Body ([Text.Encoding]::UTF8.GetBytes($body))
& $exe --password $r.password | Out-Null
Restart-Service -Name 'RustDesk' -ErrorAction SilentlyContinue
Write-Host "Готово. RustDesk ID: $id. Компьютер появился в админке C+CLub, подключение - кнопкой «Подключиться»." -ForegroundColor Green
