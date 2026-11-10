<?php

namespace App\Models;

use App\Core\Model;

class TipoResiduo extends Model
{
    protected static string $tabla = 'tipos_residuo';
    protected static array $campos = ['nombre', 'color', 'reciclable', 'descripcion', 'activo'];
    protected static array $filtros = ['reciclable'];
    protected static array $busqueda = ['t.nombre'];
    protected static string $orden = 't.id';
    protected static ?string $bajaLogica = 'activo';

    public static function enUso(int $id): bool
    {
        return static::valor('SELECT COUNT(*) FROM contenedores WHERE tipo_residuo_id = ? AND activo = 1', [$id]) > 0;
    }
}
