<?php

namespace App\Http\Controllers;

use App\Services\OpmCatalogTemplate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OpmCatalogTemplateController
{
    public function __invoke(OpmCatalogTemplate $template): BinaryFileResponse
    {
        return response()->download($template->path(), 'plantilla-catalogoproductos-digemid.xlsx');
    }
}
