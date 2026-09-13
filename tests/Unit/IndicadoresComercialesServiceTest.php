<?php

namespace Tests\Unit;

use App\Services\IndicadoresComercialesService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class IndicadoresComercialesServiceTest extends TestCase
{
    public function test_deriva_presentaciones_de_docena_desde_el_costo_unitario(): void
    {
        $service = new IndicadoresComercialesService();
        $method = new ReflectionMethod($service, 'presentacionPorDocena');
        $method->setAccessible(true);

        $this->assertSame(['SIU MAI TRADICIONAL', 6.0], $method->invoke($service, 'Siu Mai Tradicional : 1/2 DOCENA'));
        $this->assertSame(['SIU MAI TRADICIONAL', 12.0], $method->invoke($service, 'Siu Mai Tradicional : DOCENA'));
        $this->assertSame(['SIU MAI TRADICIONAL', 4.0], $method->invoke($service, 'Siu Mai Tradicional : 1/3 DOCENA'));
    }

    public function test_no_infiere_presentaciones_ambiguas(): void
    {
        $service = new IndicadoresComercialesService();
        $method = new ReflectionMethod($service, 'presentacionPorDocena');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($service, 'Siu Kao Mini : CIENTO'));
        $this->assertNull($method->invoke($service, 'Chaufa : 260 G'));
    }
}
