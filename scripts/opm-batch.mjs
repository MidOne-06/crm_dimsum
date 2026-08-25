/**
 * opm-batch.mjs — Extracción dinámica DIGEMID por parámetro/ejecución.
 *
 * Uso:
 *   node scripts/opm-batch.mjs --parametro=1 --ejecucion=5
 */
import fs from "node:fs/promises";
import fsSync from "node:fs";
import path from "node:path";
import { createHash } from "node:crypto";
import { spawn as spawnProcess } from "node:child_process";
import { fileURLToPath } from "node:url";
import nodeFetch from "node-fetch";
import { HttpsProxyAgent } from "https-proxy-agent";
import pg from "pg";

const { Pool } = pg;
const __dir = path.dirname(fileURLToPath(import.meta.url));
const IMPORT_ONLY_REQUESTED = process.argv.some(arg => arg === "--import-only" || arg === "--import-only=true");

// ── CONFIG ─────────────────────────────────────────────────────────────────
const CATALOG_INDEX = process.env.OPM_CATALOG_INDEX;
if (!CATALOG_INDEX) throw new Error("OPM_CATALOG_INDEX es obligatoria para ejecutar el batch.");
const DIGEMID_BASE   = "https://ms-opm.minsa.gob.pe/msopmcovid";
const DIGEMID_HEADERS = {
  Accept: "application/json, text/plain, */*",
  "Content-Type": "application/json",
  Origin: "https://opm-digemid.minsa.gob.pe",
  Referer: "https://opm-digemid.minsa.gob.pe/",
  "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150 Safari/537.36",
};

const pgPassword = process.env.PG_PASSWORD || process.env.DB_PASSWORD;
if (!pgPassword) throw new Error("PG_PASSWORD o DB_PASSWORD es obligatoria para ejecutar el batch.");

const DB = new Pool({
  host: process.env.PG_HOST || process.env.DB_HOST || "127.0.0.1",
  port: Number(process.env.PG_PORT || process.env.DB_PORT || 5432),
  user: process.env.PG_USER || process.env.DB_USERNAME || "postgres",
  password: pgPassword,
  database: process.env.PG_DATABASE || process.env.DB_DATABASE || "opm_digemid",
  max: 5,
});

let runtimeProxyConfig = null;
const PROXY_URL = (() => {
  if (IMPORT_ONLY_REQUESTED) return null;
  const runtimeProxyFile = process.argv
    .find(arg => arg.startsWith("--proxy-config-file="))
    ?.slice("--proxy-config-file=".length);
  let runtimeProxy = null;

  if (process.env.OPM_RUNTIME_PROXY_CONFIG) {
    try {
      runtimeProxy = JSON.parse(process.env.OPM_RUNTIME_PROXY_CONFIG);
    } catch (error) {
      throw new Error(`La configuración temporal del proxy no es válida: ${error.message}`);
    }
  }

  if (!runtimeProxy && runtimeProxyFile) {
    try {
      runtimeProxy = JSON.parse(fsSync.readFileSync(runtimeProxyFile, "utf8"));
      fsSync.unlinkSync(runtimeProxyFile);
    } catch (error) {
      throw new Error(`No se pudo cargar la configuración de proxy de la ejecución: ${error.message}`);
    }
  }

  runtimeProxyConfig = runtimeProxy;

  if (runtimeProxy?.enabled === false) return null;

  const h = String(runtimeProxy?.host ?? process.env.DATAIMPULSE_HOST ?? "gw.dataimpulse.com").trim();
  const p = String(runtimeProxy?.port ?? process.env.DATAIMPULSE_PORT ?? "823").trim();
  const u = encodeURIComponent(String(runtimeProxy?.username ?? process.env.DATAIMPULSE_USER ?? "").trim());
  const pw = encodeURIComponent(String(runtimeProxy?.password ?? process.env.DATAIMPULSE_PASSWORD ?? "").trim());
  if (!u || !pw) throw new Error("DATAIMPULSE_USER y DATAIMPULSE_PASSWORD son obligatorias para ejecutar el batch.");
  return `http://${u}:${pw}@${h}:${p}`;
})();

// ── ARGS ───────────────────────────────────────────────────────────────────
const args = Object.fromEntries(
  process.argv.slice(2)
    .filter(a => a.startsWith("--"))
    .map(a => { const [k, ...v] = a.slice(2).split("="); return [k, v.join("=")]; })
);
const PARAM_ID  = Number(args.parametro);
const EJECUCION_ID = Number(args.ejecucion);
const IMPORT_ONLY = IMPORT_ONLY_REQUESTED;
const EXTRACT_ONLY = args["extract-only"] === "true" || args["extract-only"] === "1";
const SHARD_INDEX = Number(args["shard-index"] ?? -1);
const SHARD_TOTAL = Number(args["shard-total"] ?? 1);
const PRODUCT_QUERY = args["consulta-base64"]
  ? Buffer.from(String(args["consulta-base64"]), "base64").toString("utf8").trim()
  : "";
const PROCESS_ALL_CANDIDATES = args["todos-candidatos"] === "true" || args["todos-candidatos"] === "1";
if (!PARAM_ID || !EJECUCION_ID) {
  console.error("Uso: node scripts/opm-batch.mjs --parametro=ID --ejecucion=ID");
  process.exit(1);
}

function boundedConcurrency(value, fallback, maximum) {
  const parsed = Number(value ?? fallback);
  return Number.isInteger(parsed) && parsed >= 1 ? Math.min(parsed, maximum) : fallback;
}

