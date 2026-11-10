<?php

namespace App\Controllers;

use App\Core\Clock;
use App\Core\CrudController;
use App\Core\Request;
use App\Core\Response;
use App\Models\Contenedor;
use App\Models\TipoResiduo;
use App\Models\Zona;
use App\Views\JsonView;

/**
 * ABM de contenedores. La consulta es pública (la usan el mapa de la landing
 * y el reporte por código QR); el alta, la baja y la modificación son del administrador.
 */
class ContenedorController extends CrudController
{
    protected string $modelo = Contenedor::class;
    protected string $entidad = 'contenedor';
    protected array $reglas = [
        'codigo' => 'nullable|regex:/^CNT-\d{4,6}$/',
        'tipo_residuo_id' => 'required|int',
        'zona_id' => 'required|int',
        'direccion' => 'required|string|min:5|max:150',
        'latitud' => 'required|lat',
        'longitud' => 'required|lng',
        'capacidad_litros' => 'required|int|in:360,770,1100,2400,3200',
        'estado' => 'nullable|in:funcional,desbordado,roto,fuera_servicio',
        'nivel_llenado' => 'nullable|int|min:0|max:100',
        'fecha_instalacion' => 'nullable|date',
    ];

    protected function antesDeGuardar(Request $req, array &$datos, ?array $actual): void
    {
        if ($actual === null && empty($datos['codigo'])) {
            $datos['codigo'] = Contenedor::siguienteCodigo();
        }
        if (isset($datos['codigo']) && Contenedor::existe('codigo', $datos['codigo'], $actual['id'] ?? null)) {
            throw $this->conflicto('gestion.codigo_repetido');
        }
        if (isset($datos['zona_id']) && !Zona::buscar($datos['zona_id'])) {
            throw $this->conflicto('gestion.zona_inexistente');
        }
        if (isset($datos['tipo_residuo_id']) && !TipoResiduo::buscar($datos['tipo_residuo_id'])) {
            throw $this->conflicto('gestion.tipo_inexistente');
        }
        // RNE: un contenedor desbordado tiene el nivel al 100 %
        if (($datos['estado'] ?? null) === 'desbordado') {
            $datos['nivel_llenado'] = 100;
        }
        if ($actual === null) {
            $datos['fecha_instalacion'] = $datos['fecha_instalacion'] ?? Clock::hoy();
        }
    }

    protected function antesDeEliminar(Request $req, array $actual): void
    {
        // RNE: no se da de baja un contenedor con incidencias sin resolver
        if (Contenedor::incidenciasActivas((int) $actual['id']) > 0) {
            throw $this->conflicto('gestion.contenedor_con_incidencias');
        }
    }

    /** GET /contenedores/codigo/{codigo}: lo usa la página que abre el código QR. */
    public function porCodigo(Request $req): Response
    {
        $fila = Contenedor::porCodigo((string) $req->param('codigo'));
        if ($fila === null || !$fila['activo']) {
            throw $this->noEncontrado();
        }
        return Response::ok($this->vista($fila));
    }

    public function historial(Request $req): Response
    {
        Contenedor::buscar($this->idParam($req)) ?? throw $this->noEncontrado();
        $eventos = array_map(fn ($e) => JsonView::render($e), Contenedor::historial($this->idParam($req)));
        return Response::ok($eventos);
    }
}
