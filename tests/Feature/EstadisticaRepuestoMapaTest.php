<?php

namespace Tests\Feature;

use Tests\Support\TestCase;

class EstadisticaRepuestoMapaTest extends TestCase
{
    public function testPanelDeEstadisticas(): void
    {
        $res = $this->pedir('GET', '/estadisticas/resumen', [], 1);
        $this->assertSame(200, $res->status);
        $this->assertArrayHasKey('incidencias', $res->datos()['datos']);
        $this->assertSame(1, $res->datos()['datos']['centros_en_alerta']);

        foreach (['top-desbordados', 'zonas-criticas', 'tiempos-resolucion', 'incidencias-por-mes', 'ocupacion-centros', 'proyeccion-llenado'] as $ruta) {
            $res = $this->pedir('GET', "/estadisticas/$ruta?dias=60", [], 1);
            $this->assertSame(200, $res->status, $ruta);
            $this->assertNotEmpty($res->datos()['datos'], $ruta);
        }
        $this->assertSame(403, $this->pedir('GET', '/estadisticas/resumen', [], 3)->status);
    }

    public function testInformeDeIncidenciasEnCsv(): void
    {
        $res = $this->pedir('GET', '/informes/incidencias?formato=csv&desde=2026-10-01', [], 1);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('text/csv', $res->headers['Content-Type']);
        $this->assertStringContainsString('Contenedor;', $res->cuerpo);
        $json = $this->pedir('GET', '/informes/incidencias?desde=2026-10-01', [], 1)->datos();
        $this->assertSame($json['meta']['total'], $json['meta']['abiertas'] + $json['meta']['cerradas']);
    }

    public function testSalidaDeStockControlaElDisponibleYAvisa(): void
    {
        // repuesto 1: stock 6, mínimo 4
        $this->assertSame(409, $this->pedir('POST', '/repuestos/1/movimientos', ['tipo' => 'salida', 'cantidad' => 7, 'motivo' => 'Prueba'], 1)->status);
        $antes = $this->contar('notificaciones', "tipo = 'stock_bajo'");
        $res = $this->pedir('POST', '/repuestos/1/movimientos', ['tipo' => 'salida', 'cantidad' => 3, 'motivo' => 'Reposición en Buceo'], 1);
        $this->assertSame(201, $res->status);
        $this->assertSame(3, $res->datos()['datos']['stock']);
        $this->assertTrue($res->datos()['datos']['bajo_minimo']);
        $this->assertGreaterThan($antes, $this->contar('notificaciones', "tipo = 'stock_bajo'"));
    }

    public function testElStockNoSeModificaDirectamente(): void
    {
        $res = $this->pedir('PUT', '/repuestos/2', ['stock' => 500, 'stock_minimo' => 2], 1);
        $this->assertSame(200, $res->status);
        $this->assertSame(3, $res->datos()['datos']['stock']);
        $this->assertSame(2, $res->datos()['datos']['stock_minimo']);
    }

    public function testMapaPublicoYMapaDelPersonal(): void
    {
        $publico = $this->pedir('GET', '/mapa')->datos()['datos'];
        $this->assertArrayHasKey('contenedores', $publico);
        $this->assertArrayNotHasKey('incidencias', $publico);
        $admin = $this->pedir('GET', '/mapa', [], 1)->datos()['datos'];
        $this->assertArrayHasKey('incidencias', $admin);
        $this->assertCount(47, $admin['contenedores']);
    }

    public function testOperarioSoloVeLaMaquinariaDeSuCentro(): void
    {
        $res = $this->pedir('GET', '/maquinaria', [], 10);   // operario del centro 2
        foreach ($res->datos()['datos'] as $m) {
            $this->assertSame(2, $m['centro_id']);
        }
        $this->assertSame(403, $this->pedir('PUT', '/maquinaria/1/estado', ['estado' => 'mantenimiento'], 10)->status);
        $this->assertSame(200, $this->pedir('PUT', '/maquinaria/4/estado', ['estado' => 'operativa'], 10)->status);
    }

    public function testCatalogosPublicosYProtegidos(): void
    {
        $this->assertSame(200, $this->pedir('GET', '/zonas')->status);
        $this->assertSame(200, $this->pedir('GET', '/tipos-residuo')->status);
        $this->assertSame(409, $this->pedir('DELETE', '/zonas/1', [], 1)->status);
        $this->assertSame(201, $this->pedir('POST', '/zonas', ['nombre' => 'Carrasco'], 1)->status);
        $this->assertSame(409, $this->pedir('POST', '/zonas', ['nombre' => 'Carrasco'], 1)->status);
    }
}
