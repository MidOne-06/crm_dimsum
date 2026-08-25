<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

final class OpmCatalogTemplate
{
    public const FILE = 'opm_catalog_templates/plantilla-catalogoproductos-digemid.xlsx';

    public function path(): string
    {
        $disk = Storage::disk('local');

        if ($disk->exists(self::FILE)) {
            return $disk->path(self::FILE);
        }

        $disk->makeDirectory(dirname(self::FILE));
        $path = $disk->path(self::FILE);
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Guía');
        $writer->addRow(Row::fromValues(['Plantilla de carga manual · Catálogo DIGEMID']));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Hoja obligatoria', 'Catálogo']));
        $writer->addRow(Row::fromValues(['Fila de cabecera', 7]));
        $writer->addRow(Row::fromValues(['Primera fila de datos', 8]));
        $writer->addRow(Row::fromValues(['Campos obligatorios', 'Cod_Prod, Nom_Prod, Concent, Nom_Form_Farm']));
        $writer->addRow(Row::fromValues(['Regla', 'No modifique la hoja Catálogo ni sus cabeceras.']));

        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Catálogo');
        for ($row = 1; $row < 7; $row++) {
            $writer->addRow(Row::fromValues([]));
        }
        $writer->addRow(Row::fromValues([
            'Cod_Prod', 'Nom_Prod', 'Concent', 'Nom_Form_Farm', 'Presentac', 'Fracción',
            'Num_RegSan', 'Nom_Titular', 'Nom_Fabricante', 'Nom_IFA', 'Nom_Rubro', 'Situación',
        ]));
        $writer->close();

        return $path;
    }
}
