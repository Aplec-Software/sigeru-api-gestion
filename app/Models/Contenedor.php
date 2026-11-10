<?php

namespace App\Models;

use App\Core\Model;

class Contenedor extends Model
{
    protected static string $tabla = 'contenedores';
    protected static array $campos = [
        'codigo', 'tipo_residuo_id', 'zona_id', 'direccion', 'latitud', 'longitud', 'capacidad_litros',
        'estado', 'nivel_llenado', 'fecha_instalacion', 'ultima_recoleccion', 'activo',
    ];
    protected static array $filtros = ['zona_id', 'tipo_residuo_id', 'estado'];
    protected static array $busqueda = ['t.codigo', 't.direccion'];
    protected static string $orden = 't.codigo';
    protected static ?string $bajaLogica = 'activo';

    protected static function consultaBase(): string
    {
        return 'SELECT t.*, z.nombre AS zona, tr.nombre AS tipo_residuo, tr.color
                FROM contenedores t
                JOIN zonas z ON z.id = t.zona_id
                JOIN tipos_residuo tr ON tr.id = t.tipo_residuo_id';
    }

    public static function porCodigo(string $codigo): ?array
    {
        return static::uno(static::consultaBase() . ' WHERE t.codigo = ?', [strtoupper($codigo)]);
    }

    public static function siguienteCodigo(): string
    {
        $max = (int) static::valor("SELECT MAX(id) FROM contenedores");
        do {
            $codigo = sprintf('CNT-%04d', ++$max);
        } while (static::existe('codigo', $codigo));
        return $codigo;
    }

    public static function incidenciasActivas(int $id): int
    {
        return (int) static::valor("SELECT COUNT(*) FROM incidencias WHERE contenedor_id = ? AND estado IN ('abierta','en_curso')", [$id]);
    }

    public static function cambiarEstado(int $id, string $estado, ?int $nivel = null): void
    {
        if ($nivel === null) {
            static::ejecutar('UPDATE contenedores SET estado = ? WHERE id = ?', [$estado, $id]);
        } else {
            static::ejecutar('UPDATE contenedores SET estado = ?, nivel_llenado = ? WHERE id = ?', [$estado, $nivel, $id]);
        }
    }

    /** Historial del contenedor: incidencias y recolecciones, de la más reciente a la más antigua. */
    public static function historial(int $id, int $limite = 50): array
    {
        return static::todos(
            "SELECT 'incidencia' AS evento, i.id, i.tipo AS detalle, i.estado, i.creado_en AS fecha
               FROM incidencias i WHERE i.contenedor_id = ?
             UNION ALL
             SELECT 'recoleccion' AS evento, r.id, CAST(r.peso_kg AS CHAR) AS detalle, CAST(r.nivel_previo AS CHAR) AS estado, r.creado_en AS fecha
               FROM recolecciones r WHERE r.contenedor_id = ?
             ORDER BY fecha DESC LIMIT $limite",
            [$id, $id]
        );
    }
}
