[CmdletBinding()]
param(
    [string] $HostName = '2.25.104.73'
)

$ErrorActionPreference = 'Stop'
$crmRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$files = @(git -C $crmRoot ls-files)
if ($files.Count -eq 0) { throw 'No se encontraron archivos versionados para verificar.' }

$localHashes = @{}
foreach ($file in $files) {
    $localHashes[$file] = (Get-FileHash (Join-Path $crmRoot $file) -Algorithm SHA256).Hash.ToLower()
}

$fileList = ($files | ForEach-Object { "'$_'" }) -join ' '
$remote = "docker exec crm-dimsum-app-1 sh -lc 'cd /var/www/html && sha256sum $fileList'"
$lines = & ssh "root@$HostName" $remote
if ($LASTEXITCODE -ne 0) { throw 'No se pudo leer el contenedor de producción.' }

$remoteHashes = @{}
foreach ($line in $lines) {
    if ($line -match '^([a-f0-9]{64})\s+(.+)$') {
        $remoteHashes[$matches[2].TrimStart('./')] = $matches[1]
    }
}

$mismatches = @($files | Where-Object { $remoteHashes[$_] -ne $localHashes[$_] })
if ($mismatches.Count -gt 0) {
    $mismatches | ForEach-Object { Write-Error "No coincide en producción: $_" }
    throw "Verificación fallida: $($mismatches.Count) archivo(s) no coinciden."
}

Write-Host "Verificación aprobada: $($files.Count) archivos CRM coinciden con producción." -ForegroundColor Green
