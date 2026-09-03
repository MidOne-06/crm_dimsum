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
- 2026-09-03 · Claude Code · Migración de guías/salidas/requerimientos de `BackgroundArtisan` (moría en cada deploy que recreara `scheduler`) a jobs reales en el contenedor `worker` (`SincronizarGuiasInternasJob`, `SincronizarSalidasStockJob`, `SincronizarRequerimientosStockJob`), con `retryUntil()` en vez de `$tries` fijo (64 fallos falsos por `MaxAttemptsExceededException` corregidos) y guarda de estado para no reprocesar corridas ya `completado` (encontrado en 4/5 despachos duplicados reales de stock-actual/salidas). Corrige además la reanudación de Stock Actual, que relanzaba en `--directo` y quedaba en loop sin avanzar nunca. `reanudar-huerfanas` ahora cubre los 6 módulos de sincronización. · Ninguno -- verificado en producción con `verify-production.ps1` y con una corrida real interrumpida y reanudada.
- 2026-09-03 · Claude Code · Corrige el gap arquitectónico de fondo detrás de las 3 interrupciones reales de la corrida #71 este mismo día: `GuiasInternasHistoricoService::sincronizar()` siempre reiniciaba desde la página 1 al reanudar una corrida `en_progreso`, perdiendo todo el progreso ante cualquier interrupción (deploy, restart). Ahora detecta reanudación (`estado='en_progreso' && paginas_procesadas>0`) y continúa desde `paginas_procesadas+1`, reconstruyendo `$seen` desde lo ya persistido en BD; si una corrida previa a la reanudación dejó páginas sin leer o errores, la reconciliación (borrado) se sigue omitiendo para no generar falsos positivos. Verificado en producción: la corrida #71 reanudó en la página 11 (no desde 0) tras el redeploy. También se encontraron y purgaron 12 despachos duplicados de esa misma corrida acumulados por reintentos previos (payloads con `retryUntil` nulo, de antes del fix), y un lock huérfano con prefijo de caché `panel_administrativo_*` (de un `APP_NAME` anterior, sin dueño activo). `VENTAS_WORKER_REPLICAS` 3→5 y `RESTAURANT_SESSION_POOL_SIZE` 8→12 (VPS con ~12GB RAM libre y load average <1 de 4 núcleos, sin OOM-kills registrados). · `RESTAURANT_SESSION_POOL_SIZE=12` quedó escrito en `/opt/API-TI/.env` pero el contenedor `gateway` no se reinició todavía (habría cortado sesiones de extracciones activas); toma efecto recién en el próximo reinicio natural de ese contenedor.
- 2026-09-03 · Claude Code · Bug de timestamp encontrado en producción: una conexión Postgres de larga duración en `worker` escribía `updated_at` con ~5h de atraso (Lima vs UTC), haciendo que el detector de "extracción huérfana" de la pantalla de Guías internas mostrara "sin avance hace 5 horas" sobre corridas activas y sanas (#71, #70) -- riesgo real de que alguien cancelara una extracción viva pensando que estaba muerta. Causa raíz exacta no confirmada (se sospecha de la conexión persistente del `worker` de mayor antigüedad); mitigado reiniciando las conexiones (`--force-recreate` de `worker`), confirmado con timestamps correctos después. · Sin causa raíz de código identificada y corregida -- si vuelve a aparecer "sin avance" con datos que sí están progresando, sospechar primero de este mismo bug antes de asumir que el proceso murió, y considerar agregar una verificación defensiva (`SET timezone` explícito en cada conexión, o un healthcheck que compare `now()` vs hora del sistema).
- 2026-09-03 · Claude Code · Reanudación incremental por página extendida a `SalidasStockHistoricoService` (mismo patrón que Guías internas, ver entrada anterior). Verificado en producción con una corrida real (#106): estaba en progreso en la página 3/6, se recreó `worker` a propósito para probar, y al redespachar retomó exactamente en la página 4 (paginas_procesadas 3→6 sin reiniciar), cerrando en `completado`. · Requerimientos (`SincronizarReporteRequerimientos`) sigue sin esta misma reanudación incremental -- no se ha demostrado el problema ahí todavía (corre cada 30 min sin cortes hoy), pero el mismo patrón aplicaría si algún día se corta a mitad de una corrida grande. Auditoría de Kardex: el pipeline (`ExtraerKardexJob`/`ProcesarLocalKardexJob`) ya tenía buen diseño de reintento/reclamo (sin el bug de `MaxAttemptsExceededException` falso, porque no usa `release()` manual) -- sin cambios de código. Hallazgo real pendiente de decisión: Kardex **no tiene extracción automática programada** (a diferencia de Ventas/Guías/Requerimientos que corren cada 30 min); depende 100% de que alguien la dispare a mano -- la última corrida real fue del 2026-09-01. Evaluar con el usuario si se agrega al scheduler.
