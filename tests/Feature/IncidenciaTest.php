<?php

namespace Tests\Feature;

use Tests\Support\TestCase;

class IncidenciaTest extends TestCase
{
    private function contenedorSinIncidencias(): int
    {
        return (int) $this->db->query("SELECT id FROM contenedores WHERE activo = 1 AND estado = 'funcional' AND id NOT IN
            (SELECT contenedor_id FROM incidencias WHERE estado IN ('abierta','en_curso')) LIMIT 1")->fetchColumn();
    }

    public function testVecinoReportaDesbordeYSeAvisaAlAdministrador(): void
    {
        $id = $this->contenedorSinIncidencias();
        $antes = $this->contar('notificaciones', 'usuario_id = 1');
        $res = $this->pedir('POST', '/incidencias', ['contenedor_id' => $id, 'tipo' => 'desborde'], 12);
        $this->assertSame(201, $res->status);
        $this->assertSame('abierta', $res->datos()['datos']['estado']);
        $this->assertSame('vecino', $res->datos()['datos']['origen']);
        $this->assertSame('desbordado', $this->db->query("SELECT estado FROM contenedores WHERE id = $id")->fetchColumn());
        $this->assertSame($antes + 1, $this->contar('notificaciones', 'usuario_id = 1'));
    }

    public function testUnDesbordeNoBorraQueElContenedorEstaRoto(): void
    {
        // D-15: un contenedor roto que además se llena sigue roto (es lo más grave) y queda con el nivel al 100 %
        $id = $this->contenedorSinIncidencias();
        $this->pedir('POST', '/incidencias', ['contenedor_id' => $id, 'tipo' => 'rotura'], 12);
        $this->assertSame('roto', $this->db->query("SELECT estado FROM contenedores WHERE id = $id")->fetchColumn());
        $res = $this->pedir('POST', '/incidencias', ['contenedor_id' => $id, 'tipo' => 'desborde'], 12);
        $this->assertSame(201, $res->status);
        $fila = $this->db->query("SELECT estado, nivel_llenado FROM contenedores WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('roto', $fila['estado']);
        $this->assertEquals(100, $fila['nivel_llenado']);
    }

    public function testReporteRepetidoSeSumaALaIncidenciaExistente(): void
    {
        $id = $this->contenedorSinIncidencias();
        $primero = $this->pedir('POST', '/incidencias', ['contenedor_id' => $id, 'tipo' => 'desborde'], 12)->datos()['datos'];
        $segundo = $this->pedir('POST', '/incidencias', ['contenedor_id' => $id, 'tipo' => 'desborde'], 13);
        $this->assertSame(200, $segundo->status);
        $this->assertSame($primero['id'], $segundo->datos()['datos']['id']);
        $this->assertSame(2, $segundo->datos()['datos']['cantidad_reportes']);
    }

    public function testReporteAnonimoPorQrConLimitePorHora(): void
    {
        $codigo = $this->db->query('SELECT codigo FROM contenedores WHERE id = ' . $this->contenedorSinIncidencias())->fetchColumn();
        $res = $this->pedir('POST', '/incidencias/qr', ['contenedor_codigo' => $codigo, 'tipo' => 'rotura']);
        $this->assertSame(201, $res->status);
        $this->assertSame('qr', $res->datos()['datos']['origen']);

        $tipos = ['quema', 'falta_recoleccion', 'otro', 'desborde', 'desborde'];
        $ultimo = null;
        foreach ($tipos as $tipo) {
            $ultimo = $this->pedir('POST', '/incidencias/qr', ['contenedor_codigo' => 'CNT-0010', 'tipo' => $tipo]);
        }
        $this->assertSame(429, $ultimo->status);
    }

    public function testElReporteAnonimoNoMuestraDatosDeQuienReportoAntes(): void
    {
        // un vecino reporta un desborde y después alguien sin cuenta reporta lo mismo por QR
        $id = $this->contenedorSinIncidencias();
        $codigo = $this->db->query("SELECT codigo FROM contenedores WHERE id = $id")->fetchColumn();
        $this->pedir('POST', '/incidencias', ['contenedor_id' => $id, 'tipo' => 'desborde'], 12);
        $res = $this->pedir('POST', '/incidencias/qr', ['contenedor_codigo' => $codigo, 'tipo' => 'desborde']);
        $this->assertSame(200, $res->status);
        $datos = $res->datos()['datos'];
        $this->assertTrue($datos['sumado']);
        $this->assertSame(2, $datos['cantidad_reportes']);
        foreach (['reportado_por', 'reportado_nombre', 'reportado_apellido', 'ip_hash', 'cuadrilla', 'cuadrilla_id'] as $campo) {
            $this->assertArrayNotHasKey($campo, $datos);
        }
        // el administrador sigue viendo la incidencia completa, pero nunca el hash de la IP
        $completa = $this->pedir('GET', '/incidencias/' . $datos['id'], [], 1)->datos()['datos'];
        $this->assertArrayHasKey('reportado_nombre', $completa);
        $this->assertArrayNotHasKey('ip_hash', $completa);
    }

    public function testCadaRolVeSoloLoQueLeCorresponde(): void
    {
        $res = $this->pedir('GET', '/incidencias?por_pagina=200', [], 3);   // cuadrilla 1
        foreach ($res->datos()['datos'] as $i) {
            $this->assertSame(1, $i['cuadrilla_id']);
        }
        $res = $this->pedir('GET', '/incidencias?por_pagina=200', [], 12);  // vecino
        foreach ($res->datos()['datos'] as $i) {
            $this->assertSame(12, $i['reportado_por']);
        }
        $ajena = (int) $this->db->query('SELECT id FROM incidencias WHERE reportado_por IS NULL LIMIT 1')->fetchColumn();
        $this->assertSame(404, $this->pedir('GET', "/incidencias/$ajena", [], 12)->status);
    }

    public function testFlujoCompletoAsignarYResolver(): void
    {
        $id = $this->contenedorSinIncidencias();
        $inc = $this->pedir('POST', '/incidencias', ['contenedor_id' => $id, 'tipo' => 'desborde'], 12)->datos()['datos'];

        $res = $this->pedir('PUT', "/incidencias/{$inc['id']}/asignar", ['cuadrilla_id' => 1], 1);
        $this->assertSame(200, $res->status);
        $this->assertSame('en_curso', $res->datos()['datos']['estado']);
        $this->assertSame(1, $this->contar('notificaciones', "usuario_id = 3 AND tipo = 'incidencia_asignada' AND leida = 0") - 1);

        // sin comentario no se puede cerrar
        $this->assertSame(422, $this->pedir('PUT', "/incidencias/{$inc['id']}/estado", ['estado' => 'resuelta'], 3)->status);
        $res = $this->pedir('PUT', "/incidencias/{$inc['id']}/estado", ['estado' => 'resuelta', 'comentario' => 'Se vació el contenedor'], 3);
        $this->assertSame(200, $res->status);
        $this->assertSame('resuelta', $res->datos()['datos']['estado']);
        $this->assertSame('funcional', $this->db->query("SELECT estado FROM contenedores WHERE id = $id")->fetchColumn());
        $this->assertSame(1, $this->contar('notificaciones', "usuario_id = 12 AND tipo = 'incidencia_resuelta'"));
        $this->assertSame(3, $this->contar('incidencia_historial', "incidencia_id = {$inc['id']}"));
    }

    public function testTransicionInvalidaDevuelve409(): void
    {
        $resuelta = (int) $this->db->query("SELECT id FROM incidencias WHERE estado = 'resuelta' LIMIT 1")->fetchColumn();
        $this->assertSame(409, $this->pedir('PUT', "/incidencias/$resuelta/estado", ['estado' => 'descartada'], 1)->status);
        $this->assertSame(200, $this->pedir('PUT', "/incidencias/$resuelta/estado", ['estado' => 'abierta', 'comentario' => 'Volvió a pasar'], 1)->status);
    }

    public function testLaQuemaTienePrioridadAlta(): void
    {
        $res = $this->pedir('POST', '/incidencias', ['contenedor_id' => $this->contenedorSinIncidencias(), 'tipo' => 'quema'], 13);
        $this->assertSame('alta', $res->datos()['datos']['prioridad']);
    }

    /**
     * Caja blanca de la tabla de decisión de la prioridad (IncidenciaController::prioridadSugerida):
     * un caso por cada camino de la función.
     */
    public function testPrioridadSugeridaSegunTipoYOrigen(): void
    {
        $casos = [
            // [contenedor, tipo, usuario, prioridad esperada, camino]
            [2, 'rotura', 3, 'alta', 'rotura reportada por una cuadrilla'],
            [3, 'rotura', 12, 'media', 'rotura reportada por un vecino'],
            [7, 'desborde', 12, 'alta', 'desborde en un contenedor con 2 desbordes en los últimos 30 días'],
            [4, 'desborde', 12, 'media', 'desborde en un contenedor no crítico'],
            [6, 'falta_recoleccion', 12, 'media', 'falta de recolección'],
            [8, 'otro', 12, 'baja', 'otro problema'],
        ];
        foreach ($casos as [$contenedor, $tipo, $usuario, $esperada, $camino]) {
            $res = $this->pedir('POST', '/incidencias', ['contenedor_id' => $contenedor, 'tipo' => $tipo], $usuario);
            $this->assertSame(201, $res->status, "No se creó la incidencia ($camino)");
            $this->assertSame($esperada, $res->datos()['datos']['prioridad'], "Prioridad incorrecta: $camino");
        }
    }

    public function testNoSeReportaSobreUnContenedorFueraDeServicio(): void
    {
        $this->assertSame(409, $this->pedir('POST', '/incidencias', ['contenedor_id' => 48, 'tipo' => 'desborde'], 12)->status);
    }
}
