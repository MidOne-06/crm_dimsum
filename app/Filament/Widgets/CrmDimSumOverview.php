<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class CrmDimSumOverview extends Widget
{
    protected string $view = 'filament.widgets.crm-dimsum-overview';

    protected int|string|array $columnSpan = 'full';
}
