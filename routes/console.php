<?php

use App\Models\ConfiguracionSincronizacion;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Panel de Sincronización: cada uno de los 6 syncs automáticos de abajo se
// puede prender/apagar desde /admin/sincronizacion sin tocar código ni
// hacer un deploy -- ->when() hace que Laravel directamente NO dispare esa
// tarea en el tick donde el módulo esté desactivado (no la ejecuta y aborta,
// ni siquiera intenta correr). No afecta la reanudación de huérfanas ni el
// arranque de extracciones que un usuario haya encolado a mano -- apagar el
// automático no debe bloquear algo que alguien pidió explícitamente.
$sincActivo = fn (string $modulo) => fn (): bool => ConfiguracionSincronizacion::activo($modulo);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('ventas:procesar-automatizaciones')
    ->everyMinute()
    ->withoutOverlapping();

// IMPORTANTE: estos tres comandos tienen un rango histórico completo como
// valor por defecto de --desde (para poder invocarlos manualmente como
// backfill de una sola vez). Si el schedule los invoca SIN --desde, cada
// ejecución periódica intenta re-sincronizar años de datos desde cero, nunca
// converge dentro del intervalo, y una corrida muere sin dejar avance
// resumible en la siguiente -- exactamente lo que dejó guías internas y
// requerimientos con solo unos meses/días de histórico real pese a llevar
// tiempo "sincronizándose". Por eso aquí se fuerza siempre una ventana
// incremental corta (con margen de solape para correcciones tardías de
// Restaurant); el histórico profundo se cubre una sola vez con un backfill
// manual (--desde=<fecha real de inicio>), no con el ciclo periódico.
$ventanaIncremental = fn (int $dias): string => now()->subDays($dias)->toDateString();

// runInBackground() es obligatorio aquí: sin él, Laravel ejecuta el comando
// EN EL HILO del propio tick del scheduler y espera a que termine antes de
// revisar el resto de tareas programadas -- una corrida de guías/salidas que
// tarda 40+ minutos bloquearía, por ejemplo, "ventas:procesar-automatizaciones"
// (que debe correr cada minuto) durante todo ese tiempo. runInBackground()
// hace que el scheduler lance el proceso y siga de inmediato con las demás
// tareas sin esperarlo.

// ---------------------------------------------------------------------------
// Los 6 módulos del Panel de Sincronización corren CADA 30 MIN, pero
// ESCALONADOS en carriles de 4 min -- NO todos a la vez en :00/:30.
//
// Por qué: el gateway API-TI mantiene un pool de solo 4 sesiones de navegador
// con Restaurant.pe (RESTAURANT_SESSION_POOL_SIZE). Si los 6 comandos disparan
// en el mismo minuto, sus jobs compiten por esas 4 sesiones y todo se serializa
// igual, pero con logins en frío de más (una sesión inactiva se recicla a los
// pocos min). Escalonados, cada módulo tiene el pool casi para sí mismo en su
// ventana (kardex ~2 min, guías ~1-2 min, el resto <1 min), y reusa sesiones
// tibias del carril anterior.
//
// Orden de los carriles = criticidad para la Directiva de Transferencia:
//   :02/:32  kardex            -> saldo en tiempo real (base de la DT)
//   :06/:36  guías internas    -> cantidad_en_transito de la DT
//   :10/:40  stock actual
//   :14/:44  requerimientos
//   :18/:48  salidas de stock
//   :22/:52  ventas (--dias=0, ventana mínima) -> reporting, no alimenta la DT
// ---------------------------------------------------------------------------

// Kardex alimenta el saldo en tiempo real y, con él, la Directiva de
// Transferencia -- es el módulo más sensible a quedar desactualizado. Antes
// corría UNA vez al día (00:05) extrayendo solo "ayer", con una guarda de
// idempotencia que se saltaba la corrida si ya había cualquier extracción de
// esa fecha. Eso dejaba un hueco real: presionar "Sincronizar y calcular"
// a media tarde (extrae "hoy") marcaba el día como extraído y la nocturna no
// volvía a cerrarlo -- las ventas/entradas posteriores nunca entraban al CRM
// (hueco de 26 unidades encontrado en Aurora / SM001 el 2026-09-10). Ahora
// extrae ayer + hoy, sin guarda de idempotencia (reemplazar() borra e inserta
// el rango, es idempotente). Primer carril del ciclo escalonado.
Schedule::command('kardex:sincronizar-diario')
  ->cron('2,32 * * * *')
  ->withoutOverlapping(180)
  ->runInBackground()
  ->when($sincActivo('kardex'));

