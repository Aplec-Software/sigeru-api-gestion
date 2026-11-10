<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Models\Contenedor;
use App\Models\Incidencia;
use App\Views\JsonView;

/**
 * Informes simples con exportación a CSV (?formato=csv).
 */
class InformeController extends Controller
{
    /** Listado de incidencias abiertas y cerradas en un período. */
    public function incidencias(Request $req): Response
    {
        $extra = [];
        if (!empty($req->query['desde'])) {
            $extra['t.creado_en >= ?'] = $req->query['desde'];
        }
        if (!empty($req->query['hasta'])) {
            $extra['t.creado_en <= ?'] = $req->query['hasta'] . ' 23:59:59';
        }
        if (!empty($req->query['zona_id'])) {
            $extra['c.zona_id = ?'] = $req->query['zona_id'];
        }
        [$filas, $total] = Incidencia::listar($req->query, 1, 5000, $extra);
        $abiertas = count(array_filter($filas, fn ($f) => in_array($f['estado'], ['abierta', 'en_curso'], true)));

        if (($req->query['formato'] ?? '') === 'csv') {
            $columnas = ['ID', 'Fecha', 'Contenedor', 'Dirección', 'Zona', 'Tipo', 'Prioridad', 'Estado', 'Cuadrilla', 'Resuelta'];
            $datos = array_map(fn ($f) => [$f['id'], $f['creado_en'], $f['contenedor_codigo'], $f['direccion'], $f['zona'],
                $f['tipo'], $f['prioridad'], $f['estado'], $f['cuadrilla'], $f['resuelto_en']], $filas);
            return Response::csv('incidencias.csv', $columnas, $datos);
        }
        return Response::ok(array_map(fn ($f) => JsonView::render($f), $filas), 200, [
            'total' => $total,
            'abiertas' => $abiertas,
            'cerradas' => $total - $abiertas,
        ]);
    }

    /** Inventario de contenedores por estado (incluye los dados de baja). */
    public function contenedores(Request $req): Response
    {
        [$filas] = Contenedor::listar($req->query + ['incluir_bajas' => 1], 1, 5000);
        if (($req->query['formato'] ?? '') === 'csv') {
            $columnas = ['Código', 'Tipo', 'Zona', 'Dirección', 'Capacidad (L)', 'Estado', 'Nivel %', 'Última recolección', 'Activo'];
            $datos = array_map(fn ($f) => [$f['codigo'], $f['tipo_residuo'], $f['zona'], $f['direccion'], $f['capacidad_litros'],
                $f['estado'], $f['nivel_llenado'], $f['ultima_recoleccion'], $f['activo'] ? Lang::t('gestion.si') : Lang::t('gestion.no')], $filas);
            return Response::csv('contenedores.csv', $columnas, $datos);
        }
        return Response::ok(array_map(fn ($f) => JsonView::render($f), $filas));
    }
}
