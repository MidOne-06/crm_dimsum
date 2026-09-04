[CmdletBinding()]
param(
    [string] $HostName = '2.25.155.29'
)

$ErrorActionPreference = 'Stop'
$crmRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path

# storage/ está trackeado en este repo (caché de vistas compiladas de
# Blade), pero deploy-production.ps1 lo excluye explícitamente del rsync
# (--exclude 'storage/') -- comparar su hash no verifica nada del código
# desplegado, solo un artefacto regenerado por separado en cada lado, y
# puede quedar referenciado en el índice de Git sin existir ya en disco
# localmente (Laravel lo recompila con otro nombre). Se excluye aquí igual
# que en el deploy.
#
# .env* también está trackeado (convención del equipo, intencional), pero
# cada entorno tiene sus propios secretos/valores -- deploy-production.ps1
# los excluye del rsync a propósito para no pisar los de producción, así
# que verificar que coincidan byte a byte no tiene sentido: se espera que
# sean distintos incluso cuando ambos están "correctos".
# .claude/ es configuración de herramientas (comandos/skills de Claude
# Code) -- se copia al build por no estar en .dockerignore, pero no tiene
# ningún efecto en el runtime de la app; verificarla solo genera ruido.
# .deploy-backups/ es un respaldo histórico de un despliegue manual previo
# (ago-2026), no código servido -- no tiene por qué coincidir con el
# contenedor actual.
# bootstrap/cache/*.php también está en .dockerignore -- son manifiestos
# regenerados por Laravel (config:cache, package discovery), no código
# fuente; comparar su hash no aporta nada.
$files = @(git -C $crmRoot ls-files | Where-Object { $_ -notmatch '^storage/' -and $_ -notmatch '^\.env' -and $_ -notmatch '^\.claude/' -and $_ -notmatch '^\.deploy-backups/' -and $_ -notmatch '^bootstrap/cache/' })
if ($files.Count -eq 0) { throw 'No se encontraron archivos versionados para verificar.' }

# OJO: Get-FileHash sobre el archivo crudo en disco NO sirve aquí.
# .gitattributes tiene "* text=auto eol=lf" -- Git normaliza todo a LF al
# guardar/servir el blob, pero el checkout en Windows deja CRLF en disco.
# Comparar los bytes crudos de Windows contra el contenedor Linux (LF)
# marca como "distinto" a CUALQUIER archivo de texto sin que el código
# haya cambiado en absoluto -- se comprobó con TaperTipo.php: mismo
# contenido, hash de disco distinto del hash real de Git/producción. Se
# extrae el blob exacto de HEAD a un archivo temporal (bytes crudos, sin
# reconstrucción de texto -- así no rompe con binarios) y se hashea eso,
# que es exactamente lo que empaqueta `git archive` para el deploy.
$blobTemp = Join-Path ([System.IO.Path]::GetTempPath()) "verify-blob-$([guid]::NewGuid().ToString('N'))"
$localHashes = @{}
$missingLocal = @()
try {
    foreach ($file in $files) {
        # Redirección de cmd.exe (no el pipeline de PowerShell) para que los
        # bytes del blob lleguen intactos al archivo, sin reinterpretación
        # de texto/encoding de por medio.
        cmd /c "git -C `"$crmRoot`" cat-file blob HEAD:$file > `"$blobTemp`" 2>nul"
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $blobTemp)) {
            $missingLocal += $file
            continue
        }
        $localHashes[$file] = (Get-FileHash -LiteralPath $blobTemp -Algorithm SHA256).Hash.ToLower()
        Remove-Item -LiteralPath $blobTemp -Force -ErrorAction SilentlyContinue
    }
} finally {
    Remove-Item -LiteralPath $blobTemp -Force -ErrorAction SilentlyContinue
}
if ($missingLocal.Count -gt 0) {
    $missingLocal | ForEach-Object { Write-Warning "Trackeado en Git pero no se pudo leer el blob de HEAD, se omite: $_" }
    $files = $files | Where-Object { $localHashes.ContainsKey($_) }
}

$fileList = ($files | ForEach-Object { "'$_'" }) -join ' '
$remote = "docker exec crm-dimsum-app-1 sh -lc 'cd /var/www/html && sha256sum $fileList'"
$lines = & ssh "root@$HostName" $remote
# sha256sum devuelve código distinto de cero si CUALQUIER archivo del lote
# falta en el contenedor, aun cuando sí calculó el hash del resto -- eso no
# es un fallo de conexión. Solo se trata como fallo real si no llegó
# ninguna línea de vuelta (ahí sí no se pudo leer el contenedor).
if ($null -eq $lines -or $lines.Count -eq 0) { throw 'No se pudo leer el contenedor de producción.' }

$remoteHashes = @{}
foreach ($line in $lines) {
    if ($line -match '^([a-f0-9]{64})\s+(.+)$') {
        # TrimStart('./') trata el argumento como un CONJUNTO de caracteres a
        # recortar (no un prefijo literal) -- además de quitar el "./" que
        # antepone sha256sum, también se comía el punto inicial de
        # dotfiles reales como .gitignore, dejando la clave "gitignore" y
        # marcándolos como "no coincide" por un KeyNotFound silencioso.
        $remoteHashes[($matches[2] -replace '^\./', '')] = $matches[1]
    } elseif ($line -match "sha256sum: can't open '(.+)'") {
        Write-Warning "No existe en producción, se omite: $($matches[1])"
    }
}

$mismatches = @($files | Where-Object { $remoteHashes[$_] -ne $localHashes[$_] })
if ($mismatches.Count -gt 0) {
    # Write-Error corta el script en la primera línea porque
    # $ErrorActionPreference = 'Stop' aplica también dentro del ForEach --
    # se listan todas las diferencias antes de fallar, no solo la primera.
    $mismatches | ForEach-Object { Write-Host "No coincide en producción: $_" -ForegroundColor Red }
    throw "Verificación fallida: $($mismatches.Count) archivo(s) no coinciden."
}

Write-Host "Verificación aprobada: $($files.Count) archivos CRM coinciden con producción." -ForegroundColor Green
