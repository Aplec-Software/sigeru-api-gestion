<?php

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\Request;
use App\Models\Zona;

class ZonaController extends CrudController
{
    protected string $modelo = Zona::class;
    protected string $entidad = 'zona';
    protected array $reglas = [
        'nombre' => 'required|string|min:2|max:60',
        'descripcion' => 'nullable|string|max:200',
        'latitud' => 'nullable|lat',
        'longitud' => 'nullable|lng',
    ];

    protected function antesDeGuardar(Request $req, array &$datos, ?array $actual): void
    {
        if (isset($datos['nombre']) && Zona::existe('nombre', $datos['nombre'], $actual['id'] ?? null)) {
            throw $this->conflicto('gestion.nombre_repetido');
        }
    }

    protected function antesDeEliminar(Request $req, array $actual): void
    {
        if (Zona::enUso((int) $actual['id'])) {
            throw $this->conflicto('gestion.zona_en_uso');
        }
    }
}
