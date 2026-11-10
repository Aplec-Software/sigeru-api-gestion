<?php

namespace App\Models;

use App\Core\Clock;
use App\Core\Model;

class Repuesto extends Model
{
    protected static string $tabla = 'repuestos';
    protected static array $campos = ['codigo', 'nombre', 'categoria', 'unidad', 'stock', 'stock_minimo', 'activo'];
    protected static array $filtros = ['categoria'];
    protected static array $busqueda = ['t.codigo', 't.nombre'];
    protected static string $orden = 't.codigo';
    protected static ?string $bajaLogica = 'activo';

    /**
     * Registra una entrada o salida de stock. La salida solo se aplica si hay
     * stock suficiente (la condición va en el propio UPDATE para evitar que dos
     * pedidos simultáneos dejen el stock negativo).
     */
    public static function mover(int $id, string $tipo, int $cantidad, string $motivo, int $usuarioId, ?int $asignacionId = null): bool
    {
        if ($tipo === 'salida') {
            $filas = static::ejecutar('UPDATE repuestos SET stock = stock - ? WHERE id = ? AND stock >= ?', [$cantidad, $id, $cantidad]);
        } else {
            $filas = static::ejecutar('UPDATE repuestos SET stock = stock + ? WHERE id = ?', [$cantidad, $id]);
        }
        if ($filas === 0) {
            return false;
        }
        static::db()->prepare('INSERT INTO movimientos_stock (repuesto_id, tipo, cantidad, motivo, usuario_id, asignacion_id, creado_en)
                               VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tipo, $cantidad, $motivo, $usuarioId, $asignacionId, Clock::ahora()]);
        return true;
    }

    public static function movimientos(int $id, int $limite = 50): array
    {
        return static::todos("SELECT m.id, m.tipo, m.cantidad, m.motivo, m.asignacion_id, m.creado_en,
                                     u.nombre AS usuario_nombre, u.apellido AS usuario_apellido
                              FROM movimientos_stock m JOIN usuarios u ON u.id = m.usuario_id
                              WHERE m.repuesto_id = ? ORDER BY m.creado_en DESC, m.id DESC LIMIT $limite", [$id]);
    }
}
