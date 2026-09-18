[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$environmentFile = Join-Path $projectRoot '.env.development'
$composeFile = Join-Path $projectRoot 'compose.isolated.yaml'
$projectName = 'crm-dimsum-development'

if (-not (Test-Path -LiteralPath $environmentFile)) {
    throw 'No existe .env.development. Inicie Desarrollo antes de sincronizar el catálogo.'
}

# Solo consulta el catálogo maestro de Producción. No se extraen cierres, Bachs,
# salidas, usuarios, auditorías ni ningún otro registro operativo.
$sql = @'
SELECT json_build_object(
  'categorias', COALESCE((
    SELECT json_agg(json_build_object('id', id, 'nombre', nombre, 'orden', orden) ORDER BY orden, id)
    FROM produccion_categorias
  ), '[]'::json),
  'productos', COALESCE((
    SELECT json_agg(json_build_object(
      'categoria_id', produccion_categoria_id,
      'restaurant_item_id', restaurant_item_id,
      'restaurant_item_tipo', restaurant_item_tipo,
      'restaurant_presentacion_id', restaurant_presentacion_id,
      'codigo', codigo,
      'nombre', nombre,
      'unidad', unidad,
      'activo', activo
    ) ORDER BY id)
    FROM produccion_productos
  ), '[]'::json)
);
'@

$encodedSql = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($sql))
$remoteCommand = 'cd /opt/crm-dimsum && docker compose --env-file .env.docker exec -T db sh -lc ''echo ' + $encodedSql + ' | base64 -d | psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At'''
$catalogJson = (& ssh root@2.25.155.29 $remoteCommand).Trim()

if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($catalogJson)) {
    throw 'No se pudo obtener el catálogo maestro desde Producción.'
}

try {
    # Windows PowerShell 5.1 (disponible en este host) no implementa -Depth.
    # El payload solo contiene dos colecciones planas, por lo que no lo necesita.
    $catalog = $catalogJson | ConvertFrom-Json
} catch {
    throw 'Producción devolvió un catálogo con formato inválido.'
}

if (@($catalog.categorias).Count -eq 0 -or @($catalog.productos).Count -eq 0) {
    throw 'El catálogo de Producción está vacío; la importación fue cancelada.'
}

$payload = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($catalogJson)).TrimEnd('=').Replace('+', '-').Replace('/', '_')
$composeArgs = @('--env-file', $environmentFile, '-f', $composeFile, '-p', $projectName)

Push-Location $projectRoot
try {
    & docker compose @composeArgs up -d --build
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo actualizar Desarrollo con el código actual.' }

    & docker compose @composeArgs exec -T app php artisan produccion:importar-catalogo "--payload=$payload" --purge
    if ($LASTEXITCODE -ne 0) { throw 'La importación fue rechazada; Desarrollo no fue modificado parcialmente.' }

    & docker compose @composeArgs exec -T db sh -lc 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -c "SELECT (SELECT count(*) FROM produccion_categorias), (SELECT count(*) FROM produccion_productos), (SELECT count(*) FROM produccion_diaria_cierres), (SELECT count(*) FROM produccion_diaria_tandas), (SELECT count(*) FROM produccion_diaria_salidas);"'
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo verificar el resultado de Desarrollo.' }
} finally {
    Pop-Location
}
