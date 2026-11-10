<?php

namespace App\Models;

use App\Core\Clock;
use App\Core\Model;

class Incidencia extends Model
{
    protected static string $tabla = 'incidencias';
    protected static array $campos = [
        'contenedor_id', 'tipo', 'descripcion', 'estado', 'prioridad', 'origen', 'reportado_por', 'cuadrilla_id',
        'cantidad_reportes', 'ip_hash', 'creado_en', 'asignado_en', 'resuelto_en', 'resolucion',
    ];
    protected static array $filtros = ['estado', 'tipo', 'prioridad', 'origen', 'contenedor_id', 'cuadrilla_id'];
    protected static array $busqueda = ['c.codigo', 'c.direccion', 't.descripcion'];
    protected static string $orden = 't.creado_en DESC, t.id DESC';

    // Transiciones permitidas entre estados (diagrama de estados de la incidencia)
    public const TRANSICIONES = [
        'abierta' => ['en_curso', 'descartada'],
        'en_curso' => ['resuelta', 'abierta'],
        'resuelta' => ['abierta'],
        'descartada' => ['abierta'],
    ];

    protected static function consultaBase(): string
    {
        return 'SELECT t.*, c.codigo AS contenedor_codigo, c.direccion, c.latitud, c.longitud, c.zona_id,
                       z.nombre AS zona, tr.nombre AS tipo_residuo, cu.nombre AS cuadrilla,
                       u.nombre AS reportado_nombre, u.apellido AS reportado_apellido
                FROM incidencias t
                JOIN contenedores c ON c.id = t.contenedor_id
                JOIN zonas z ON z.id = c.zona_id
                JOIN tipos_residuo tr ON tr.id = c.tipo_residuo_id
                LEFT JOIN cuadrillas cu ON cu.id = t.cuadrilla_id
                LEFT JOIN usuarios u ON u.id = t.reportado_por';
    }

    /** Incidencia abierta o en curso del mismo tipo sobre el mismo contenedor (para no duplicar reportes). */
    public static function activaDe(int $contenedorId, string $tipo): ?array
    {
        $id = static::valor("SELECT id FROM incidencias WHERE contenedor_id = ? AND tipo = ? AND estado IN ('abierta','en_curso')
                             ORDER BY id DESC LIMIT 1", [$contenedorId, $tipo]);
        return $id ? static::buscar((int) $id) : null;
    }

    public static function sumarReporte(int $id): void
    {
        static::ejecutar('UPDATE incidencias SET cantidad_reportes = cantidad_reportes + 1 WHERE id = ?', [$id]);
    }

    public static function agregarHistorial(int $id, ?string $anterior, string $nuevo, ?int $usuarioId, ?string $comentario): void
    {
        static::db()->prepare('INSERT INTO incidencia_historial (incidencia_id, estado_anterior, estado_nuevo, usuario_id, comentario, creado_en)
                               VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $anterior, $nuevo, $usuarioId, $comentario, Clock::ahora()]);
    }

    public static function historial(int $id): array
    {
        return static::todos('SELECT h.id, h.estado_anterior, h.estado_nuevo, h.comentario, h.creado_en,
                                     u.nombre AS usuario_nombre, u.apellido AS usuario_apellido
                              FROM incidencia_historial h LEFT JOIN usuarios u ON u.id = h.usuario_id
                              WHERE h.incidencia_id = ? ORDER BY h.creado_en, h.id', [$id]);
    }

    public static function reportesDesdeIp(string $ipHash, string $desde): int
    {
        return (int) static::valor('SELECT COUNT(*) FROM incidencias WHERE ip_hash = ? AND creado_en >= ?', [$ipHash, $desde]);
    }

    public static function cuadrillaDeUsuario(int $usuarioId): ?int
    {
        $id = static::valor('SELECT cuadrilla_id FROM cuadrilla_integrante WHERE usuario_id = ?', [$usuarioId]);
        return $id === false ? null : (int) $id;
    }

    public static function cuadrillaActiva(int $id): bool
    {
        return static::valor('SELECT COUNT(*) FROM cuadrillas WHERE id = ? AND activa = 1', [$id]) > 0;
    }

    public static function desbordesRecientes(int $contenedorId, string $desde): int
    {
        return (int) static::valor("SELECT COUNT(*) FROM incidencias WHERE contenedor_id = ? AND tipo = 'desborde' AND creado_en >= ?",
            [$contenedorId, $desde]);
    }
}
