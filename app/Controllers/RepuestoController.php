<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\CrudController;
use App\Core\Lang;
use App\Core\Notifier;
use App\Core\Request;
use App\Core\Response;
use App\Models\Repuesto;
use App\Views\JsonView;

/**
 * Inventario de repuestos y contenedores de reposición.
 */
class RepuestoController extends CrudController
{
    protected string $modelo = Repuesto::class;
    protected string $entidad = 'repuesto';
    protected array $reglas = [
        'codigo' => 'required|regex:/^REP-\d{3,5}$/',
        'nombre' => 'required|string|min:3|max:100',
        'categoria' => 'required|in:contenedor,pieza_contenedor,pieza_camion,herramienta,insumo',
        'unidad' => 'required|string|max:15',
        'stock' => 'nullable|int|min:0',
        'stock_minimo' => 'required|int|min:0',
    ];

    public function vista(array $fila): array
    {
        $datos = JsonView::render($fila);
        $datos['bajo_minimo'] = $datos['stock'] < $datos['stock_minimo'];
        return $datos;
    }

    protected function filtrosExtra(Request $req): array
    {
        return ($req->query['bajo_minimo'] ?? '') === '1' ? ['t.stock < t.stock_minimo' => null] : [];
    }

    protected function antesDeGuardar(Request $req, array &$datos, ?array $actual): void
    {
        if (isset($datos['codigo']) && Repuesto::existe('codigo', $datos['codigo'], $actual['id'] ?? null)) {
            throw $this->conflicto('gestion.codigo_repetido');
        }
        // el stock de un repuesto existente solo cambia con movimientos (queda registrado quién y por qué)
        if ($actual !== null) {
            unset($datos['stock']);
        }
    }

    public function movimientos(Request $req): Response
    {
        $id = $this->idParam($req);
        Repuesto::buscar($id) ?? throw $this->noEncontrado();
        return Response::ok(array_map(fn ($m) => JsonView::render($m), Repuesto::movimientos($id)));
    }

    /** POST /repuestos/{id}/movimientos: entrada o salida de stock. */
    public function registrarMovimiento(Request $req): Response
    {
        $id = $this->idParam($req);
        $repuesto = Repuesto::buscar($id) ?? throw $this->noEncontrado();
        $datos = $this->validar($req, [
            'tipo' => 'required|in:entrada,salida',
            'cantidad' => 'required|int|min:1|max:10000',
            'motivo' => 'required|string|min:3|max:150',
        ]);
        if (!Repuesto::mover($id, $datos['tipo'], $datos['cantidad'], $datos['motivo'], $req->usuario['id'])) {
            throw $this->conflicto('gestion.stock_insuficiente', ['stock' => $repuesto['stock']]);
        }
        $actual = Repuesto::buscar($id);
        if ($datos['tipo'] === 'salida' && $actual['stock'] < $actual['stock_minimo'] && $repuesto['stock'] >= $repuesto['stock_minimo']) {
            Notifier::aRol('administrador', 'stock_bajo', Lang::t('gestion.alerta_stock_titulo'),
                Lang::t('gestion.alerta_stock', ['repuesto' => $actual['nombre'], 'stock' => $actual['stock'], 'minimo' => $actual['stock_minimo']]), '#/repuestos');
        }
        Audit::registrar($req, 'stock_' . $datos['tipo'], 'repuesto', $id, (string) $datos['cantidad']);
        return Response::ok($this->vista($actual), 201, null, Lang::t('ok.creado'));
    }
}
