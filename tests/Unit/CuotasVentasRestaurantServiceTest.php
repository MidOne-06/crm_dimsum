<?php

namespace Tests\Unit;

use App\Services\CuotasVentasRestaurantService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CuotasVentasRestaurantServiceTest extends TestCase
{
    public function test_acepta_importes_excel_con_formatos_peruanos_e_internacionales(): void
    {
        $service = new CuotasVentasRestaurantService();
        $method = new ReflectionMethod($service, 'numero');
        $method->setAccessible(true);

        $this->assertSame(44200.0, $method->invoke($service, '44.200,00', 'TSW003', 'sin IGV'));
        $this->assertSame(44200.0, $method->invoke($service, '44,200.00', 'TSW003', 'sin IGV'));
        $this->assertSame(0.0, $method->invoke($service, 0, 'TSW003', 'sin IGV'));
    }

    public function test_rechaza_importes_negativos(): void
    {
        $service = new CuotasVentasRestaurantService();
        $method = new ReflectionMethod($service, 'numero');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $method->invoke($service, '-1', 'TSW003', 'sin IGV');
    }
}
