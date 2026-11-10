<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/**
 * Consultas de los reportes y del panel de control (solo lectura).
 */
class Estadistica extends Model
{
    protected static string $tabla = 'incidencias';

    public static function resumen(int $umbralCentro): array
    {
        $porEstado = fn (string $sql) => array_column(static::todos($sql), 'n', 'estado');
        return [
            'contenedores' => $porEstado('SELECT estado, COUNT(*) AS n FROM contenedores WHERE activo = 1 GROUP BY estado'),
            'incidencias' => $porEstado('SELECT estado, COUNT(*) AS n FROM incidencias GROUP BY estado'),
            'camiones' => $porEstado('SELECT estado, COUNT(*) AS n FROM camiones WHERE activo = 1 GROUP BY estado'),
            'centros_en_alerta' => (int) static::valor('SELECT COUNT(*) FROM v_ocupacion_centros WHERE porcentaje >= ?', [$umbralCentro]),
            'repuestos_bajo_minimo' => (int) static::valor('SELECT COUNT(*) FROM repuestos WHERE activo = 1 AND stock < stock_minimo'),
        ];
    }

    /** Ranking de contenedores con más desbordes desde una fecha. */
    public static function topDesbordados(string $desde, int $limite): array
    {
        return static::todos("SELECT c.id, c.codigo, c.direccion, z.nombre AS zona, COUNT(*) AS cantidad,
                                     SUM(i.cantidad_reportes) AS reportes
                              FROM incidencias i
                              JOIN contenedores c ON c.id = i.contenedor_id
                              JOIN zonas z ON z.id = c.zona_id
                              WHERE i.tipo = 'desborde' AND i.creado_en >= ?
                              GROUP BY c.id, c.codigo, c.direccion, z.nombre
                              ORDER BY cantidad DESC, reportes DESC LIMIT $limite", [$desde]);
    }

    /** Zonas ordenadas por cantidad de incidencias por contenedor. */
    public static function zonasCriticas(string $desde): array
    {
        $horas = Database::horasEntre('i.creado_en', 'i.resuelto_en');
        return static::todos("SELECT z.id, z.nombre AS zona,
                                     (SELECT COUNT(*) FROM contenedores c2 WHERE c2.zona_id = z.id AND c2.activo = 1) AS contenedores,
                                     COUNT(i.id) AS incidencias,
                                     SUM(CASE WHEN i.estado IN ('abierta','en_curso') THEN 1 ELSE 0 END) AS activas,
                                     ROUND(AVG(CASE WHEN i.resuelto_en IS NOT NULL THEN $horas END), 1) AS horas_promedio
                              FROM zonas z
                              LEFT JOIN contenedores c ON c.zona_id = z.id
                              LEFT JOIN incidencias i ON i.contenedor_id = c.id AND i.creado_en >= ?
                              GROUP BY z.id, z.nombre
                              ORDER BY incidencias DESC", [$desde]);
    }

    public static function tiemposResolucion(string $desde): array
    {
        $horas = Database::horasEntre('creado_en', 'resuelto_en');
        return static::todos("SELECT tipo, COUNT(*) AS resueltas, ROUND(AVG($horas), 1) AS horas_promedio,
                                     ROUND(MIN($horas), 1) AS horas_minimo, ROUND(MAX($horas), 1) AS horas_maximo
                              FROM incidencias WHERE estado = 'resuelta' AND creado_en >= ?
                              GROUP BY tipo ORDER BY horas_promedio DESC", [$desde]);
    }

    public static function incidenciasPorMes(string $desde): array
    {
        $mes = Database::mes('creado_en');
        return static::todos("SELECT $mes AS mes, tipo, COUNT(*) AS cantidad FROM incidencias
                              WHERE creado_en >= ? GROUP BY $mes, tipo ORDER BY mes", [$desde]);
    }

    public static function ocupacionCentros(): array
    {
        return static::todos('SELECT * FROM v_ocupacion_centros ORDER BY porcentaje DESC');
    }

    /** Recolecciones de los últimos días, para estimar cuándo se llena cada contenedor. */
    public static function recoleccionesDesde(string $desde): array
    {
        return static::todos('SELECT contenedor_id, creado_en, nivel_previo FROM recolecciones WHERE creado_en >= ? ORDER BY contenedor_id, creado_en', [$desde]);
    }

    public static function contenedoresActivos(): array
    {
        return static::todos('SELECT c.id, c.codigo, c.direccion, c.nivel_llenado, c.estado, c.ultima_recoleccion, z.nombre AS zona
                              FROM contenedores c JOIN zonas z ON z.id = c.zona_id WHERE c.activo = 1 ORDER BY c.codigo');
    }
}