const PROCESS_COUNT = boundedConcurrency(process.env.OPM_BATCH_PROCESSES, 1, 12);
const CONCURRENCY = boundedConcurrency(process.env.OPM_BATCH_CONCURRENT, 4, 12);
const DETAIL_CONCURRENT = boundedConcurrency(process.env.OPM_DETAIL_CONCURRENT, 8, 32);
const RETRY_ATTEMPTS = boundedConcurrency(process.env.OPM_PROXY_RETRY_ATTEMPTS, 5, 10);
const REQUEST_TIMEOUT_MS = boundedConcurrency(process.env.OPM_REQUEST_TIMEOUT_MS, 30_000, 120_000);
const sleep = ms => new Promise(r => setTimeout(r, ms));

// ── HELPERS ────────────────────────────────────────────────────────────────
const n   = v => (v == null || v === "") ? null : v;
const num = v => { const x = Number(v); return Number.isFinite(x) ? x : null; };

function canonicalNorm(v) {
  return String(v ?? "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .trim()
    .replace(/\s+/g, " ")
    .toUpperCase();
}

function normConcLegacy(v) {
  return canonicalNorm(v)
    .replace(/,/g, ".")
    .replace(/[\u00b5\u03bc]/g, "U")
    .replace(/\s+/g, "");
}

function canonicalConc(v) {
  return normConcLegacy(v).replace(/(\d+(?:\.\d+)?)G(?=\/|\+|$)/g, (_, amount) => {
    const milligrams = Number(amount) * 1000;
    return `${milligrams}MG`;
  });
}

function aliasId(productId, row) {
  return createHash("sha256")
    .update([productId, row.filaCatalogo ?? "", row.Cod_Prod ?? "", row.Num_RegSan ?? "", row.combinationKey ?? ""].join("|"))
    .digest("hex");
}

function candidateId(productId, candidate, consultaNormalizada) {
  return createHash("sha256")
    .update([
      productId,
      canonicalNorm(candidate?.nombreProducto),
      canonicalConc(candidate?.concent),
      canonicalNorm(candidate?.nombreFormaFarmaceutica),
      candidate?.grupo ?? "",
      candidate?.codGrupoFF ?? "",
      consultaNormalizada ?? "",
    ].join("|"))
    .digest("hex");
}

function ingredientSignature(value) {
  return canonicalNorm(value)
    .replace(/[+,/;]/g, "|")
    .replace(/\b(?:Y|E)\b/g, "|")
    .split("|")
    .map(item => item.trim())
    .filter(Boolean)
    .sort()
    .join("|");
}

function priceKey(sel, F) {
  return [sel.grupo, sel.codGrupoFF, canonicalConc(sel.concent),
    F.categoria, F.tipo, F.departamento, F.provincia, F.distrito].join("|");
}
function legacyPriceKey(sel, F) {
  return [sel.grupo, sel.codGrupoFF, normConcLegacy(sel.concent),
    F.categoria, F.tipo, F.departamento, F.provincia, F.distrito].join("|");
}
function productIdForPriceKey(priceCacheKey) {
  return `${EJECUCION_ID}|${priceCacheKey}`;
}
function detailKey(p, ejecucionId) {
  const prod  = p?.codProdE ?? p?.codigoProducto;
  const estab = p?.codEstab ?? p?.codEstablecimiento;
  if (prod == null || estab == null) return "";
  return `${ejecucionId}|${prod}|${String(estab).padStart(7, "0")}`;
}
function chooseCandidate(nomProd, concent, forma, candidates) {
  const exact = (candidates || []).filter(c =>
    canonicalNorm(c?.nombreProducto) === canonicalNorm(nomProd) &&
    canonicalConc(c?.concent)        === canonicalConc(concent)
  );
  if (!exact.length) return null;
  const famScore = (s, t) => {
    if (s === t) return 4;
    const fams = [["TABLETA","CAPSULA"],["SOLUCION","SUSPENSION","JARABE","GOTAS"],
      ["INYECTABLE","POLVO PARA INYECCION"],["CREMA","UNGUENTO","GEL"]];
    return fams.some(f => f.some(x => s.includes(x)) && f.some(x => t.includes(x))) ? 2 : 0;
  };
  return exact.map(c => ({ c, score: famScore(canonicalNorm(forma), canonicalNorm(c.nombreFormaFarmaceutica)) }))
    .sort((a, b) => b.score - a.score)[0]?.c ?? null;
}

// ── NDJSON ─────────────────────────────────────────────────────────────────
async function readNdjson(file) {
  try {
    const text = await fs.readFile(file, "utf8");
    const map = new Map();
    for (const line of text.split(/\r?\n/)) {
      if (!line.trim()) continue;
      try { const item = JSON.parse(line); if (item?.key) map.set(item.key, item); } catch {}
    }
    return map;
  } catch (e) { return e?.code === "ENOENT" ? new Map() : (() => { throw e; })(); }
}

const _queues = new Map();
function appendNdjson(file, value) {
  const prev = _queues.get(file) ?? Promise.resolve();
  const next = prev.then(() => fs.appendFile(file, `${JSON.stringify(value)}\n`, "utf8"),
                         () => fs.appendFile(file, `${JSON.stringify(value)}\n`, "utf8"));
  _queues.set(file, next);
  return next;
}

// ── API ────────────────────────────────────────────────────────────────────
const PROXY_URLS = (() => {
  if (!PROXY_URL) return [];

  const configured = String(process.env.OPM_PROXY_URLS ?? "")
    .split(",")
    .map(value => value.trim())
    .filter(Boolean);

  return configured.length ? configured : [PROXY_URL];
})();

let requestSequence = 0;
const randomJitter = maximum => Math.floor(Math.random() * maximum);

function proxyForAttempt(attempt) {
  if (!PROXY_URLS.length) return null;
  const index = (requestSequence++ + attempt - 1) % PROXY_URLS.length;

  return PROXY_URLS[index];
}

async function apiPost(urlPath, body, attempts = RETRY_ATTEMPTS) {
  let lastErr;
  for (let i = 1; i <= attempts; i++) {
    try {
      const proxyUrl = proxyForAttempt(i);
      const r = await nodeFetch(`${DIGEMID_BASE}${urlPath}`, {
        method: "POST", headers: DIGEMID_HEADERS, body: JSON.stringify(body),
        agent: proxyUrl ? new HttpsProxyAgent(proxyUrl) : undefined,
        signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
      });
      if (!r.ok) {
        const bodyPreview = (await r.text()).slice(0, 200);
        const retryable = r.status === 408 || r.status === 425 || r.status === 429 || r.status >= 500;
        if (!retryable) {
          const error = new Error(`HTTP ${r.status}: ${bodyPreview}`);
          error.retryable = false;
          throw error;
        }
        throw new Error(`RETRYABLE_HTTP_${r.status}: ${bodyPreview}`);
      }
      return await r.json();
    } catch (e) {
      lastErr = e;
      if (e?.retryable === false) throw e;
      if (i < attempts) {
        const delay = Math.min(30_000, 750 * 2 ** (i - 1)) + randomJitter(500);
        await sleep(delay);
      }
    }
  }
  throw lastErr;
}

async function fetchAutocomplete(name) {
  for (let i = 1; i <= 3; i++) {
    const r = await apiPost("/producto/autocompleteciudadano", {
      filtro: { nombreProducto: name, pagina: 1, tamanio: 10, tokenGoogle: "" }
    });
    const data = Array.isArray(r?.data) ? r.data : [];
    if (data.length) return data;
    if (i < 3) await sleep(500 * i);
  }
  return [];
}

// ── DB INSERT ──────────────────────────────────────────────────────────────
async function batchInsert(client, table, cols, rows, batchSize = 500) {
  for (let i = 0; i < rows.length; i += batchSize) {
    const batch = rows.slice(i, i + batchSize);
    const placeholders = batch.map((_, ri) =>
      `(${cols.map((_, ci) => `$${ri * cols.length + ci + 1}`).join(",")})`
    ).join(",");
    const values = batch.flatMap(row => cols.map(c => row[c]));
    await client.query(
      `INSERT INTO ${table} (${cols.join(",")}) VALUES ${placeholders} ON CONFLICT DO NOTHING`,
      values
    );
    process.stdout.write(`\r  ${table}: ${Math.min(i + batchSize, rows.length)}/${rows.length}`);
  }
  if (rows.length) process.stdout.write("\n");
}

function filesFor(workDir) {
  return {
    names: path.join(workDir, "cache-autocomplete.ndjson"),
    prices: path.join(workDir, "cache-prices.ndjson"),
    details: path.join(workDir, "cache-details.ndjson"),
    progress: path.join(workDir, "progress.json"),
    log: path.join(workDir, "batch.log"),
  };
}

function runNode(argumentsList, environment) {
  return new Promise((resolve, reject) => {
    const child = spawnProcess(process.execPath, [fileURLToPath(import.meta.url), ...argumentsList], {
      env: environment,
      stdio: "ignore",
    });

    child.once("error", reject);
    child.once("exit", code => code === 0
      ? resolve()
      : reject(new Error("El worker paralelo terminó con código " + (code ?? "desconocido") + ".")));
  });
}

async function mergeShardCaches(rootFiles, shardDirectories) {
  for (const key of ["names", "prices", "details"]) {
    const merged = new Map();
    for (const directory of shardDirectories) {
      const entries = await readNdjson(filesFor(directory)[key]);
      for (const [entryKey, value] of entries) merged.set(entryKey, value);
    }

    const target = rootFiles[key];
    const temporary = target + ".merge-" + process.pid + ".tmp";
    const content = [...merged.values()].map(value => JSON.stringify(value)).join("\n");
    await fs.writeFile(temporary, content ? content + "\n" : "", "utf8");
    await fs.rename(temporary, target);
  }
}

async function coordinateWorkers({ rootWorkDir, rootFiles, log }) {
  const catalog = JSON.parse(await fs.readFile(CATALOG_INDEX, "utf8"));
  const allRows = Array.isArray(catalog.rows) ? catalog.rows : [];
  const total = PRODUCT_QUERY
    ? new Set(allRows.map(row => canonicalNorm(row.Nom_Prod))).has(canonicalNorm(PRODUCT_QUERY)) ? 1 : 0
    : new Set(allRows.map(row => canonicalNorm(row.Nom_Prod))).size;

  if (!total) throw new Error("No hay productos del catálogo para distribuir entre workers.");

  const workerCount = Math.min(PROCESS_COUNT, total);
  const shardDirectories = Array.from({ length: workerCount }, (_, index) => path.join(rootWorkDir, "shard_" + index));
  const baseArguments = process.argv.slice(2).filter(argument => ![
    "--proxy-config-file", "--shard-index", "--shard-total", "--extract-only", "--import-only",
  ].some(name => argument === name || argument.startsWith(name + "=")));
  const workerEnvironment = {
    ...process.env,
    OPM_BATCH_PROCESSES: "1",
    ...(runtimeProxyConfig ? { OPM_RUNTIME_PROXY_CONFIG: JSON.stringify(runtimeProxyConfig) } : {}),
  };

  await log("Distribuyendo " + total + " nombres entre " + workerCount + " workers paralelos.");
  let completed = false;
  let failed = 0;
  const publishProgress = async () => {
    const progress = await Promise.all(shardDirectories.map(async directory => {
      try {
        return JSON.parse(await fs.readFile(filesFor(directory).progress, "utf8"));
      } catch {
        return { processed: 0, total: 0, failed: 0, priceQueries: 0, details: 0 };
      }
    }));
    const processed = progress.reduce((sum, item) => sum + Number(item.processed ?? 0), 0);
    const priceQueries = progress.reduce((sum, item) => sum + Number(item.priceQueries ?? 0), 0);
    const details = progress.reduce((sum, item) => sum + Number(item.details ?? 0), 0);
    failed = progress.reduce((sum, item) => sum + Number(item.failed ?? 0), 0);
    const percent = ((processed / total) * 100).toFixed(1);
    const snapshot = {
      updatedAt: new Date().toISOString(), processed, total, failed, priceQueries, details,
      percent, workers: workerCount, completado: completed,
    };
    await fs.writeFile(rootFiles.progress, JSON.stringify(snapshot, null, 2), "utf8");
    await DB.query(
      "UPDATE opm_ejecuciones SET total_precios=$2, total_detalles=$3 WHERE id=$1",
      [EJECUCION_ID, priceQueries, details],
    );
    await DB.query(
      "UPDATE opm_parametros SET total_productos=0, total_precios=$2, total_detalles=$3 WHERE id=$1",
      [PARAM_ID, priceQueries, details],
    );
  };

  const monitor = setInterval(() => { publishProgress().catch(() => {}); }, 2_000);
  try {
    const workerResults = await Promise.allSettled(Array.from({ length: workerCount }, (_, index) => runNode([
      ...baseArguments,
      "--shard-index=" + index,
      "--shard-total=" + workerCount,
      "--extract-only=true",
    ], workerEnvironment)));
    const workerFailure = workerResults.find(result => result.status === "rejected");
    if (workerFailure) throw workerFailure.reason;
    completed = true;
    await publishProgress();
  } finally {
    clearInterval(monitor);
  }

  await mergeShardCaches(rootFiles, shardDirectories);
  await log("Workers completados. Reintegrando cachés: " + failed + " fallos recuperables registrados.");
  await runNode([...baseArguments, "--import-only=true"], {
    ...process.env,
    OPM_BATCH_PROCESSES: "1",
  });
}

// ── MAIN ───────────────────────────────────────────────────────────────────
async function main() {
  // 1. Leer parámetro
  const { rows: [parametro] } = await DB.query("SELECT * FROM opm_parametros WHERE id=$1", [PARAM_ID]);
  if (!parametro) { console.error(`Parámetro #${PARAM_ID} no encontrado`); process.exit(1); }

  const F = {
    categoria:   parametro.cod_categoria,
    tipo:        parametro.cod_tipo,
    departamento:parametro.cod_departamento,
    provincia:   parametro.cod_provincia,
    distrito:    parametro.cod_distrito,
  };
  const FILTER_SUFFIX = [F.categoria, F.tipo, F.departamento, F.provincia, F.distrito].join("|");

  const ROOT_WORK_DIR = path.join(__dir, "..", "storage", "app", "opm_batch", "parametro_" + PARAM_ID, "ejecucion_" + EJECUCION_ID);
  const WORK_DIR = SHARD_INDEX >= 0 ? path.join(ROOT_WORK_DIR, "shard_" + SHARD_INDEX) : ROOT_WORK_DIR;
  await fs.mkdir(WORK_DIR, { recursive: true });

  const FILES = filesFor(WORK_DIR);

  const log = async msg => {
    const line = `[${new Date().toISOString()}] ${msg}`;
    console.log(line);
    await fs.appendFile(FILES.log, line + "\n", "utf8").catch(() => {});
  };

  await log(`═══ OPM Batch — Parámetro #${PARAM_ID} / Ejecución #${EJECUCION_ID}: ${parametro.nombre} ═══`);
  await log(`Filtros: ${parametro.desc_categoria} / ${parametro.desc_tipo} / ${parametro.desc_departamento} / ${parametro.desc_provincia} / ${parametro.desc_distrito}`);
  await log(`Directorio: ${WORK_DIR}`);

  // Marcar ejecutando (ejecucion ya existe en BD, creada por PHP)
  await DB.query("UPDATE opm_parametros  SET estado='ejecutando' WHERE id=$1", [PARAM_ID]);
  await DB.query("UPDATE opm_ejecuciones SET estado='ejecutando' WHERE id=$1", [EJECUCION_ID]);

  try {
    if (!IMPORT_ONLY && !EXTRACT_ONLY && SHARD_INDEX < 0 && PROCESS_COUNT > 1) {
      await coordinateWorkers({ rootWorkDir: ROOT_WORK_DIR, rootFiles: FILES, log });

      return;
    }
    // 2. Leer catálogo desde catalog-index.json (ya generado)
    await log("Cargando catálogo de productos...");
    const catalogIndex = JSON.parse(await fs.readFile(CATALOG_INDEX, "utf8"));
    const catalogRows = catalogIndex.rows || [];
    await log(`  ${catalogRows.length} filas en catálogo`);

    // 3. Agrupar por nombre normalizado
    const grouped = new Map();
    for (const row of catalogRows) {
      const k = canonicalNorm(row.Nom_Prod);
      if (!grouped.has(k)) grouped.set(k, []);
      grouped.get(k).push(row);
    }
    const entries = [...grouped.entries()];
    const unshardedEntries = PRODUCT_QUERY
      ? entries.filter(([normalizedName]) => normalizedName === canonicalNorm(PRODUCT_QUERY))
      : entries;
    if (PRODUCT_QUERY && !unshardedEntries.length) {
      throw new Error(`El producto controlado no existe en el catálogo: ${PRODUCT_QUERY}`);
    }
    const scopedEntries = SHARD_INDEX >= 0
      ? unshardedEntries.filter((_, index) => index % SHARD_TOTAL === SHARD_INDEX)
      : unshardedEntries;
    await log(`  ${scopedEntries.length} nombres únicos a consultar${PRODUCT_QUERY ? ` (modo controlado: ${PRODUCT_QUERY})` : ""}`);

    // 4. Cargar caches NDJSON existentes (permite reanudar)
    const nameCache   = await readNdjson(FILES.names);
    const priceCache  = await readNdjson(FILES.prices);
    const detailCache = await readNdjson(FILES.details);
    await log(`  Caché previa: ${nameCache.size} nombres, ${priceCache.size} precios, ${detailCache.size} detalles`);

    // 5. Extracción con workers paralelos
    let processed = 0, failed = 0, detailsSinceProgress = 0;
    if (!IMPORT_ONLY) {
    const startedAt = Date.now();

    const writeProgress = async () => {
      const elapsed = (Date.now() - startedAt) / 1000;
      const rate = processed / Math.max(1, elapsed);
      const eta  = rate > 0 ? Math.round((scopedEntries.length - processed) / rate) : null;
      const pct  = scopedEntries.length > 0 ? ((processed / scopedEntries.length) * 100).toFixed(1) : "0.0";
      await fs.writeFile(FILES.progress, JSON.stringify({
        updatedAt: new Date().toISOString(),
        processed, total: scopedEntries.length, failed,
        priceQueries: priceCache.size,
        details: detailCache.size,
        percent: pct, eta_s: eta,
      }, null, 2), "utf8").catch(() => {});
      // Los workers de fragmento publican su progreso al coordinador; solo éste actualiza la BD.
      if (!EXTRACT_ONLY) {
        await DB.query(
          `UPDATE opm_ejecuciones SET total_precios=$2, total_detalles=$3 WHERE id=$1`,
          [EJECUCION_ID, priceCache.size, detailCache.size]
        ).catch(() => {});
        await DB.query(
          `UPDATE opm_parametros SET total_precios=$2, total_detalles=$3 WHERE id=$1`,
          [PARAM_ID, priceCache.size, detailCache.size]
        ).catch(() => {});
      }
    };

    async function processOne(normName, rows) {
      const displayName = String(rows[0].Nom_Prod).trim();

      // Autocomplete
      let ac = nameCache.get(normName);
      if (!ac) {
        if (displayName.length < 5) {
          ac = { key: normName, status: "OMITIDO_NOMBRE_CORTO", data: [], timestamp: new Date().toISOString() };
        } else {
          try {
            const data = await fetchAutocomplete(displayName);
            ac = { key: normName, status: data.length ? "OK" : "SIN_DATOS", data, timestamp: new Date().toISOString() };
          } catch (e) {
            failed++;
            await log(`[ERROR autocomplete] ${displayName}: ${e.message}`);
            return;
          }
        }
        await appendNdjson(FILES.names, ac);
        nameCache.set(normName, ac);
      }

      // En modo controlado se consultan todos los candidatos que devolvió
      // autocompleteciudadano. El modo histórico conserva el cruce catálogo.
      const selections = [];
      if (PROCESS_ALL_CANDIDATES) {
        const uniqueCandidates = new Map();
        for (const candidate of ac.data || []) {
          if (!candidate?.grupo || !candidate?.codGrupoFF) continue;
          const candidateKey = [
            canonicalNorm(candidate.nombreProducto), canonicalConc(candidate.concent),
            canonicalNorm(candidate.nombreFormaFarmaceutica), candidate.grupo, candidate.codGrupoFF,
          ].join("|");
          uniqueCandidates.set(candidateKey, candidate);
        }
        selections.push(...uniqueCandidates.values());
      } else {
        const seen = new Map();
        for (const row of rows) {
          const ck = `${canonicalNorm(row.Nom_Prod)}||${canonicalConc(row.Concent)}||${canonicalNorm(row.Nom_Form_Farm)}`;
          if (!seen.has(ck)) seen.set(ck, row);
        }
        for (const row of seen.values()) {
          const candidate = chooseCandidate(row.Nom_Prod, row.Concent, row.Nom_Form_Farm, ac.data);
          if (candidate) selections.push(candidate);
        }
      }

      for (const sel of selections) {

        const pKey = priceKey(sel, F);
        let prices = priceCache.get(pKey) ?? priceCache.get(legacyPriceKey(sel, F));
        if (!prices) {
          try {
            const r = await apiPost("/preciovista/ciudadano", { filtro: {
              codigoProducto: sel.grupo,
              codigoDepartamento: F.departamento, codigoProvincia: F.provincia, codigoUbigeo: F.distrito,
              codTipoEstablecimiento: F.tipo, catEstablecimiento: F.categoria,
              nombreEstablecimiento: null, nombreLaboratorio: null,
              codGrupoFF: sel.codGrupoFF, concent: sel.concent,
              tamanio: 10, pagina: 1, tokenGoogle: "", nombreProducto: null,
            } });
            prices = {
              key: pKey, selected: sel, status: "OK", data: Array.isArray(r?.data) ? r.data : [],
              api_response: r, timestamp: new Date().toISOString()
            };
            await appendNdjson(FILES.prices, prices);
            priceCache.set(pKey, prices);
          } catch (e) { await log(`[ERROR prices] ${displayName}: ${e.message}`); continue; }
        }

        // Detalles en paralelo
        const pending = (prices.data || []).filter(p => { const dk = detailKey(p, EJECUCION_ID); return dk && !detailCache.has(dk); });
        if (pending.length) {
          let di = 0;
          const dWorker = async () => {
            while (di < pending.length) {
              const p = pending[di++];
              const dk = detailKey(p, EJECUCION_ID);
              if (!dk || detailCache.has(dk)) continue;
              const [, codProd, codEstab] = dk.split("|"); // [ejecucionId, codProd, codEstab]
              try {
                const r = await apiPost("/precioproducto/obtener", {
                  filtro: { codigoProducto: Number(codProd), codEstablecimiento: codEstab, tokenGoogle: "" }
                });
                const entity = r?.entidad;
                const det = {
                  key: dk, status: entity && typeof entity === "object" ? "OK" : "SIN_ENTIDAD",
                  data: entity && typeof entity === "object" ? entity : {}, api_response: r,
                  timestamp: new Date().toISOString()
                };
                await appendNdjson(FILES.details, det);
                detailCache.set(dk, det);
                detailsSinceProgress++;
                if (detailsSinceProgress >= 25) {
                  detailsSinceProgress = 0;
                  await writeProgress();
                }
              } catch (e) {
                const det = { key: dk, status: "ERROR", data: {}, mensaje: e.message, timestamp: new Date().toISOString() };
                await appendNdjson(FILES.details, det);
                detailCache.set(dk, det);
                detailsSinceProgress++;
                if (detailsSinceProgress >= 25) {
                  detailsSinceProgress = 0;
                  await writeProgress();
                }
              }
            }
          };
          await Promise.all(Array.from({ length: Math.min(DETAIL_CONCURRENT, pending.length) }, dWorker));
        }
      }

      processed++;
      if (processed % 25 === 0 || processed === scopedEntries.length) {
        await writeProgress();
        if (processed % 100 === 0) await log(`Progreso: ${processed}/${scopedEntries.length} | precios:${priceCache.size} | detalles:${detailCache.size}`);
      }
    }

    await log(`Iniciando ${Math.min(CONCURRENCY, scopedEntries.length)} workers...`);
    let wIdx = 0;
    const worker = async () => {
      while (wIdx < scopedEntries.length) {
        const i = wIdx++;
        try { await processOne(scopedEntries[i][0], scopedEntries[i][1]); }
        catch (e) { await log(`[worker] ${scopedEntries[i]?.[0]}: ${e.message}`); }
      }
    };
    await Promise.all(Array.from({ length: Math.min(CONCURRENCY, scopedEntries.length) }, worker));
    await writeProgress();
    await log(`Extracción completada. Procesados: ${processed}, fallidos: ${failed}`);

    if (EXTRACT_ONLY) {
      return;
    }

    } else {
      processed = scopedEntries.length;
      await log(`Modo import-only: se reutilizarÃ¡n ${nameCache.size} autocompletes, ${priceCache.size} consultas de precios y ${detailCache.size} detalles sin usar el proxy.`);
    }

    // 6. Importar a PostgreSQL
    await log("Importando a PostgreSQL...");
    const client = await DB.connect();
    try {
      await client.query("BEGIN");

      // Cada ejecución es independiente — NO borramos ejecuciones previas.
      // Solo borramos si esta misma ejecucion_id ya tenía data (re-run de error).
      await client.query("DELETE FROM opm_detalles WHERE ejecucion_id=$1", [EJECUCION_ID]);
      await client.query("DELETE FROM opm_precios   WHERE ejecucion_id=$1", [EJECUCION_ID]);
      await client.query("DELETE FROM opm_productos WHERE ejecucion_id=$1", [EJECUCION_ID]);

      // Detalles
      const detalleRows = [];
      for (const r of detailCache.values()) {
        if (r.status !== "OK" || !r.data) continue;
        const d = r.data;
        // key = "ejecucionId|codProdE|codEstab"
        const [, codProdE, codEstab] = r.key.split("|");
        detalleRows.push({
          detail_key: r.key, parametro_id: PARAM_ID, ejecucion_id: EJECUCION_ID,
          cod_estab: n(codEstab), cod_prod_e: num(codProdE),
          precio1: num(d.precio1), precio2: num(d.precio2),
          nombre_producto: n(d.nombreProducto), pais_fabricacion: n(d.paisFabricacion),
          registro_sanitario: n(d.registroSanitario), condicion_venta: n(d.condicionVenta),
          tipo_producto: n(d.tipoProducto), nombre_titular: n(d.nombreTitular),
          nombre_fabricante: n(d.nombreFabricante), presentacion: n(d.presentacion),
          laboratorio: n(d.laboratorio), director_tecnico: n(d.directorTecnico),
          nombre_comercial: n(d.nombreComercial), telefono: n(d.telefono),
          email: n(d.email), ruc: n(d.ruc), direccion: n(d.direccion),
          departamento: n(d.departamento), provincia: n(d.provincia), distrito: n(d.distrito),
          horario_atencion: n(d.horarioAtencion), ubigeo: n(d.ubigeo), cat_codigo: n(d.catCodigo),
        });
      }
      if (detalleRows.length) await batchInsert(client, "opm_detalles", Object.keys(detalleRows[0]), detalleRows);
      await log(`  ✓ ${detalleRows.length} detalles`);

      // Precios + meta para productos
      // Un producto resumen representa un grupo técnico de DIGEMID. Los nombres
      // comerciales y principios activos del catálogo son aliases: no duplican
      // precios, pero sí participan en la búsqueda.
      const productSeeds = new Map();
      for (const row of catalogRows) {
        const autocomplete = nameCache.get(canonicalNorm(row.Nom_Prod));
        if (autocomplete?.status !== "OK" || !Array.isArray(autocomplete.data)) continue;

        const selected = chooseCandidate(row.Nom_Prod, row.Concent, row.Nom_Form_Farm, autocomplete.data);
        if (!selected) continue;

        const key = priceKey(selected, F);
        const productId = productIdForPriceKey(key);
        if (!productSeeds.has(key)) productSeeds.set(key, { key, productId, selected, aliases: new Map(), candidates: new Map() });

        const seed = productSeeds.get(key);
        const consultaNormalizada = autocomplete.key;
        seed.candidates.set(candidateId(productId, selected, consultaNormalizada), { candidate: selected, consultaNormalizada });
        const id = aliasId(productId, row);
        seed.aliases.set(id, {
          id,
          producto_id: productId,
          parametro_id: PARAM_ID,
          ejecucion_id: EJECUCION_ID,
          nombre_catalogo: n(String(row.Nom_Prod ?? "").trim()) ?? "",
          nombre_catalogo_normalizado: canonicalNorm(row.Nom_Prod),
          principio_activo: n(row.Nom_IFA),
          principio_activo_normalizado: n(row.Nom_IFA) ? canonicalNorm(row.Nom_IFA) : null,
          codigo_catalogo: n(row.Cod_Prod),
          registro_sanitario: n(row.Num_RegSan),
          presentacion: n(row.Presentac),
          fabricante: n(row.Nom_Fabricante),
          titular: n(row.Nom_Titular),
          combinacion_key: n(row.combinationKey),
        });
      }

      // La vista de Productos representa los candidatos de autocompleteciudadano.
      // Se incluyen incluso si el catálogo no tenía una fila exacta para ellos,
      // igual que en la web oficial; los que no tengan precio quedarán en cero.
      for (const autocomplete of nameCache.values()) {
        if (autocomplete.status !== "OK" || !Array.isArray(autocomplete.data)) continue;
        for (const candidate of autocomplete.data) {
          if (!candidate?.grupo || !candidate?.codGrupoFF) continue;
          const key = priceKey(candidate, F);
          const productId = productIdForPriceKey(key);
          if (!productSeeds.has(key)) {
            productSeeds.set(key, { key, productId, selected: candidate, aliases: new Map(), candidates: new Map() });
          }
          const consultaNormalizada = autocomplete.key;
          productSeeds.get(key).candidates.set(candidateId(productId, candidate, consultaNormalizada), { candidate, consultaNormalizada });
        }
      }

      if (IMPORT_ONLY && !productSeeds.size) {
        throw new Error("El caché no contiene coincidencias reutilizables para esta ejecución; no se realizó ninguna consulta externa.");
      }

      const preferredAliasValue = (aliases, field) => {
        const values = new Map();
        for (const alias of aliases) {
          const value = alias[field];
          if (!value) continue;
          const key = canonicalNorm(value);
          const item = values.get(key) ?? { value, count: 0 };
          item.count++;
          values.set(key, item);
        }
        return [...values.values()]
          .sort((left, right) => right.count - left.count || canonicalNorm(left.value).localeCompare(canonicalNorm(right.value), "es"))[0]
          ?.value ?? null;
      };

      const preferredCandidate = (candidates, principioActivo, fallback) => {
        const activeName = canonicalNorm(principioActivo);
        const activeIngredients = ingredientSignature(principioActivo);

        return [...candidates]
          .sort((left, right) => {
            const score = candidate => {
              const candidateName = canonicalNorm(candidate.nombreProducto);
              if (activeName && candidateName === activeName) return 3;
              if (activeIngredients && ingredientSignature(candidate.nombreProducto) === activeIngredients) return 2;
              // Si no existe una equivalencia exacta en el catálogo, se prioriza
              // el candidato compuesto de DIGEMID sobre una marca comercial.
              if (candidateName.includes("+")) return 1;
              return 0;
            };

            return score(right) - score(left)
              || canonicalNorm(left.nombreProducto).localeCompare(canonicalNorm(right.nombreProducto), "es");
          })[0] ?? fallback;
      };

      const priceMetaMap = new Map();
      const precioRows = [];
      const seenPriceRows = new Set();
      for (const r of priceCache.values()) {
        const selected = r.selected;
        if (!selected?.grupo || !selected?.codGrupoFF) continue;

        const key = priceKey(selected, F);
        if (!productSeeds.has(key)) continue;
        const data = Array.isArray(r.data) ? r.data : [];
        const previous = priceMetaMap.get(key);
        if (!previous || data.length > previous.cantPrecios) {
          const p1s = data.map(p => p.precio1).filter(x => x != null && x > 0);
          priceMetaMap.set(key, {
            cantPrecios: data.length,
            minPrecio1: p1s.length ? Math.min(...p1s) : null,
            maxPrecio1: p1s.length ? Math.max(...p1s) : null,
          });
        }

        for (const p of data) {
          const id = `${EJECUCION_ID}|${p.codProdE}|${p.codEstab}`;
          if (seenPriceRows.has(id)) continue;
          seenPriceRows.add(id);
          precioRows.push({
            id,
            parametro_id: PARAM_ID, ejecucion_id: EJECUCION_ID,
            producto_id: productIdForPriceKey(key),
            cod_estab: n(p.codEstab), cod_prod_e: num(p.codProdE),
            nombre_producto: n(p.nombreProducto), concentracion: n(p.concent),
            precio1: num(p.precio1), precio2: num(p.precio2), precio3: num(p.precio3),
            nombre_comercial: n(p.nombreComercial), nom_grupo_ff: n(p.nomGrupoFF),
            setcodigo: n(p.setcodigo), direccion: n(p.direccion), telefono: n(p.telefono),
            departamento: n(p.departamento), provincia: n(p.provincia), distrito: n(p.distrito),
            ubicodigo: n(p.ubicodigo), fecha: n(p.fecha),
          });
        }
      }

      const productoRows = [];
      const aliasRows = [];
      const candidateRows = [];
      for (const seed of productSeeds.values()) {
        const aliases = [...seed.aliases.values()];
        const principioActivo = preferredAliasValue(aliases, "principio_activo");
        const nombreCatalogo = preferredAliasValue(aliases, "nombre_catalogo");
        const selectedCandidate = preferredCandidate(
          [...seed.candidates.values()].map(item => item.candidate),
          principioActivo,
          seed.selected,
        );
        const meta = priceMetaMap.get(seed.key);
        productoRows.push({
          id: seed.productId, parametro_id: PARAM_ID, ejecucion_id: EJECUCION_ID,
          // La etiqueta de la lista debe reproducir el candidato oficial de
          // autocompleteciudadano. El principio activo queda como información
          // complementaria, no como sustituto del nombre consultado.
          nombre_producto: n(selectedCandidate?.nombreProducto) ?? nombreCatalogo ?? "",
          principio_activo: principioActivo,
          concentracion: n(selectedCandidate?.concent), forma: n(selectedCandidate?.nombreFormaFarmaceutica),
          grupo: num(seed.selected.grupo), cod_grupo_ff: n(seed.selected.codGrupoFF),
          cant_precios: meta?.cantPrecios ?? 0,
          min_precio1: meta?.minPrecio1 ?? null, max_precio1: meta?.maxPrecio1 ?? null,
          min_precio2: null,
        });
        aliasRows.push(...aliases);
        for (const { candidate, consultaNormalizada } of seed.candidates.values()) {
          candidateRows.push({
            id: candidateId(seed.productId, candidate, consultaNormalizada),
            producto_id: seed.productId,
            ejecucion_id: EJECUCION_ID,
            consulta_normalizada: consultaNormalizada,
            nombre_producto: n(candidate.nombreProducto) ?? "",
            nombre_normalizado: canonicalNorm(candidate.nombreProducto),
            concentracion: n(candidate.concent),
            forma: n(candidate.nombreFormaFarmaceutica),
            grupo: num(candidate.grupo),
            cod_grupo_ff: n(candidate.codGrupoFF),
          });
        }
      }

      if (productoRows.length) await batchInsert(client, "opm_productos", Object.keys(productoRows[0]), productoRows);
      if (aliasRows.length) await batchInsert(client, "opm_producto_aliases", Object.keys(aliasRows[0]), aliasRows);
      if (candidateRows.length) await batchInsert(client, "opm_producto_candidatos", Object.keys(candidateRows[0]), candidateRows);
      await log(`  ✓ ${productoRows.length} productos`);
      if (precioRows.length) await batchInsert(client, "opm_precios", Object.keys(precioRows[0]), precioRows);
      await log(`  ✓ ${precioRows.length} precios`);

      // Actualizar ejecucion (histórico) y parametro (resumen último)
      await client.query(`
        UPDATE opm_ejecuciones SET
          estado='completado', completado_at=NOW(),
          total_productos=$2, total_precios=$3, total_detalles=$4
        WHERE id=$1
      `, [EJECUCION_ID, productoRows.length, precioRows.length, detalleRows.length]);

      await client.query(`
        UPDATE opm_parametros SET
          estado='completado', ejecutado_at=NOW(),
          total_productos=$2, total_precios=$3, total_detalles=$4
        WHERE id=$1
      `, [PARAM_ID, productoRows.length, precioRows.length, detalleRows.length]);

      await client.query("COMMIT");
      await log(`✅ Completado: ${productoRows.length} productos / ${precioRows.length} precios / ${detalleRows.length} detalles`);

      // Marcar progress como 100%
      await fs.writeFile(FILES.progress, JSON.stringify({
        updatedAt: new Date().toISOString(),
        processed: scopedEntries.length, total: scopedEntries.length, failed,
        priceQueries: priceCache.size, details: detailCache.size,
        percent: "100.0", eta_s: 0, completado: true,
      }, null, 2), "utf8").catch(() => {});

    } catch (e) {
      await client.query("ROLLBACK");
      throw e;
    } finally {
      client.release();
    }

  } catch (e) {
    await log(`❌ ERROR: ${e.message}`);
    await DB.query("UPDATE opm_ejecuciones SET estado='error' WHERE id=$1", [EJECUCION_ID]).catch(() => {});
    await DB.query("UPDATE opm_parametros  SET estado='error' WHERE id=$1", [PARAM_ID]).catch(() => {});
    process.exit(1);
  } finally {
    await DB.end();
  }
}

main();
