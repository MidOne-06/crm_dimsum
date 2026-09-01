param(
    [Parameter(Mandatory = $true)]
    [ValidateRange(0, 20)]
    [int] $Count
)

$projectRoot = Split-Path -Parent $PSScriptRoot
$composeFile = Join-Path $projectRoot 'compose.yaml'
$environmentFile = Join-Path $projectRoot '.env.docker.local'

# No reinicia la web, la base ni el planificador: solo crea o detiene workers.
docker compose -p crm-dimsum --env-file $environmentFile -f $composeFile up -d --no-deps --scale "worker=$Count" worker

if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

Write-Host "Workers de extracción activos: $Count"
