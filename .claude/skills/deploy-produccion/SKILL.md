---
name: deploy-produccion
description: Despliega CRM-DIMSUM (D:\DS-TI\CRM-DIMSUM\opm-digemid) y/o el gateway API-TI (D:\DS-TI\API-TI) a producción (2.25.104.73) de forma versionada y verificable. Úsalo SIEMPRE que el usuario pida desplegar, publicar, subir a producción, sincronizar producción, "que se reflejen los cambios", relanzar el proyecto en el servidor, o cualquier variante de llevar cambios del entorno de desarrollo local al servidor -- incluso si no menciona el nombre de los scripts o dice solo "sube esto" / "despliega". No uses este skill para trabajo puramente local (features, fixes, tests) que el usuario no haya pedido explícitamente llevar a producción.
---

# Despliegue versionado a producción

## Por qué existe esta regla

Producción nunca se actualiza copiando el disco local. Se actualiza jalando
una versión exacta y commiteada del repo remoto. La razón no es burocracia:
es la única forma de garantizar que **lo que se probó = lo que se commiteó =
lo que corre en producción**. Si producción pudiera recibir archivos sueltos
de un working directory con cambios sin confirmar, quedaría corriendo algo
que no existe en ningún commit -- imposible de reproducir, revisar o
revertir con confianza. Esta sesión encontró exactamente ese problema (drift
real: archivos corriendo en producción que nunca se commitearon) y cerrarlo
costó tiempo real. La regla evita que vuelva a pasar.

## Flujo obligatorio (en este orden, sin saltarse pasos)

```
1. git status  →  el árbol debe estar limpio (sin archivos propios sin commitear)
2. git commit + git push  →  al repo remoto correspondiente
3. Desplegar SOLO desde el remoto (nunca desde el working directory local)
4. Reconstruir/reiniciar los contenedores afectados
5. Verificar salud + verificar hashes (paridad exacta con lo commiteado)
```

## Paso 1-2: árbol limpio y push

Antes de tocar producción, revisa el estado real de los repos que vayan a
desplegarse:

```bash
cd "D:\DS-TI\CRM-DIMSUM\opm-digemid" && git status --short
cd "D:\DS-TI\API-TI" && git status --short
```

**Si hay cambios propios sin commitear que pertenecen al despliegue pedido**:
revísalos (lint, coherencia con el resto del código, que no dependan de
otros archivos aún sin terminar), coméntalos con el usuario si hay algo
dudoso, y commitea + `git push` antes de continuar. No hay atajo: no se
despliega nada que no esté en el remoto.

**Si hay cambios sin commitear que NO son tuyos ni del despliegue pedido**
(otra sesión/persona trabajando en paralelo, código a medio terminar): no
los toques ni los fuerces a pasar. Repórtalos al usuario y sigue solo con lo
que sí está listo para el remoto. Nunca uses `git stash`, `git checkout --`,
ni ninguna otra forma de descartar o esconder cambios ajenos para "destrabar"
un deploy.

Si `deploy-production.ps1` (paso 3) encuentra el árbol sucio, **se va a
negar a correr** -- eso es intencional, no un error a evadir. La solución
es commitear, nunca forzar.

## Paso 3: desplegar

**Mecanismo primario** -- `scripts/deploy-production.ps1` (PowerShell, corre
desde `D:\DS-TI\CRM-DIMSUM\opm-digemid`):

```powershell
.\scripts\deploy-production.ps1
```

Qué hace: exige árbol Git limpio en ambos repos, empaqueta el commit exacto
de `HEAD` con `git archive` (no el working directory), lo sincroniza al
servidor con `rsync --delete` (con excludes ya corregidos para no borrar
contenido legítimo de producción -- `lang/`, `app/Support/`, `app/Data/`,
`app/Filament/Exports/`, `resources/views/filament/modals/`,
`database/database.sqlite`, `public/build/`, `data/catalogo/`),
reconstruye Docker y reinicia `app`, `scheduler`, `kardex-worker` y
`gateway`.

