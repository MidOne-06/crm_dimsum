# CRM DIMSUM (opm-digemid)

> Este archivo es un espejo de `CLAUDE.md` en la raíz del repo -- mismo
> contenido, para que Claude Code y Codex/ChatGPT partan del mismo
> contexto. Si editás uno, actualizá el otro igual (especialmente la
> Bitácora al final).

Panel Filament 5.7 sobre Laravel 11, PHP-FPM, Postgres. Repo remoto:
`https://github.com/MidOne-06/crm_dimsum.git` (rama `main`). Producción:
`2.25.104.73`, Docker Compose, proyecto `crm-dimsum`, deploy en
`/opt/crm-dimsum`. Trabaja junto al gateway `API-TI`
(`D:\DS-TI\API-TI`, repo `api-ti`, deploy en `/opt/API-TI`), que expone
la integración real contra Restaurant.pe vía Playwright.

Este proyecto se edita con más de una herramienta de IA (Claude Code y
Codex/ChatGPT, al menos). Las reglas de abajo existen porque ya hubo
fricción real por trabajar sin ellas: una herramienta dejó cambios sin
commitear mientras la otra trabajaba en el mismo árbol, y solo se
descubrió por accidente (unos logs `codex-guias-*.log` sueltos en
`storage/`). No fue un problema de Git -- fue falta de disciplina antes
de empezar a tocar el árbol.

## Reglas de convivencia entre herramientas

**Antes de empezar a editar cualquier archivo:**
1. `git status --short` -- el árbol debe estar limpio. Si hay cambios
   sin commitear que no son tuyos de esta sesión, **no los toques, no
   los descartes** (nunca `git stash`/`git checkout --` sobre trabajo
   ajeno) -- repórtalo al usuario y esperá instrucción.
2. `git pull` -- traé lo que la otra herramienta ya haya subido.

**Al terminar una sesión de trabajo (o antes de que el usuario abra la
otra herramienta):**
3. Commitear y hacer `git push` de lo que quedó terminado y probado.
   No dejar el árbol sucio "para después" -- la próxima sesión (con
   cualquiera de las dos herramientas) parte de ahí.
4. Agregar una entrada corta en la Bitácora de abajo: qué se hizo, qué
   quedó pendiente, y cualquier advertencia que la próxima sesión
   necesite saber (ej. "no reintentar X sin ver Y primero").

**Si vas a trabajar en paralelo de verdad** (no por turnos, sino con
las dos herramientas activas al mismo tiempo): usar una rama por
herramienta (`claude/<tema>`, `codex/<tema>`) y mergear a `main`
cuando cada una termine, en vez de compartir el mismo checkout local.
Evita por completo el problema de "árbol sucio de otro proceso".

**Producción solo se despliega por versión, nunca copiando el disco
local.** Ver el skill `deploy-produccion` (`.claude/skills/deploy-produccion/`)
para el flujo completo y por qué existe.

## Bitácora

Formato: `AAAA-MM-DD · herramienta · qué se hizo · qué queda pendiente/advertencias`.
Agregar entradas nuevas al final. No editar entradas viejas salvo para
corregir un error real.

- 2026-09-03 · Claude Code · Directiva de Transferencia Fase 0 (9 módulos de configuración: cadencia por local, vida útil, capacidad de fábrica, prorrateo, sustitución, capacidad de vehículo, cantidad de arranque) + trazabilidad (`configuracion_dt_eventos`) en todos los modelos de configuración. · Los 9 módulos existen pero están vacíos -- falta cargar datos operativos reales antes de poder construir Fase 1 (cantidad sugerida).
- 2026-09-03 · Claude Code · Guías internas: extracción migrada al estándar de modales; scripts `deploy-production.ps1`/`verify-production.ps1` corregidos y verificados contra producción real (5 bugs reales encontrados y cerrados, no solo revisados en código). · Ninguno.
- 2026-09-03 · Claude Code · Nuevo módulo Reporte de guías internas (`guias-internas/reporte`), con el mismo patrón que Reporte de requerimientos; "Aplicar filtros" sincroniza contra Restaurant antes de mostrar/exportar. · Ninguno.
- 2026-09-03 · Claude Code · Corrige bug real de integridad: `guias-internas:sincronizar` reconciliaba (borraba) siempre por `fecha_emision` sin importar que el usuario hubiera elegido "Fecha de traslado" -- podía borrar guías válidas. Ahora `--filtro-fecha` viaja hasta la reconciliación. · Ninguno.
- 2026-09-03 · Claude Code · Extracción de guías internas: la pantalla solo rastreaba "la corrida más reciente por id", así que una corrida más vieja atascada quedaba invisible bloqueando el botón sin explicación. Ahora se listan TODAS las corridas activas, con detección de estancamiento (15 min sin avance) y cancelación por id. `init: true` agregado al servicio `scheduler` en `compose.yaml` (corría como PID 1 sin reaparentar procesos hijos, acumulando zombies). · Ninguno.
- 2026-09-03 · Claude Code · Intento de optimizar `guias-internas:sincronizar` pidiendo el detalle de cada página en paralelo (`Http::pool`, aprovechando el pool de 4 sesiones del gateway). **Revertido** (commit `d1f73d4`): colgaba el proceso de forma reproducible justo después de la página 1, en 3 pruebas con métodos de lanzamiento distintos, incluido el mecanismo real de producción (despachador + `BackgroundArtisan`). · **No reintentar sin antes conseguir visibilidad real de errores** -- `BackgroundArtisan` manda todo a `/dev/null`, así que la causa exacta del cuelgue nunca se vio. Antes de reintentar: correr con salida capturada (no `/dev/null`) en un entorno controlado, y sospechar primero de una interacción entre `Http::pool()` y el cliente HTTP compartido de Laravel (posible conexión/curl-multi que queda en mal estado para la siguiente llamada no-pooled).
- 2026-09-03 · Codex · Reconciliación de producción: seis páginas de Configuración DT que existían solo en el artefacto del VPS fueron incorporadas al repositorio para evitar drift. · Todo despliegue debe usar `scripts/deploy-production.ps1`; no copiar archivos al VPS.
- 2026-09-03 · Codex · Extracción de guías: corrige la zona horaria de la sesión PostgreSQL, normaliza los timestamps históricos de sus corridas y conserva el stderr de Artisan en `storage/logs/background-artisan.log`. · Verificar la corrida #64 y revisar ese log ante cualquier futura interrupción.
- 2026-09-03 · Codex · Listado de guías internas migrado a consulta paginada en tiempo real contra Restaurant; el reporte permanece sobre la copia local modelada. · Detalle, anulación y descargas del listado también consultan Restaurant; extracción continúa alimentando informe e histórico.