Schedule::command('guias-internas:sincronizar --desde='.$ventanaIncremental(3))
  ->cron('6,36 * * * *')
  ->withoutOverlapping(180)
  ->runInBackground()
  ->when($sincActivo('guias-internas'));

// Mantiene la copia local de Stock Actual al día sin bloquear a los usuarios.
Schedule::command('stock-actual:sincronizar --directo --desde='.$ventanaIncremental(3))
  ->cron('10,40 * * * *')
  ->withoutOverlapping(180)
  ->runInBackground()
  ->when($sincActivo('stock-actual'));

// El reporte de requerimientos consulta una copia local para que la matriz y
// las exportaciones respondan de inmediato. Sin esta tarea, la copia quedaba
// detenida en la última extracción manual y el filtro del día actual podía
// mostrar cero aun cuando Restaurant ya tenía requerimientos registrados.
Schedule::command('requerimientos-stock:sincronizar-reporte --desde='.$ventanaIncremental(3))
  ->cron('14,44 * * * *')
  ->withoutOverlapping(180)
  ->runInBackground()
  ->when($sincActivo('requerimientos-stock'));

Schedule::command('salidas-stock:sincronizar --desde='.$ventanaIncremental(3))
  ->cron('18,48 * * * *')
  ->withoutOverlapping(180)
  ->runInBackground()
  ->when($sincActivo('salidas-stock'));

// Ventas es la extracción más pesada (paginada, tabla más grande) y NO
// alimenta la Directiva (la DT lee kardex_movimientos, no la tabla ventas).
// En el ciclo de 30 min va con --dias=0 (solo hoy: la lista de páginas es la
// mitad y los detalles se saltan solos por venta_id ya conocida) y en el
// último carril. El barrido profundo con solape de 3 días lo hace la corrida
// nocturna de abajo. Sus queues (ventas-pages/ventas-details) están LAST en la
// prioridad del worker (ver compose.yaml), así que nunca desplazan a
// guías/salidas/requerimientos.
Schedule::command('ventas:sincronizar-diario --dias=0')
  ->cron('22,52 * * * *')
  ->withoutOverlapping(180)
  ->runInBackground()
  ->when($sincActivo('ventas'));

// Barrido profundo diario de Ventas: 3 días de solape para capturar
// correcciones tardías de Restaurant (una boleta anulada/reemitida días
// después). A la 01:00, fuera de las horas de operación y del ciclo de 30 min.
Schedule::command('ventas:sincronizar-diario --dias=3')
  ->dailyAt('01:00')
  ->withoutOverlapping(180)
  ->runInBackground()
  ->when($sincActivo('ventas'));

// Directiva de Transferencia: corre a las 03:00 -- para entonces el ciclo
// de Kardex de cada 30 min ya recalculó el saldo en tiempo real, y con
// margen suficiente antes del corte real de despacho (el usuario definió
// que todos los locales deben recibir su mercadería como máximo a las
// 12pm, así que la sugerencia tiene que estar lista mucho antes de eso
// para dar tiempo a producción + transporte). A esta hora todavía no
// existe ninguna venta del día en curso -- por diseño (ver
// DirectivaTransferenciaService) usa el promedio histórico del MISMO día
// de la semana, no una extrapolación del día de hoy.
Schedule::command('directiva-transferencia:calcular')
  ->dailyAt('03:00')
  ->withoutOverlapping(60)
  ->runInBackground();

// Autocura corridas huérfanas: si el proceso de un backfill muere (sesión
// SSH cortada, servidor reiniciado) la fila queda en 'en_progreso' para
// siempre y nadie la reintenta. Cada 10 min se detectan corridas estancadas
// (sin avance en 10+ min) y se relanzan solas, usando BackgroundArtisan (ver
// App\Services\BackgroundArtisan -- Process::start() no sobrevive en este
// contenedor bajo ningún padre, comprobado empíricamente). Rápido por
// diseño (solo revisa y dispara), no necesita runInBackground().
Schedule::command('extracciones:reanudar-huerfanas')
  ->everyTenMinutes()
  ->withoutOverlapping(60);

// Arranca las corridas que un usuario encoló desde la web (botón "Iniciar
// extracción" de guías internas / requerimientos de stock). Las páginas de
// Filament SOLO crean la fila 'pendiente' -- el arranque real siempre pasa
// por aquí, con BackgroundArtisan. También es rápido (solo revisa y
// dispara), no necesita runInBackground().
Schedule::command('extracciones:despachar-pendientes')
  ->everyMinute()
  ->withoutOverlapping(50);