Parámetros útiles:
- Solo cambió CRM (nada en API-TI): `-SkipGateway`
- Publicar un commit/ref específico en vez de HEAD: `-CrmRef <sha>` y/o
  `-GatewayRef <sha>`
- Solo sincronizar sin reconstruir Docker (raro, para pruebas): `-SkipBuild`

**Si el mecanismo primario falla o no aplica** (ej. WSL no disponible, un
cambio en la infraestructura del propio script), el respaldo manual sigue el
MISMO principio -- nunca te saltes el "clonar desde el remoto":

```bash
ssh root@2.25.104.73 "rm -rf /tmp/crm-deploy && git clone --depth 1 https://github.com/MidOne-06/crm_dimsum.git /tmp/crm-deploy && rsync -a /tmp/crm-deploy/ /opt/crm-dimsum/ \
  --exclude='.git' --exclude='.env' --exclude='.env.docker' --exclude='storage' \
  --exclude='lang' --exclude='app/Support' --exclude='app/Filament/Exports' \
  --exclude='resources/views/filament/modals' --exclude='database/database.sqlite' \
  --exclude='vendor' --exclude='node_modules' --exclude='public/build' \
  --exclude='data/catalogo' --exclude='app/Data' --exclude='.phpunit.result.cache' \
  && cd /opt/crm-dimsum && docker compose build app \
  && STOCK_GATEWAY_PATH=/opt/API-TI docker compose up -d --no-deps app scheduler kardex-worker"
```

Para el gateway (si cambió API-TI), mismo principio, clon fresco de
`https://github.com/MidOne-06/api-ti.git` a `/opt/API-TI`, luego
`STOCK_GATEWAY_PATH=/opt/API-TI docker compose build gateway` y
`... up -d --force-recreate gateway` (el build del gateway necesita esa
variable de entorno explícita o falla con "path /API-TI not found").

Nunca reemplaces el `git clone` por una copia directa de
`D:\DS-TI\CRM-DIMSUM\opm-digemid` o `D:\DS-TI\API-TI` -- eso es exactamente
lo que esta regla prohíbe, sin importar qué tan al día parezca estar el
working directory local.

## Paso 4: salud

Después de reconstruir, confirma antes de dar el deploy por bueno:

```bash
ssh root@2.25.104.73 "cd /opt/crm-dimsum && docker compose ps --format '{{.Name}}: {{.Status}}' && docker compose logs app --since 30s 2>&1 | grep -iE 'error|exception'; curl -s -o /dev/null -w 'admin http: %{http_code}\n' http://localhost:8080/admin/login"
```

Todos los contenedores afectados deben quedar `healthy`/`Up`, sin errores en
logs recientes, y el login debe responder 200.

## Paso 5: verificar paridad de hashes (obligatorio para dar el deploy por terminado)

```powershell
.\scripts\verify-production.ps1
```

Compara el hash SHA256 de cada archivo trackeado en Git (usando el blob de
`HEAD`, no el archivo crudo en disco -- necesario porque `.gitattributes`
normaliza a LF y Windows deja CRLF, así que comparar bytes de disco daría
falsos positivos) contra el mismo archivo dentro del contenedor real de
producción. Excluye lo que nunca se despliega por diseño (`storage/`,
`.env*`, `.claude/`, `bootstrap/cache/`, `.deploy-backups/`).

Si termina con "Verificación aprobada: N archivos coinciden", el deploy está
confirmado. Si reporta diferencias, **no está terminado** -- investiga cada
archivo listado antes de decirle al usuario que el cambio ya está en
producción.

## No declares un despliegue terminado sin

1. `verify-production.ps1` en verde (paridad de hashes confirmada), **y**
2. Una prueba funcional real de la pantalla/funcionalidad afectada (no solo
   "el contenedor está healthy" -- eso confirma que arrancó, no que el
   cambio específico funciona).

Si algo de esto falla, dilo con la evidencia real (qué archivo no coincide,
qué error salió), no asumas que "probablemente ya se aplicó".
