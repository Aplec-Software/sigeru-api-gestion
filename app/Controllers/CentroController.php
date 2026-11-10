<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\CrudController;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Lang;
use App\Core\Notifier;
use App\Core\Params;
use App\Core\Request;
use App\Core\Response;
use App\Models\Centro;
use App\Models\MovimientoCentro;
use App\Models\Zona;
use App\Views\JsonView;

/**
 * Centros de acopio, plantas de clasificación y vertederos.
 */
class CentroController extends CrudController
{
    protected string $modelo = Centro::class;
    protected string $entidad = 'centro';
    protected array $reglas = [
        'nombre' => 'required|string|min:3|max:100',
        'tipo' => 'required|in:acopio,clasificacion,vertedero',
        'zona_id' => 'required|int',
        'direccion' => 'required|string|min:5|max:150',
        'latitud' => 'required|lat',
        'longitud' => 'required|lng',
        'capacidad_ton' => 'required|numeric|min:1|max:1000000',
        'telefono' => 'nullable|telefono',
        'tipos' => 'required|array',
    ];

    public function vista(array $fila): array
    {
        $datos = JsonView::render($fila);
        $datos['tipos'] = array_map(fn ($t) => JsonView::render($t), Centro::tiposAceptados((int) $fila['id']));
        return $datos;
    }

    protected function antesDeGuardar(Request $req, array &$datos, ?array $actual): void
    {
        if (isset($datos['nombre']) && Centro::existe('nombre', $datos['nombre'], $actual['id'] ?? null)) {
            throw $this->conflicto('gestion.nombre_repetido');
        }
        if (isset($datos['zona_id']) && !Zona::buscar($datos['zona_id'])) {
            throw $this->conflicto('gestion.zona_inexistente');
        }
        // RNE: la capacidad no puede quedar por debajo de lo que ya está almacenado
        if ($actual !== null && isset($datos['capacidad_ton']) && $datos['capacidad_ton'] < (float) $actual['ocupacion_ton']) {
            throw $this->conflicto('gestion.capacidad_menor_ocupacion');
        }
        if (isset($datos['tipos']) && (!$datos['tipos'] || array_filter($datos['tipos'], fn ($t) => !is_numeric($t)))) {
            throw new HttpException(422, Lang::t('error.validacion'), ['tipos' => Lang::t('gestion.tipos_requeridos')]);
        }
    }

    protected function despuesDeGuardar(Request $req, int $id, array $datos, ?array $actual): void
    {
        if (isset($datos['tipos'])) {
            Centro::guardarTipos($id, $datos['tipos']);
        }
    }

    // ------------------------------------------------ ingresos y egresos

    public function movimientos(Request $req): Response
    {
        $centro = Centro::buscar($this->idParam($req)) ?? throw $this->noEncontrado();
        $this->verificarOperario($req, $centro);
        $porPagina = $req->queryInt('por_pagina', 20, 200);
        [$filas, $total] = MovimientoCentro::listar(['centro_id' => $centro['id']] + $req->query, $req->queryInt('pagina', 1), $porPagina);
        return Response::ok(array_map(fn ($f) => JsonView::render($f), $filas), 200, $this->meta($req, $total, $porPagina));
    }

    /**
     * POST /centros/{id}/movimientos: el operario registra lo que entra o sale.
     * Se controla que el centro acepte ese tipo de residuo y que no se supere su capacidad.
     */
    public function registrarMovimiento(Request $req): Response
    {
        $centro = Centro::buscar($this->idParam($req)) ?? throw $this->noEncontrado();
        $this->verificarOperario($req, $centro);
        $datos = $this->validar($req, [
            'tipo_movimiento' => 'required|in:ingreso,egreso',
            'tipo_residuo_id' => 'required|int',
            'peso_ton' => 'required|numeric|min:0.01|max:500',
            'camion_id' => 'nullable|int',
            'destino' => 'nullable|string|max:100',
            'observacion' => 'nullable|string|max:200',
        ]);
        if (!Centro::aceptaTipo((int) $centro['id'], $datos['tipo_residuo_id'])) {
            throw new HttpException(422, Lang::t('error.validacion'), ['tipo_residuo_id' => Lang::t('gestion.tipo_no_aceptado')]);
        }
        $ocupacion = (float) $centro['ocupacion_ton'];
        $capacidad = (float) $centro['capacidad_ton'];
        $variacion = $datos['tipo_movimiento'] === 'ingreso' ? $datos['peso_ton'] : -$datos['peso_ton'];
        if ($ocupacion + $variacion > $capacidad) {
            throw $this->conflicto('gestion.sin_capacidad', ['libre' => round($capacidad - $ocupacion, 2)]);
        }
        if ($ocupacion + $variacion < 0) {
            throw $this->conflicto('gestion.egreso_mayor_ocupacion');
        }

        $db = Database::conexion();
        $db->beginTransaction();
        try {
            $id = MovimientoCentro::crear($datos + [
                'centro_id' => $centro['id'],
                'usuario_id' => $req->usuario['id'],
                'creado_en' => Clock::ahora(),
            ]);
            Centro::sumarOcupacion((int) $centro['id'], $variacion);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Alerta cuando el centro cruza el umbral de ocupación configurado
        $umbral = Params::int('umbral_ocupacion_centro', 85);
        $antes = $ocupacion * 100 / $capacidad;
        $despues = ($ocupacion + $variacion) * 100 / $capacidad;
        if ($antes < $umbral && $despues >= $umbral) {
            $mensaje = Lang::t('gestion.alerta_capacidad', ['centro' => $centro['nombre'], 'porcentaje' => round($despues)]);
            Notifier::aRol('administrador', 'capacidad_centro', Lang::t('gestion.alerta_capacidad_titulo'), $mensaje, '#/centros');
        }
        Audit::registrar($req, $datos['tipo_movimiento'], 'centro', $centro['id'], $datos['peso_ton'] . ' t');
        return Response::ok(JsonView::render(MovimientoCentro::buscar($id)), 201, null, Lang::t('ok.creado'));
    }

    /** Un operario solo puede trabajar con el centro al que está asignado. */
    private function verificarOperario(Request $req, array $centro): void
    {
        if ($req->usuario['rol'] !== 'operario') {
            return;
        }
        $st = Database::conexion()->prepare('SELECT centro_id FROM usuarios WHERE id = ?');
        $st->execute([$req->usuario['id']]);
        if ((int) $st->fetchColumn() !== (int) $centro['id']) {
            throw $this->prohibido();
        }
    }
}
