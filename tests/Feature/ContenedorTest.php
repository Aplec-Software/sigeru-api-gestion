<?php

namespace Tests\Feature;

use Tests\Support\TestCase;

class ContenedorTest extends TestCase
{
    private array $nuevo = [
        'tipo_residuo_id' => 2, 'zona_id' => 1, 'direccion' => 'Av. Rivera 4321',
        'latitud' => -34.8941, 'longitud' => -56.1322, 'capacidad_litros' => 2400,
    ];

    public function testListadoPublicoConPaginacion(): void
    {
        $res = $this->pedir('GET', '/contenedores?por_pagina=10&zona_id=1');
        $this->assertSame(200, $res->status);
        $cuerpo = $res->datos();
        $this->assertCount(6, $cuerpo['datos']);
        $this->assertSame(6, $cuerpo['meta']['total']);
        $this->assertIsFloat($cuerpo['datos'][0]['latitud']);
    }

    public function testBuscarPorCodigoQr(): void
    {
        $this->assertSame(200, $this->pedir('GET', '/contenedores/codigo/CNT-0005')->status);
        $this->assertSame(404, $this->pedir('GET', '/contenedores/codigo/CNT-9999')->status);
    }

    public function testAltaGeneraCodigoYSoloLaHaceElAdministrador(): void
    {
        $this->assertSame(401, $this->pedir('POST', '/contenedores', $this->nuevo)->status);
        $this->assertSame(403, $this->pedir('POST', '/contenedores', $this->nuevo, 12)->status);
        $res = $this->pedir('POST', '/contenedores', $this->nuevo, 1);
        $this->assertSame(201, $res->status);
        $this->assertSame('CNT-0049', $res->datos()['datos']['codigo']);
        $this->assertSame('funcional', $res->datos()['datos']['estado']);
    }

    public function testValidacionesYCodigoRepetido(): void
    {
        $res = $this->pedir('POST', '/contenedores', ['capacidad_litros' => 999, 'latitud' => 200] + $this->nuevo, 1);
        $this->assertSame(422, $res->status);
        $this->assertArrayHasKey('capacidad_litros', $res->datos()['errores']);
        $this->assertArrayHasKey('latitud', $res->datos()['errores']);
        $this->assertSame(409, $this->pedir('POST', '/contenedores', ['codigo' => 'CNT-0001'] + $this->nuevo, 1)->status);
    }

    public function testModificarEstadoDesbordadoPoneElNivelAl100(): void
    {
        $res = $this->pedir('PUT', '/contenedores/3', ['estado' => 'desbordado'], 1);
        $this->assertSame(200, $res->status);
        $this->assertSame(100, $res->datos()['datos']['nivel_llenado']);
    }

    public function testBajaLogicaYRestriccionPorIncidencias(): void
    {
        $conIncidencia = (int) $this->db->query("SELECT contenedor_id FROM incidencias WHERE estado = 'abierta' LIMIT 1")->fetchColumn();
        $this->assertSame(409, $this->pedir('DELETE', "/contenedores/$conIncidencia", [], 1)->status);

        $libre = (int) $this->db->query("SELECT id FROM contenedores WHERE activo = 1 AND id NOT IN
            (SELECT contenedor_id FROM incidencias WHERE estado IN ('abierta','en_curso')) LIMIT 1")->fetchColumn();
        $this->assertSame(200, $this->pedir('DELETE', "/contenedores/$libre", [], 1)->status);
        $this->assertSame(0, (int) $this->db->query("SELECT activo FROM contenedores WHERE id = $libre")->fetchColumn());
    }
}
