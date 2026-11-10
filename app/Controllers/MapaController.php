<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Views\JsonView;

/**
 * Datos del mapa interactivo. Cualquier persona ve contenedores y centros;
 * el personal municipal además ve las incidencias activas y los camiones.
 */
class MapaController extends Controller
{
    public function index(Request $req): Response
    {
        $db = Database::conexion();
        $render = fn (array $filas) => array_map(fn ($f) => JsonView::render($f), $filas);

        $datos = [
            'contenedores' => $render($db->query("SELECT c.id, c.codigo, c.direccion, c.latitud, c.longitud, c.estado, c.nivel_llenado,
                    tr.nombre AS tipo_residuo, tr.color, z.nombre AS zona
                FROM contenedores c JOIN tipos_residuo tr ON tr.id = c.tipo_residuo_id JOIN zonas z ON z.id = c.zona_id
                WHERE c.activo = 1")->fetchAll()),
            'centros' => $render($db->query("SELECT id, nombre, tipo, direccion, latitud, longitud,
                    ROUND(ocupacion_ton * 100 / capacidad_ton, 1) AS porcentaje FROM centros WHERE activo = 1")->fetchAll()),
        ];

        $rol = $req->usuario['rol'] ?? null;
        if (in_array($rol, ['administrador', 'cuadrilla'], true)) {
            $datos['incidencias'] = $render($db->query("SELECT i.id, i.tipo, i.estado, i.prioridad, i.creado_en, c.codigo AS contenedor_codigo,
                    c.latitud, c.longitud, c.direccion
                FROM incidencias i JOIN contenedores c ON c.id = i.contenedor_id
                WHERE i.estado IN ('abierta','en_curso')")->fetchAll());
            $datos['camiones'] = $render($db->query("SELECT id, matricula, estado, ultima_latitud AS latitud, ultima_longitud AS longitud, ultima_posicion_en
                FROM camiones WHERE activo = 1 AND ultima_latitud IS NOT NULL")->fetchAll());
        }
        return Response::ok($datos);
    }
}
