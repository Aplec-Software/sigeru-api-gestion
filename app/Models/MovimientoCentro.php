<?php

namespace App\Models;

use App\Core\Model;

/** Ingresos y egresos de residuos en un centro (tabla movimientos_centro). */
class MovimientoCentro extends Model
{
    protected static string $tabla = 'movimientos_centro';
    protected static array $campos = ['centro_id', 'tipo_movimiento', 'tipo_residuo_id', 'camion_id', 'peso_ton', 'destino', 'usuario_id', 'observacion', 'creado_en'];
    protected static array $filtros = ['centro_id', 'tipo_movimiento', 'tipo_residuo_id'];
    protected static string $orden = 't.creado_en DESC, t.id DESC';

    protected static function consultaBase(): string
    {
        return 'SELECT t.*, tr.nombre AS tipo_residuo, ca.matricula, u.nombre AS usuario_nombre, u.apellido AS usuario_apellido
                FROM movimientos_centro t
                JOIN tipos_residuo tr ON tr.id = t.tipo_residuo_id
                LEFT JOIN camiones ca ON ca.id = t.camion_id
                JOIN usuarios u ON u.id = t.usuario_id';
    }
}
