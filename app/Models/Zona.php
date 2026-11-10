<?php

namespace App\Models;

use App\Core\Model;

class Zona extends Model
{
    protected static string $tabla = 'zonas';
    protected static array $campos = ['nombre', 'descripcion', 'latitud', 'longitud', 'activa'];
    protected static array $busqueda = ['t.nombre'];
    protected static string $orden = 't.nombre';
    protected static ?string $bajaLogica = 'activa';

    public static function enUso(int $id): bool
    {
        return static::valor('SELECT (SELECT COUNT(*) FROM contenedores WHERE zona_id = ? AND activo = 1)
                                   + (SELECT COUNT(*) FROM centros WHERE zona_id = ? AND activo = 1)', [$id, $id]) > 0;
    }
}
