/**
 * Extrae todos los departamentos, provincias y distritos del DIGEMID
 * y genera el archivo PHP con el array completo para Filament.
 */
import { writeFileSync } from 'fs';

const BASE = 'https://ms-opm.minsa.gob.pe/msopmcovid';

const HEADERS = {
  'Accept': 'application/json, text/plain, */*',
  'Content-Type': 'application/json',
  'Origin': 'https://opm-digemid.minsa.gob.pe',
  'Referer': 'https://opm-digemid.minsa.gob.pe/',
  'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
};

const DEPTOS = [
  {c:'01',n:'AMAZONAS'},{c:'02',n:'ANCASH'},{c:'03',n:'APURIMAC'},
  {c:'04',n:'AREQUIPA'},{c:'05',n:'AYACUCHO'},{c:'06',n:'CAJAMARCA'},
  {c:'07',n:'CALLAO'},{c:'08',n:'CUSCO'},{c:'09',n:'HUANCAVELICA'},
  {c:'10',n:'HUANUCO'},{c:'11',n:'ICA'},{c:'12',n:'JUNIN'},
  {c:'13',n:'LA LIBERTAD'},{c:'14',n:'LAMBAYEQUE'},{c:'15',n:'LIMA'},
  {c:'16',n:'LORETO'},{c:'17',n:'MADRE DE DIOS'},{c:'18',n:'MOQUEGUA'},
  {c:'19',n:'PASCO'},{c:'20',n:'PIURA'},{c:'21',n:'PUNO'},
  {c:'22',n:'SAN MARTIN'},{c:'23',n:'TACNA'},{c:'24',n:'TUMBES'},
  {c:'25',n:'UCAYALI'}
];

async function post(path, body) {
  const r = await fetch(`${BASE}/${path}`, {
    method: 'POST',
    headers: HEADERS,
    body: JSON.stringify(body),
  });
  return r.json();
}

console.log('Extrayendo provincias de 25 departamentos...');
const provBatch = await Promise.all(DEPTOS.map(d =>
  post('parametro/provincias', { filtro: { codigo: d.c, codigoDos: null } })
    .then(r => ({ depto: d, provs: r.data }))
));
const allProvs = provBatch.flatMap(({ depto, provs }) =>
  provs.map(p => ({ dc: depto.c, dn: depto.n, pc: p.codigo, pn: p.descripcion }))
);
console.log(`${allProvs.length} provincias obtenidas. Extrayendo distritos...`);

const distBatch = await Promise.all(allProvs.map(p =>
  post('parametro/distritos', { filtro: { codigo: p.pc, codigoDos: p.dc } })
    .then(r => ({ ...p, dists: r.data.map(d => ({ c: d.codigo, n: d.descripcion })) }))
));
console.log(`Distritos obtenidos: ${distBatch.reduce((a, p) => a + p.dists.length, 0)}`);

// Build structured ubigeo
const ubigeo = {};
for (const d of DEPTOS) ubigeo[d.c] = { nombre: d.n, provincias: {} };
for (const p of distBatch) {
  ubigeo[p.dc].provincias[p.pc] = { nombre: p.pn, distritos: {} };
  for (const dist of p.dists) ubigeo[p.dc].provincias[p.pc].distritos[dist.c] = dist.n;
}

// Save raw JSON
writeFileSync('scripts/ubigeo-digemid.json', JSON.stringify(ubigeo, null, 2), 'utf8');
console.log('Guardado: scripts/ubigeo-digemid.json');

// Generate PHP constant file
function phpArray(obj, indent = 1) {
  const pad = '    '.repeat(indent);
  const pad0 = '    '.repeat(indent - 1);
  const entries = Object.entries(obj).map(([k, v]) => {
    const val = typeof v === 'object' && v !== null ? phpArray(v, indent + 1) : `'${String(v).replace(/'/g, "\\'")}'`;
    return `${pad}'${k}' => ${val}`;
  });
  return `[\n${entries.join(',\n')},\n${pad0}]`;
}

// Build flat options arrays for each level
const deptosPhp = DEPTOS.map(d => `        '${d.c}' => '${d.n}'`).join(",\n");

let provinciasPhp = '';
for (const d of DEPTOS) {
  const prov = ubigeo[d.c].provincias;
  const entries = Object.entries(prov).map(([pc, pv]) => `            '${pc}' => '${pv.nombre}'`).join(",\n");
  provinciasPhp += `        '${d.c}' => [\n${entries},\n        ],\n`;
}

let distritosPhp = '';
for (const d of DEPTOS) {
  const prov = ubigeo[d.c].provincias;
  distritosPhp += `        '${d.c}' => [\n`;
  for (const [pc, pv] of Object.entries(prov)) {
    const entries = Object.entries(pv.distritos).map(([dc, dn]) => `                '${dc}' => '${dn.replace(/'/g, "\\'")}'`).join(",\n");
    distritosPhp += `            '${pc}' => [\n${entries},\n            ],\n`;
  }
  distritosPhp += `        ],\n`;
}

const phpContent = `<?php

namespace App\\Data;

/**
 * Ubigeo de Peru extraído del OPPF/DIGEMID.
 * Fuente: https://opm-digemid.minsa.gob.pe/#/consulta-producto
 * Generado: ${new Date().toISOString().slice(0,10)}
 */
class UbigeoPeru
{
    /** @return array<string,string> */
    public static function departamentos(): array
    {
        return [
${deptosPhp},
        ];
    }

    /** @return array<string,string> */
    public static function provincias(string $codDepartamento): array
    {
        return self::allProvincias()[$codDepartamento] ?? [];
    }

    /** @return array<string,string> */
    public static function distritos(string $codDepartamento, string $codProvincia): array
    {
        return self::allDistritos()[$codDepartamento][$codProvincia] ?? [];
    }

    /** @return array<string,array<string,string>> */
    private static function allProvincias(): array
    {
        return [
${provinciasPhp}        ];
    }

    /** @return array<string,array<string,array<string,string>>> */
    private static function allDistritos(): array
    {
        return [
${distritosPhp}        ];
    }
}
`;

writeFileSync('app/Data/UbigeoPeru.php', phpContent, 'utf8');
console.log('Guardado: app/Data/UbigeoPeru.php');
console.log('Listo!');
