[CmdletBinding()]
param(
    [string] $HostName = '2.25.155.29',
    [string] $CrmRef = 'HEAD',
    [string] $GatewayRef = 'HEAD',
    [switch] $SkipGateway,
    [switch] $SkipBuild
)

$ErrorActionPreference = 'Stop'

function Require-Command([string] $Name) {
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "No se encontró '$Name' en PATH. Instálalo antes de desplegar."
    }
}

function Require-CleanGitTree([string] $Path, [string] $Name) {
    $changes = @(git -C $Path status --porcelain)
    if ($changes.Count -gt 0) {
        throw "$Name tiene cambios sin versionar. Confirma los cambios en Git antes de desplegar; así la versión publicada es reproducible."
    }
}

function Get-GitRevision([string] $Path, [string] $Ref) {
    return (git -C $Path rev-parse --verify "$Ref^{commit}").Trim()
}

function New-ReleaseArchive([string] $Path, [string] $Ref, [string] $Name) {
    $archive = Join-Path ([System.IO.Path]::GetTempPath()) "$Name-$([guid]::NewGuid().ToString('N')).tar.gz"
    git -C $Path archive --format=tar.gz --output=$archive $Ref
    if (-not (Test-Path -LiteralPath $archive)) {
        throw "No se pudo crear el paquete de $Name."
    }

    return $archive
}

Require-Command git
Require-Command ssh
Require-Command scp

$crmRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$workspaceRoot = Split-Path (Split-Path $crmRoot -Parent) -Parent
$gatewayRoot = Join-Path $workspaceRoot 'API-TI'

Require-CleanGitTree $crmRoot 'CRM-DIMSUM'
if (-not $SkipGateway) {
    Require-CleanGitTree $gatewayRoot 'API-TI'
}

$crmSha = Get-GitRevision $crmRoot $CrmRef
$gatewaySha = if ($SkipGateway) { $null } else { Get-GitRevision $gatewayRoot $GatewayRef }
$crmArchive = New-ReleaseArchive $crmRoot $crmSha 'crm-dimsum'
$gatewayArchive = if ($SkipGateway) { $null } else { New-ReleaseArchive $gatewayRoot $gatewaySha 'api-ti' }
$timestamp = Get-Date -Format 'yyyyMMddHHmmss'
$remoteCrmArchive = "/tmp/crm-dimsum-$timestamp.tar.gz"
$remoteGatewayArchive = "/tmp/api-ti-$timestamp.tar.gz"

try {
    & scp $crmArchive "root@$HostName`:$remoteCrmArchive"
    if (-not $SkipGateway) {
        & scp $gatewayArchive "root@$HostName`:$remoteGatewayArchive"
    }

    $remoteScript = @"
set -euo pipefail

deploy_tree() {
  archive="`$1"
  target="`$2"
  expected="`$3"
  [ "`$target" = "/opt/crm-dimsum" ] || [ "`$target" = "/opt/API-TI" ] || { echo "Destino no permitido: `$target" >&2; exit 1; }
  stage="`$(mktemp -d /tmp/dimsum-release.XXXXXX)"
  trap 'rm -rf "`$stage"' RETURN
  tar -xzf "`$archive" -C "`$stage"
  test -f "`$stage/`$expected"
  # OJO: --delete borra en destino todo lo que no venga en el paquete de git.
  # /opt/crm-dimsum tiene contenido real que NUNCA estuvo en git (no es
  # basura, es contenido de producción legítimo) -- confirmado con un
  # dry-run real contra el servidor: sin estos excludes, este comando
  # borraría lang/, app/Support/, app/Data/, app/Filament/Exports/,
  # resources/views/filament/modals/, database/database.sqlite,
  # public/build/ (assets compilados) y data/catalogo/ (caché de catálogo).
  rsync -a --delete \
    --exclude '.env' --exclude '.env.docker' --exclude 'storage/' --exclude 'bootstrap/cache/' \
    --exclude 'lang/' --exclude 'app/Support/' --exclude 'app/Data/' --exclude 'app/Filament/Exports/' \
    --exclude 'resources/views/filament/modals/' --exclude 'database/database.sqlite' \
    --exclude 'public/build/' --exclude 'data/catalogo/' \
    "`$stage/" "`$target/"
  rm -rf "`$stage" "`$archive"
  trap - RETURN
}

command -v rsync >/dev/null
deploy_tree "$remoteCrmArchive" /opt/crm-dimsum artisan
"@

    if (-not $SkipGateway) {
        $remoteScript += @"
deploy_tree "$remoteGatewayArchive" /opt/API-TI server.js
"@
    }

    if (-not $SkipBuild) {
        $remoteScript += @"
cd /opt/crm-dimsum
docker compose -p crm-dimsum --env-file .env.docker build app
docker compose -p crm-dimsum --env-file .env.docker up -d --force-recreate app scheduler kardex-worker
"@
        if (-not $SkipGateway) {
            $remoteScript += @"
docker compose -p crm-dimsum --env-file .env.docker build gateway
docker compose -p crm-dimsum --env-file .env.docker up -d --force-recreate gateway
"@
        }
        $remoteScript += @"
docker compose -p crm-dimsum --env-file .env.docker ps app scheduler kardex-worker gateway
curl -fsSI http://127.0.0.1:8080/admin | head -n 1
"@
    }

    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($remoteScript))
    $bashCommand = "printf '%s' '$encoded' | base64 -d | ssh root@$HostName 'bash -s'"
    & wsl.exe -- bash -lc $bashCommand
    if ($LASTEXITCODE -ne 0) {
        throw 'El despliegue remoto falló. La aplicación anterior permanece ejecutándose hasta que Docker recree los servicios correctamente.'
    }

    Write-Host "Despliegue validado. CRM: $crmSha" -ForegroundColor Green
    if ($gatewaySha) { Write-Host "Gateway: $gatewaySha" -ForegroundColor Green }
}
finally {
    Remove-Item -LiteralPath $crmArchive -Force -ErrorAction SilentlyContinue
    if ($gatewayArchive) { Remove-Item -LiteralPath $gatewayArchive -Force -ErrorAction SilentlyContinue }
}
