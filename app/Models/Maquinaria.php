<?php

namespace App\Models;

use App\Core\Model;

class Maquinaria extends Model
{
    protected static string $tabla = 'maquinaria';
    protected static array $campos = ['centro_id', 'codigo', 'descripcion', 'tipo', 'estado', 'fecha_adquisicion', 'ultimo_mantenimiento', 'activo'];
    protected static array $filtros = ['centro_id', 'tipo', 'estado'];
    protected static array $busqueda = ['t.codigo', 't.descripcion'];
    protected static string $orden = 't.codigo';
    protected static ?string $bajaLogica = 'activo';

    protected static function consultaBase(): string
    {
        return 'SELECT t.*, c.nombre AS centro FROM maquinaria t JOIN centros c ON c.id = t.centro_id';
    }
}
