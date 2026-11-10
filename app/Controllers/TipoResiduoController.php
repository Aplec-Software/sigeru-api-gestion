<?php

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\Request;
use App\Models\TipoResiduo;

class TipoResiduoController extends CrudController
{
    protected string $modelo = TipoResiduo::class;
    protected string $entidad = 'tipo_residuo';
    protected array $reglas = [
        'nombre' => 'required|string|min:3|max:50',
        'color' => 'required|color',
        'reciclable' => 'required|bool',
        'descripcion' => 'nullable|string|max:200',
    ];

    protected function antesDeGuardar(Request $req, array &$datos, ?array $actual): void
    {
        if (isset($datos['nombre']) && TipoResiduo::existe('nombre', $datos['nombre'], $actual['id'] ?? null)) {
            throw $this->conflicto('gestion.nombre_repetido');
        }
    }

    protected function antesDeEliminar(Request $req, array $actual): void
    {
        if (TipoResiduo::enUso((int) $actual['id'])) {
            throw $this->conflicto('gestion.tipo_en_uso');
        }
    }
}
