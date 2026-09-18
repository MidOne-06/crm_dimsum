[CmdletBinding()]
param(
    [ValidateSet('development', 'staging')]
    [string] $Environment = 'development'
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$environmentFile = Join-Path $projectRoot ".env.$Environment"
$composeFile = Join-Path $projectRoot 'compose.isolated.yaml'

if (-not (Test-Path $environmentFile)) {
    throw "No existe $environmentFile."
}

Push-Location $projectRoot
try {
    & docker compose --env-file $environmentFile -f $composeFile -p "crm-dimsum-$Environment" down
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo detener el entorno aislado.' }
} finally {
    Pop-Location
}

Write-Host "Entorno $Environment detenido. Sus volúmenes y datos aislados se conservaron."
