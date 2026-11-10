<?php

namespace Tests\Feature;

use Tests\Support\TestCase;

class CentroTest extends TestCase
{
    public function testAltaConTiposAceptados(): void
    {
        $res = $this->pedir('POST', '/centros', [
            'nombre' => 'Punto Limpio Buceo', 'tipo' => 'acopio', 'zona_id' => 1, 'direccion' => 'Av. Comercio 1345',
            'latitud' => -34.8930, 'longitud' => -56.1310, 'capacidad_ton' => 40, 'tipos' => [2, 3],
        ], 1);
        $this->assertSame(201, $res->status);
        $this->assertCount(2, $res->datos()['datos']['tipos']);
    }

    public function testOperarioRegistraIngresoEnSuCentro(): void
    {
        // usuario 9 = operario del centro 1 (acepta reciclables, vidrio y electrónicos)
        $antes = (float) $this->db->query('SELECT ocupacion_ton FROM centros WHERE id = 1')->fetchColumn();
        $res = $this->pedir('POST', '/centros/1/movimientos', ['tipo_movimiento' => 'ingreso', 'tipo_residuo_id' => 2, 'peso_ton' => 1.5], 9);
        $this->assertSame(201, $res->status);
        $despues = (float) $this->db->query('SELECT ocupacion_ton FROM centros WHERE id = 1')->fetchColumn();
        $this->assertEquals($antes + 1.5, $despues);
    }

    public function testTipoNoAceptadoYCapacidadInsuficiente(): void
    {
        $this->assertSame(422, $this->pedir('POST', '/centros/1/movimientos', ['tipo_movimiento' => 'ingreso', 'tipo_residuo_id' => 1, 'peso_ton' => 1], 9)->status);
        $this->assertSame(409, $this->pedir('POST', '/centros/1/movimientos', ['tipo_movimiento' => 'ingreso', 'tipo_residuo_id' => 2, 'peso_ton' => 400], 9)->status);
    }

    public function testOperarioNoPuedeOperarOtroCentro(): void
    {
        $this->assertSame(403, $this->pedir('POST', '/centros/2/movimientos', ['tipo_movimiento' => 'ingreso', 'tipo_residuo_id' => 2, 'peso_ton' => 1], 9)->status);
    }

    public function testAvisaAlCruzarElUmbralDeOcupacion(): void
    {
        // centro 1: 84,5 de 120 t (70 %). Con 18 t más pasa el 85 %.
        $antes = $this->contar('notificaciones', "tipo = 'capacidad_centro'");
        $this->pedir('POST', '/centros/1/movimientos', ['tipo_movimiento' => 'ingreso', 'tipo_residuo_id' => 3, 'peso_ton' => 18], 9);
        $this->assertGreaterThan($antes, $this->contar('notificaciones', "tipo = 'capacidad_centro'"));
    }

    public function testEgresoLiberaCapacidad(): void
    {
        $res = $this->pedir('POST', '/centros/3/movimientos', ['tipo_movimiento' => 'egreso', 'tipo_residuo_id' => 2, 'peso_ton' => 20, 'destino' => 'Planta recicladora'], 11);
        $this->assertSame(201, $res->status);
        $this->assertEquals(35.4, (float) $this->db->query('SELECT ocupacion_ton FROM centros WHERE id = 3')->fetchColumn());
    }
}
