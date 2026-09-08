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

# El rsync de más abajo excluye '.git' a propósito (borrarlo dejaría el
# checkout de producción sin ningún historial -- pasó de verdad el
# 2026-09-07). Pero eso tiene un costo que se descubrió recién: el propio
# '.git' del servidor queda SIEMPRE apuntando al commit de la vez anterior
# que alguien corrió `git fetch && git merge` ahí a mano, sin importar
# cuántas veces se despliegue con este script -- 'git log'/'git status' en
# el servidor mienten sobre qué versión está realmente corriendo. Pasó 3
# veces reales en la misma semana (documentado en la bitácora del
# 2026-09-08) y cada vez costó tiempo real diagnosticar si era un hotfix
# directo por SSH o esto. La corrección: además de los archivos, empaquetar
# también el propio commit (como bundle de Git, no como archive) y, en el
# servidor, actualizar la referencia de la rama al SHA exacto que se acaba
# de desplegar -- así 'git log' en producción vuelve a ser una fuente de
# verdad real, sin tocar el working tree (que ya lo dejó correcto el rsync).
function New-GitSyncBundle([string] $Path, [string] $TargetSha, [string] $BaseSha, [string] $Name) {
    $bundle = Join-Path ([System.IO.Path]::GetTempPath()) "$Name-$([guid]::NewGuid().ToString('N')).bundle"
    # `git bundle create` exige un REF con nombre del lado a incluir -- un
    # SHA suelto como "B" en "A..B" hace que rechace el bundle como "vacío"
    # aunque el rango sí tenga commits (probado en vivo). refs/tmp/* es una
    # referencia real pero fuera de refs/heads, para no tocar ninguna rama
    # local mientras se arma el paquete.
    $tempRef = 'refs/tmp/deploy-bundle'
    git -C $Path update-ref $tempRef $TargetSha
    try {
        $useIncremental = $false
        if ($BaseSha) {
            git -C $Path merge-base --is-ancestor $BaseSha $TargetSha 2>$null
            $useIncremental = ($LASTEXITCODE -eq 0)
        }
        if ($useIncremental) {
            git -C $Path bundle create $bundle "$BaseSha..$tempRef" | Out-Null
        } else {
            # Sin una base común conocida (primera vez, o el servidor está más
            # atrás de lo que cualquier incremental puede cubrir): empaqueta
            # toda la historia alcanzable desde el commit a desplegar. Más
            # pesado, pero siempre correcto.
            git -C $Path bundle create $bundle $tempRef | Out-Null
        }
    } finally {
        git -C $Path update-ref -d $tempRef | Out-Null
    }
    if (-not (Test-Path -LiteralPath $bundle)) {
        throw "No se pudo crear el bundle de sincronización de Git para $Name."
    }

    return $bundle
}

