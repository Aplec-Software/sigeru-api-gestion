<?php

/**
 * Rutas de la API de Gestión: elementos fijos (contenedores, centros,
 * maquinaria), inventario de repuestos, incidencias, mapa y estadísticas.
 * null = pública, [] = cualquier usuario autenticado, [roles] = solo esos roles.
 */

use App\Controllers\CentroController;
use App\Controllers\ContenedorController;
use App\Controllers\EstadisticaController;
use App\Controllers\IncidenciaController;
use App\Controllers\InformeController;
use App\Controllers\MapaController;
use App\Controllers\MaquinariaController;
use App\Controllers\RepuestoController;
use App\Controllers\SaludController;
use App\Controllers\TipoResiduoController;
use App\Controllers\ZonaController;
use App\Core\Router;

$r = new Router();
$admin = ['administrador'];
$personal = ['administrador', 'cuadrilla', 'operario'];

$r->get('/salud', [SaludController::class, 'index'], null);

// Catálogos
$r->recurso('/zonas', ZonaController::class, null, $admin);
$r->recurso('/tipos-residuo', TipoResiduoController::class, null, $admin);

// Contenedores
$r->get('/contenedores/codigo/{codigo:[A-Za-z0-9-]+}', [ContenedorController::class, 'porCodigo'], null);
$r->get('/contenedores/{id}/historial', [ContenedorController::class, 'historial'], $personal);
$r->recurso('/contenedores', ContenedorController::class, null, $admin);

// Centros y sus movimientos de residuos
$r->get('/centros/{id}/movimientos', [CentroController::class, 'movimientos'], ['administrador', 'operario']);
$r->post('/centros/{id}/movimientos', [CentroController::class, 'registrarMovimiento'], ['administrador', 'operario']);
$r->recurso('/centros', CentroController::class, null, $admin);

// Maquinaria de los centros
$r->put('/maquinaria/{id}/estado', [MaquinariaController::class, 'estado'], ['administrador', 'operario']);
$r->get('/maquinaria', [MaquinariaController::class, 'index'], ['administrador', 'operario']);
$r->get('/maquinaria/{id}', [MaquinariaController::class, 'show'], ['administrador', 'operario']);
$r->post('/maquinaria', [MaquinariaController::class, 'store'], $admin);
$r->put('/maquinaria/{id}', [MaquinariaController::class, 'update'], $admin);
$r->delete('/maquinaria/{id}', [MaquinariaController::class, 'destroy'], $admin);

// Repuestos y stock
$r->get('/repuestos/{id}/movimientos', [RepuestoController::class, 'movimientos'], $admin);
$r->post('/repuestos/{id}/movimientos', [RepuestoController::class, 'registrarMovimiento'], $admin);
$r->get('/repuestos', [RepuestoController::class, 'index'], ['administrador', 'cuadrilla']);
$r->get('/repuestos/{id}', [RepuestoController::class, 'show'], ['administrador', 'cuadrilla']);
$r->post('/repuestos', [RepuestoController::class, 'store'], $admin);
$r->put('/repuestos/{id}', [RepuestoController::class, 'update'], $admin);
$r->delete('/repuestos/{id}', [RepuestoController::class, 'destroy'], $admin);

// Incidencias
$r->post('/incidencias/qr', [IncidenciaController::class, 'reportarQr'], null);
$r->get('/incidencias', [IncidenciaController::class, 'index'], ['administrador', 'cuadrilla', 'vecino']);
$r->get('/incidencias/{id}', [IncidenciaController::class, 'show'], ['administrador', 'cuadrilla', 'vecino']);
$r->get('/incidencias/{id}/historial', [IncidenciaController::class, 'historial'], ['administrador', 'cuadrilla', 'vecino']);
$r->post('/incidencias', [IncidenciaController::class, 'store'], ['administrador', 'cuadrilla', 'vecino']);
$r->put('/incidencias/{id}', [IncidenciaController::class, 'update'], $admin);
$r->put('/incidencias/{id}/asignar', [IncidenciaController::class, 'asignar'], $admin);
$r->put('/incidencias/{id}/estado', [IncidenciaController::class, 'estado'], ['administrador', 'cuadrilla']);
$r->delete('/incidencias/{id}', [IncidenciaController::class, 'destroy'], $admin);

// Mapa (público, con más capas para el personal)
$r->get('/mapa', [MapaController::class, 'index'], null);

// Estadísticas e informes
$r->get('/estadisticas/resumen', [EstadisticaController::class, 'resumen'], $admin);
$r->get('/estadisticas/top-desbordados', [EstadisticaController::class, 'topDesbordados'], $admin);
$r->get('/estadisticas/zonas-criticas', [EstadisticaController::class, 'zonasCriticas'], $admin);
$r->get('/estadisticas/tiempos-resolucion', [EstadisticaController::class, 'tiemposResolucion'], $admin);
$r->get('/estadisticas/incidencias-por-mes', [EstadisticaController::class, 'incidenciasPorMes'], $admin);
$r->get('/estadisticas/ocupacion-centros', [EstadisticaController::class, 'ocupacionCentros'], $admin);
$r->get('/estadisticas/proyeccion-llenado', [EstadisticaController::class, 'proyeccionLlenado'], $admin);
$r->get('/informes/incidencias', [InformeController::class, 'incidencias'], $admin);
$r->get('/informes/contenedores', [InformeController::class, 'contenedores'], $admin);

return $r;
