<?php

namespace App\Services;

use App\Models\OpmCatalogo;
use DateTimeInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Normalizer;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use ZipArchive;

final class OpmCatalogSynchronizer
{
    public const SOURCE_URL = 'https://ms-opm.minsa.gob.pe/msopmcovid/producto/catalogoproductos';

    /** @return array{catalogo: OpmCatalogo, changed: bool} */
    public function synchronize(): array
    {
        $indexPath = $this->indexPath();
        $directory = dirname($indexPath);
        File::ensureDirectoryExists($directory, 0755, true);

        if (! is_writable($directory)) {
            throw new RuntimeException("El directorio del catálogo no permite escritura: {$directory}");
        }

        $temporaryFile = tempnam($directory, 'opm-catalog-');
        if ($temporaryFile === false) {
            throw new RuntimeException('No se pudo preparar la descarga temporal del catálogo DIGEMID.');
        }

        try {
            $response = Http::accept('application/json, text/plain, */*')
                ->withHeaders([
                    'Origin' => 'https://opm-digemid.minsa.gob.pe',
                    'Referer' => 'https://opm-digemid.minsa.gob.pe/',
                    'User-Agent' => 'OPM-DIGEMID catalog synchronizer',
                ])
                ->timeout(120)
                ->connectTimeout(20)
                ->post(self::SOURCE_URL, [
                    'filtro' => [
                        'situacion' => 'ACT',
                        'tokenGoogle' => '',
                    ],
                ]);

            $response->throw();
            $contents = $response->body();

            if (strlen($contents) < 1024 || file_put_contents($temporaryFile, $contents, LOCK_EX) === false) {
                throw new RuntimeException('La descarga del catálogo DIGEMID está vacía o no se pudo almacenar.');
            }

            $this->assertXlsx($temporaryFile);
            return $this->publishCatalog($temporaryFile, 'digemid', self::SOURCE_URL);
        } finally {
            File::delete($temporaryFile);
        }
    }

    public function active(): OpmCatalogo
    {
        $catalogo = OpmCatalogo::query()->where('activo', true)->latest('verificado_at')->first();

        if (! $catalogo || ! is_file($this->indexPath())) {
            throw new RuntimeException('No existe un catálogo DIGEMID validado. Actualice el catálogo antes de ejecutar un parámetro.');
        }

        return $catalogo;
    }