function Get-RemoteGitHead([string] $HostName, [string] $Path) {
    $output = & ssh "root@$HostName" "git -C $Path rev-parse HEAD 2>/dev/null || true"
    $sha = ($output | Select-Object -First 1)
    if ($sha -match '^[0-9a-f]{40}$') {
        return $sha
    }

    return $null
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

$crmRemoteHead = Get-RemoteGitHead $HostName '/opt/crm-dimsum'
$crmBundle = New-GitSyncBundle $crmRoot $crmSha $crmRemoteHead 'crm-dimsum'
$gatewayBundle = $null
if (-not $SkipGateway) {
    $gatewayRemoteHead = Get-RemoteGitHead $HostName '/opt/API-TI'
    $gatewayBundle = New-GitSyncBundle $gatewayRoot $gatewaySha $gatewayRemoteHead 'api-ti'
}

$timestamp = Get-Date -Format 'yyyyMMddHHmmss'
$remoteCrmArchive = "/tmp/crm-dimsum-$timestamp.tar.gz"
$remoteGatewayArchive = "/tmp/api-ti-$timestamp.tar.gz"
$remoteCrmBundle = "/tmp/crm-dimsum-$timestamp.bundle"
$remoteGatewayBundle = "/tmp/api-ti-$timestamp.bundle"

try {
    & scp $crmArchive "root@$HostName`:$remoteCrmArchive"
    & scp $crmBundle "root@$HostName`:$remoteCrmBundle"
    if (-not $SkipGateway) {
        & scp $gatewayArchive "root@$HostName`:$remoteGatewayArchive"
        & scp $gatewayBundle "root@$HostName`:$remoteGatewayBundle"
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
  # También borraría /opt/crm-dimsum/.git -- pasó de verdad el 2026-09-07
  # (deploy de "Movimientos entre almacenes"): `git archive` no incluye
  # .git, así que sin este exclude el propio repo del servidor queda
  # borrado, y con él toda forma de confirmar HEAD/`git status` ahí sin
  # reclonar. .deploy-backups/ y .claude/ tampoco vienen del paquete de
  # git y son de la otra herramienta (Codex) -- se preservan igual.
  rsync -a --delete \
    --exclude '.git' --exclude '.deploy-backups' --exclude '.claude' \
    --exclude '.env' --exclude '.env.docker' --exclude 'storage/' --exclude 'bootstrap/cache/' \
    --exclude 'lang/' --exclude 'app/Support/' --exclude 'app/Data/' --exclude 'app/Filament/Exports/' \
    --exclude 'resources/views/filament/modals/' --exclude 'database/database.sqlite' \
    --exclude 'public/build/' --exclude 'data/catalogo/' \
    "`$stage/" "`$target/"
  rm -rf "`$stage" "`$archive"
  trap - RETURN
}

# El rsync de arriba nunca toca '.git' (por diseño, ver el comentario sobre
# el borrado real del 2026-09-07). Sin este paso, 'git log'/'git status' en
# el servidor quedan congelados en lo que sea que haya ahí -- normalmente el
# commit de la última vez que alguien corrió `git fetch`/`merge` a mano, NO
# lo que este script acaba de dejar corriendo. Encontrado 3 veces reales en
# la misma semana (bitácora 2026-09-08), cada vez pareciendo un hotfix
# directo por SSH cuando en realidad era esto. El bundle ya trae los objetos
# de Git necesarios (incremental si el servidor tenía una base conocida,
# completo si no) -- acá solo se actualiza la referencia de la rama al SHA
# exacto desplegado, sin tocar ningún archivo del working tree (el rsync ya
# lo dejó correcto).
sync_git_state() {
  target="`$1"
  sha="`$2"
  bundle="`$3"
  if [ ! -d "`$target/.git" ]; then
    echo "AVISO: `$target no tiene .git todavía -- se omite la sincronización de estado de Git (no bloquea el deploy de archivos)." >&2
    rm -f "`$bundle"
    return 0
  fi
  if [ ! -f "`$bundle" ]; then
    echo "AVISO: no llegó el bundle de Git para `$target -- se omite la sincronización de estado." >&2
    return 0
  fi
  git -C "`$target" fetch "`$bundle" "refs/tmp/deploy-bundle:refs/tmp/deploy-sync"
  git -C "`$target" update-ref refs/heads/main "`$sha"
  git -C "`$target" symbolic-ref HEAD refs/heads/main
  git -C "`$target" update-ref -d refs/tmp/deploy-sync 2>/dev/null || true
  # reset --hard también alinea el ÍNDICE, no solo el ref -- encontrado en
  # vivo el 2026-09-08 al probar este mismo script: un `git checkout -- .`
  # anterior (de una limpieza manual) había dejado el índice con contenido
  # viejo aunque el working tree y el ref ya estaban correctos, y quedaba
  # invisible hasta el primer `git status` real. reset --hard solo toca
  # archivos TRACKEADOS -- el contenido legítimo de producción que el rsync
  # excluye (lang/, app/Support/, etc.) es untracked y no se toca.
  git -C "`$target" reset --hard "`$sha"
  rm -f "`$bundle"
  echo "git en `$target sincronizado a `$sha"
}

command -v rsync >/dev/null
deploy_tree "$remoteCrmArchive" /opt/crm-dimsum artisan
sync_git_state /opt/crm-dimsum "$crmSha" "$remoteCrmBundle"
"@

    if (-not $SkipGateway) {
        $remoteScript += @"
deploy_tree "$remoteGatewayArchive" /opt/API-TI server.js
sync_git_state /opt/API-TI "$gatewaySha" "$remoteGatewayBundle"
"@
    }

    if (-not $SkipBuild) {
        $remoteScript += @"
cd /opt/crm-dimsum
docker compose -p crm-dimsum --env-file .env.docker build app
docker compose -p crm-dimsum --env-file .env.docker up -d --force-recreate app worker scheduler kardex-worker
"@
        if (-not $SkipGateway) {
            $remoteScript += @"
docker compose -p crm-dimsum --env-file .env.docker build gateway
docker compose -p crm-dimsum --env-file .env.docker up -d --force-recreate gateway
"@
        }
        $remoteScript += @"
docker compose -p crm-dimsum --env-file .env.docker ps app worker scheduler kardex-worker gateway
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
    Remove-Item -LiteralPath $crmBundle -Force -ErrorAction SilentlyContinue
    if ($gatewayArchive) { Remove-Item -LiteralPath $gatewayArchive -Force -ErrorAction SilentlyContinue }
    if ($gatewayBundle) { Remove-Item -LiteralPath $gatewayBundle -Force -ErrorAction SilentlyContinue }
}
