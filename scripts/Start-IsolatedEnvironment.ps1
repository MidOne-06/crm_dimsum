[CmdletBinding()]
param(
    [ValidateSet('development', 'staging')]
    [string] $Environment = 'development'
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$environmentFile = Join-Path $projectRoot ".env.$Environment"
$templateFile = "$environmentFile.example"
$composeFile = Join-Path $projectRoot 'compose.isolated.yaml'
$projectName = "crm-dimsum-$Environment"

if (-not (Test-Path $environmentFile)) {
    Copy-Item -LiteralPath $templateFile -Destination $environmentFile
}

$content = Get-Content -LiteralPath $environmentFile -Raw

function Set-EnvironmentValue {
    param([string] $Name, [string] $Value)

    $escapedName = [regex]::Escape($Name)
    $pattern = "(?m)^$escapedName=.*$"
    $replacement = "$Name=$Value"

    if ($script:content -match $pattern) {
        $script:content = [regex]::Replace($script:content, $pattern, $replacement)
    } else {
        $script:content = $script:content.TrimEnd() + [Environment]::NewLine + $replacement + [Environment]::NewLine
    }
}

if ($content -match 'APP_KEY=base64:CHANGE_ME') {
    $appKey = 'base64:' + [Convert]::ToBase64String([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
    Set-EnvironmentValue -Name 'APP_KEY' -Value $appKey
}

if ($content -match 'DB_PASSWORD=CHANGE_ME') {
    $databasePassword = ([Convert]::ToBase64String([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(24))).Replace('+', 'a').Replace('/', 'b').Replace('=', '')
    Set-EnvironmentValue -Name 'DB_PASSWORD' -Value $databasePassword
}

if ($content -match 'ISOLATED_ADMIN_PASSWORD=CHANGE_ME') {
    $administratorPassword = ([Convert]::ToBase64String([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(24))).Replace('+', 'A').Replace('/', 'b').Replace('=', '')
    Set-EnvironmentValue -Name 'ISOLATED_ADMIN_PASSWORD' -Value $administratorPassword
}

Set-Content -LiteralPath $environmentFile -Value $content -NoNewline

$environmentValues = @{}
Get-Content -LiteralPath $environmentFile | ForEach-Object {
    if ($_ -match '^([^#=]+)=(.*)$') {
        $environmentValues[$matches[1]] = $matches[2].Trim('"')
    }
}

$composeArgs = @('--env-file', $environmentFile, '-f', $composeFile, '-p', $projectName)
Push-Location $projectRoot
try {
    & docker compose @composeArgs up -d --build
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo iniciar el entorno aislado.' }

    $healthUrl = "http://localhost:$($environmentValues['APP_PORT'])/healthz"
    $healthy = $false
    foreach ($attempt in 1..45) {
        try {
            if ((Invoke-WebRequest -Uri $healthUrl -UseBasicParsing -TimeoutSec 4).StatusCode -eq 200) {
                $healthy = $true
                break
            }
        } catch {
            Start-Sleep -Seconds 2
        }
    }

    if (-not $healthy) { throw "El entorno inició, pero no respondió en $healthUrl." }

    & docker compose @composeArgs exec -T app php artisan db:seed --class=Database\Seeders\BootstrapIsolatedEnvironmentSeeder --force
    if ($LASTEXITCODE -ne 0) { throw 'El entorno respondió, pero no se pudo crear el administrador aislado.' }

    Write-Host "Entorno $Environment disponible en $healthUrl"
    Write-Host "Usuario administrador: $($environmentValues['ISOLATED_ADMIN_EMAIL'])"
    Write-Host "La contraseña aislada se guarda solo en $environmentFile"
} finally {
    Pop-Location
}