    /** @return array{catalogo: OpmCatalogo, changed: bool} */
    public function importManual(string $uploadedPath): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($uploadedPath)) {
            throw new RuntimeException('El archivo cargado no está disponible para validación.');
        }

        $sourceFile = $disk->path($uploadedPath);

        try {
            $this->assertXlsx($sourceFile);

            return $this->publishCatalog($sourceFile, 'manual', 'Carga manual desde el panel OPM');
        } finally {
            $disk->delete($uploadedPath);
        }
    }

    public function activeLabel(): string
    {
        $catalogo = $this->active();

        return sprintf(
            '%s · %s · %s filas · %s',
            $catalogo->tipo_origen === 'manual' ? 'Manual' : 'DIGEMID',
            $catalogo->verificado_at?->format('d/m/Y H:i') ?? 'sin fecha',
            number_format($catalogo->total_filas),
            substr($catalogo->sha256, 0, 12),
        );
    }

    public function indexPath(): string
    {
        $path = trim((string) env('OPM_CATALOG_INDEX', ''));

        if ($path === '') {
            throw new RuntimeException('OPM_CATALOG_INDEX es obligatoria para usar el catálogo DIGEMID.');
        }

        return $path;
    }

    /** @return array{sheet: string, firstRow: int, lastRow: int, rowCount: int, uniqueNameCount: int, uniqueCombinationCount: int, rows: array<int, array<string, mixed>>} */
    private function parseWorkbook(string $file): array
    {
        $reader = new Reader();
        $reader->open($file);

        try {
            $sheet = collect(iterator_to_array($reader->getSheetIterator()))
                ->first(fn ($item): bool => $this->normalizeHeader($item->getName()) === 'catalogo');

            if (! $sheet) {
                throw new RuntimeException('El Excel oficial no contiene la hoja "Catálogo".');
            }

            $header = null;
            $firstRow = null;
            $lastRow = 0;
            $rows = [];
            $rowNumber = 0;

            foreach ($sheet->getRowIterator() as $spreadsheetRow) {
                $rowNumber++;
                $values = array_map(fn ($value) => $this->scalar($value), $spreadsheetRow->toArray());

                if ($header === null) {
                    $candidate = $this->buildHeader($values);
                    if (isset($candidate['Nom_Prod']) && isset($candidate['Cod_Prod'])) {
                        $header = $candidate;
                        $firstRow = $rowNumber + 1;
                    }

                    continue;
                }

                $lastRow = $rowNumber;
                $row = [];
                foreach ($header as $field => $column) {
                    $row[$field] = $values[$column] ?? null;
                }

                if (blank($row['Nom_Prod'] ?? null)) {
                    continue;
                }

                $row['filaCatalogo'] = $rowNumber;
                $row['nameKey'] = $this->normalizeText($row['Nom_Prod']);
                $row['combinationKey'] = implode('||', [
                    $row['nameKey'],
                    $this->normalizeConcentration($row['Concent'] ?? null),
                    $this->normalizeText($row['Nom_Form_Farm'] ?? null),
                ]);
                $rows[] = $row;
            }

            if ($header === null || $rows === []) {
                throw new RuntimeException('El Excel oficial no tiene una cabecera o filas de catálogo válidas.');
            }

            return [
                'sheet' => $sheet->getName(),
                'firstRow' => $firstRow,
                'lastRow' => $lastRow,
                'rowCount' => count($rows),
                'uniqueNameCount' => count(array_unique(array_column($rows, 'nameKey'))),
                'uniqueCombinationCount' => count(array_unique(array_column($rows, 'combinationKey'))),
                'rows' => $rows,
            ];
        } finally {
            $reader->close();
        }
    }

    /** @return array{catalogo: OpmCatalogo, changed: bool} */
    private function publishCatalog(string $sourceFile, string $originType, string $sourceUrl): array
    {
        $indexPath = $this->indexPath();
        $directory = dirname($indexPath);
        File::ensureDirectoryExists($directory, 0755, true);

        if (! is_writable($directory)) {
            throw new RuntimeException("El directorio del catálogo no permite escritura: {$directory}");
        }
        $contents = file_get_contents($sourceFile);

        if ($contents === false) {
            throw new RuntimeException('No se pudo leer el archivo de catálogo para publicarlo.');
        }

        $parsed = $this->parseWorkbook($sourceFile);
        $sourceSha256 = hash_file('sha256', $sourceFile);
        $sha256 = hash('sha256', json_encode(
            $parsed['rows'],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        ));
        $archivePath = $directory.DIRECTORY_SEPARATOR."catalogoproductos-{$sourceSha256}.xlsx";

        if (! is_file($archivePath)) {
            $this->atomicWrite($archivePath, $contents);
        }

        $existing = OpmCatalogo::query()->where('sha256', $sha256)->first();
        $index = [
            'preparedAt' => now()->toIso8601String(),
            'source' => $sourceUrl,
            'sourceType' => $originType,
            'sourceFile' => basename($archivePath),
            'sha256' => $sha256,
            'sourceSha256' => $sourceSha256,
            ...$parsed,
        ];
        $this->atomicWrite($indexPath, json_encode(
            $index,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        ));

        OpmCatalogo::query()->where('activo', true)->where('sha256', '!=', $sha256)->update(['activo' => false]);

        $catalogo = OpmCatalogo::updateOrCreate(
            ['sha256' => $sha256],
            [
                'tipo_origen' => $originType,
                'origen_url' => $sourceUrl,
                'archivo_fuente' => $archivePath,
                'ruta_indice' => $indexPath,
                'hoja' => $parsed['sheet'],
                'total_filas' => $parsed['rowCount'],
                'total_nombres_unicos' => $parsed['uniqueNameCount'],
                'total_combinaciones_unicas' => $parsed['uniqueCombinationCount'],
                'activo' => true,
                'obtenido_at' => $existing?->obtenido_at ?? now(),
                'verificado_at' => now(),
            ],
        );

        return ['catalogo' => $catalogo, 'changed' => $existing === null];
    }

    /** @param array<int, mixed> $headers @return array<string, int> */
    private function buildHeader(array $headers): array
    {
        $aliases = [
            'Cod_Prod' => ['codprod', 'codigoprod', 'codigoproducto'],
            'Nom_Prod' => ['nomprod', 'nombreproducto'],
            'Concent' => ['concent', 'concentracion'],
            'Nom_Form_Farm' => ['nomformfarm', 'nombreformafarmaceutica'],
            'Presentac' => ['presentac', 'presentacion'],
            'Fracción' => ['fraccion', 'fracciones'],
            'Num_RegSan' => ['numregsan', 'numeroregistrosanitario'],
            'Nom_Titular' => ['nomtitular', 'nombretitular'],
            'Nom_Fabricante' => ['nomfabricante', 'nombrefabricante'],
            'Nom_IFA' => ['nomifa', 'nombreifa'],
            'Nom_Rubro' => ['nomrubro', 'nombrerubro'],
            'Situación' => ['situacion'],
        ];
        $found = [];

        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader($header);
            foreach ($aliases as $field => $candidates) {
                if (in_array($normalized, $candidates, true)) {
                    $found[$field] = $index;
                }
            }
        }

        foreach (['Cod_Prod', 'Nom_Prod', 'Concent', 'Nom_Form_Farm'] as $required) {
            if (! array_key_exists($required, $found)) {
                return [];
            }
        }

        return $found;
    }

    private function assertXlsx(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true
            || $zip->locateName('[Content_Types].xml') === false
            || $zip->locateName('xl/workbook.xml') === false) {
            throw new RuntimeException('La respuesta de DIGEMID no corresponde a un archivo Excel XLSX válido.');
        }
        $zip->close();
    }

    private function atomicWrite(string $destination, string $contents): void
    {
        $temporary = $destination.'.'.Str::uuid().'.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || ! rename($temporary, $destination)) {
            File::delete($temporary);
            throw new RuntimeException("No se pudo publicar el archivo de catálogo: {$destination}");
        }
    }

    private function scalar(mixed $value): string|int|float|bool|null
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('c');
        }

        return is_scalar($value) || $value === null ? $value : json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function normalizeHeader(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii((string) $value))) ?? '';
    }

    private function normalizeText(mixed $value): string
    {
        $text = Normalizer::normalize((string) ($value ?? ''), Normalizer::FORM_D) ?: (string) ($value ?? '');

        return strtoupper(Str::ascii(trim((string) preg_replace('/\p{Mn}+/u', '', $text))));
    }

    private function normalizeConcentration(mixed $value): string
    {
        return str_replace([' ', ','], ['', '.'], str_replace(['µ', 'μ'], 'U', $this->normalizeText($value)));
    }
}
