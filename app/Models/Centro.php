<?php

namespace App\Models;

use App\Core\Model;

class Centro extends Model
{
    protected static string $tabla = 'centros';
    protected static array $campos = [
        'nombre', 'tipo', 'zona_id', 'direccion', 'latitud', 'longitud', 'capacidad_ton', 'ocupacion_ton', 'telefono', 'activo',
    ];
    protected static array $filtros = ['tipo', 'zona_id'];
    protected static array $busqueda = ['t.nombre', 't.direccion'];
    protected static string $orden = 't.nombre';
    protected static ?string $bajaLogica = 'activo';

    protected static function consultaBase(): string
    {
        return 'SELECT t.*, z.nombre AS zona, ROUND(t.ocupacion_ton * 100 / t.capacidad_ton, 1) AS porcentaje
                FROM centros t JOIN zonas z ON z.id = t.zona_id';
    }

    public static function tiposAceptados(int $id): array
    {
        return static::todos('SELECT tr.id, tr.nombre, tr.color FROM centro_tipo_residuo ct
                              JOIN tipos_residuo tr ON tr.id = ct.tipo_residuo_id WHERE ct.centro_id = ? ORDER BY tr.id', [$id]);
    }

    public static function aceptaTipo(int $centroId, int $tipoId): bool
    {
        return static::valor('SELECT COUNT(*) FROM centro_tipo_residuo WHERE centro_id = ? AND tipo_residuo_id = ?', [$centroId, $tipoId]) > 0;
    }

    /** Reemplaza la lista de tipos de residuo que acepta el centro. */
    public static function guardarTipos(int $id, array $tipos): void
    {
        static::ejecutar('DELETE FROM centro_tipo_residuo WHERE centro_id = ?', [$id]);
        $st = static::db()->prepare('INSERT INTO centro_tipo_residuo (centro_id, tipo_residuo_id) VALUES (?, ?)');
        foreach (array_unique(array_map('intval', $tipos)) as $tipo) {
            $st->execute([$id, $tipo]);
        }
    }

    public static function sumarOcupacion(int $id, float $toneladas): void
    {
        static::ejecutar('UPDATE centros SET ocupacion_ton = ocupacion_ton + ? WHERE id = ?', [$toneladas, $id]);
    }
}
