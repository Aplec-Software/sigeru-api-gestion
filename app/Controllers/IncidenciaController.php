<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Lang;
use App\Core\Notifier;
use App\Core\Params;
use App\Core\Request;
use App\Core\Response;
use App\Models\Contenedor;
use App\Models\Incidencia;
use App\Views\JsonView;

/**
 * Registro y seguimiento de incidencias (desbordes, roturas, quemas, etc.).
 *
 * Estados: abierta -> en_curso -> resuelta (y descartada para reportes falsos o duplicados).
 * Cada cambio queda en incidencia_historial.
 */
class IncidenciaController extends Controller
{
    private const TIPOS = 'desborde,rotura,quema,falta_recoleccion,otro';

    public function index(Request $req): Response
    {
        $porPagina = $req->queryInt('por_pagina', 20, 200);
        [$filas, $total] = Incidencia::listar($req->query, $req->queryInt('pagina', 1), $porPagina, $this->alcance($req));
        return Response::ok(array_map(fn ($f) => $this->vista($f), $filas), 200, $this->meta($req, $total, $porPagina));
    }

    public function show(Request $req): Response
    {
        $incidencia = $this->buscarVisible($req);
        $datos = $this->vista($incidencia);
        $datos['historial'] = array_map(fn ($h) => JsonView::render($h), Incidencia::historial((int) $incidencia['id']));
        return Response::ok($datos);
    }

    /** Alta por un usuario autenticado (vecino, cuadrilla o administrador). */
    public function store(Request $req): Response
    {
        $datos = $this->validar($req, [
            'contenedor_id' => 'nullable|int',
            'contenedor_codigo' => 'nullable|regex:/^CNT-\d{4,6}$/',
            'tipo' => 'required|in:' . self::TIPOS,
            'descripcion' => 'nullable|string|max:500',
            'prioridad' => 'nullable|in:baja,media,alta',
        ]);
        $contenedor = $this->contenedorActivo($datos);
        $origen = match ($req->usuario['rol']) {
            'cuadrilla' => 'cuadrilla',
            'administrador' => 'administrador',
            default => 'vecino',
        };
        if ($origen !== 'administrador') {
            unset($datos['prioridad']);   // la prioridad la define el sistema o el administrador
        }
        return $this->registrar($req, $contenedor, $datos, $origen, $req->usuario['id'], null);
    }

    /**
     * Alta anónima desde el código QR del contenedor (sin iniciar sesión).
     * Para evitar abusos: campo trampa, límite de reportes por hora por IP
     * (se guarda solo el hash de la IP) y agrupación de reportes repetidos.
     */
    public function reportarQr(Request $req): Response
    {
        if (!empty($req->input('sitio_web'))) {
            return Response::ok(null, 201, null, Lang::t('gestion.reporte_recibido'));
        }
        $datos = $this->validar($req, [
            'contenedor_codigo' => 'required|regex:/^CNT-\d{4,6}$/',
            'tipo' => 'required|in:' . self::TIPOS,
            'descripcion' => 'nullable|string|max:200',
        ]);
        $ipHash = hash('sha256', $req->ip . Config::get('APP_SAL', 'sigeru'));
        if (Incidencia::reportesDesdeIp($ipHash, Clock::mas(-60)) >= Params::int('qr_reportes_por_hora', 5)) {
            throw new HttpException(429, Lang::t('gestion.demasiados_reportes'));
        }
        $contenedor = $this->contenedorActivo($datos);
        return $this->registrar($req, $contenedor, $datos, $req->usuario ? 'vecino' : 'qr', $req->usuario['id'] ?? null, $ipHash);
    }

    private function registrar(Request $req, array $contenedor, array $datos, string $origen, ?int $usuarioId, ?string $ipHash): Response
    {
        // RNE: si ya hay una incidencia activa igual, se suma el reporte en lugar de duplicarla
        $existente = Incidencia::activaDe((int) $contenedor['id'], $datos['tipo']);
        if ($existente) {
            Incidencia::sumarReporte((int) $existente['id']);
            Incidencia::agregarHistorial((int) $existente['id'], $existente['estado'], $existente['estado'], $usuarioId, Lang::t('gestion.reporte_adicional'));
            if ($datos['tipo'] === 'desborde' && $existente['cantidad_reportes'] + 1 >= 3 && $existente['prioridad'] !== 'alta') {
                Incidencia::actualizar((int) $existente['id'], ['prioridad' => 'alta']);
            }
            return Response::ok($this->vistaReporte($req, Incidencia::buscar((int) $existente['id']), true), 200, null, Lang::t('gestion.incidencia_duplicada'));
        }

        $prioridad = $datos['prioridad'] ?? $this->prioridadSugerida($datos['tipo'], (int) $contenedor['id'], $origen);
        $id = Incidencia::crear([
            'contenedor_id' => $contenedor['id'],
            'tipo' => $datos['tipo'],
            'descripcion' => $datos['descripcion'] ?? null,
            'estado' => 'abierta',
            'prioridad' => $prioridad,
            'origen' => $origen,
            'reportado_por' => $usuarioId,
            'ip_hash' => $ipHash,
            'creado_en' => Clock::ahora(),
        ]);
        Incidencia::agregarHistorial($id, null, 'abierta', $usuarioId, Lang::t('gestion.incidencia_registrada'));

        // el estado del contenedor acompaña al tipo de incidencia; un contenedor roto sigue roto aunque también
        // esté lleno (la rotura es lo más grave), pero se registra el nivel al 100 %
        if ($datos['tipo'] === 'desborde') {
            Contenedor::cambiarEstado((int) $contenedor['id'], $contenedor['estado'] === 'roto' ? 'roto' : 'desbordado', 100);
        } elseif (in_array($datos['tipo'], ['rotura', 'quema'], true)) {
            Contenedor::cambiarEstado((int) $contenedor['id'], 'roto');
        }

        Notifier::aRol('administrador', 'incidencia_nueva', Lang::t('gestion.nueva_incidencia_titulo'),
            Lang::t('gestion.nueva_incidencia', ['tipo' => $datos['tipo'], 'codigo' => $contenedor['codigo'], 'direccion' => $contenedor['direccion']]),
            '#/incidencias/' . $id);
        Audit::registrar($req, 'crear', 'incidencia', $id, $origen);

        return Response::ok($this->vistaReporte($req, Incidencia::buscar($id), false), 201, null, Lang::t('gestion.reporte_recibido'))
            ->conHeader('Location', '/incidencias/' . $id);
    }

    /** Representación JSON de una incidencia: el hash de la IP nunca sale de la API. */
    private function vista(array $incidencia): array
    {
        return JsonView::render($incidencia, [], ['ip_hash']);
    }

    /**
     * Respuesta al que reporta. El personal ve la incidencia completa; quien reporta por QR sin sesión,
     * o un vecino cuyo aviso se sumó a la incidencia de otra persona, recibe solo un resumen
     * (sin datos de quien reportó primero, ni de la cuadrilla, ni el hash de la IP).
     */
    private function vistaReporte(Request $req, array $incidencia, bool $sumado): array
    {
        $usuario = $req->usuario;
        $esPersonal = $usuario && in_array($usuario['rol'], ['administrador', 'cuadrilla'], true);
        $esPropia = $usuario && (int) ($incidencia['reportado_por'] ?? 0) === (int) $usuario['id'];
        if ($esPersonal || $esPropia) {
            return $this->vista($incidencia);
        }
        $resumen = array_intersect_key($incidencia, array_flip(['id', 'contenedor_codigo', 'direccion', 'tipo', 'estado',
            'origen', 'cantidad_reportes', 'creado_en']));
        $resumen['sumado'] = $sumado;
        return JsonView::render($resumen);
    }

    /**
     * Prioridad sugerida (tabla de decisión documentada en la ERS):
     *   quema                                   -> alta
     *   rotura                                  -> alta si la reporta una cuadrilla, si no media
     *   desborde en contenedor crítico          -> alta
     *   desborde / falta de recolección         -> media
     *   otro                                    -> baja
     */
    private function prioridadSugerida(string $tipo, int $contenedorId, string $origen): string
    {
        $limite = Params::int('desbordes_contenedor_critico', 3);
        return match (true) {
            $tipo === 'quema' => 'alta',
            $tipo === 'rotura' => $origen === 'cuadrilla' ? 'alta' : 'media',
            $tipo === 'desborde' && Incidencia::desbordesRecientes($contenedorId, Clock::mas(-30 * 24 * 60)) + 1 >= $limite => 'alta',
            in_array($tipo, ['desborde', 'falta_recoleccion'], true) => 'media',
            default => 'baja',
        };
    }

    /** PUT /incidencias/{id}: el administrador corrige tipo, prioridad o descripción. */
    public function update(Request $req): Response
    {
        $incidencia = $this->buscarVisible($req);
        $datos = $this->validar($req, [
            'tipo' => 'required|in:' . self::TIPOS,
            'prioridad' => 'required|in:baja,media,alta',
            'descripcion' => 'nullable|string|max:500',
        ], true);
        Incidencia::actualizar((int) $incidencia['id'], $datos);
        Audit::registrar($req, 'modificar', 'incidencia', $incidencia['id']);
        return Response::ok($this->vista(Incidencia::buscar((int) $incidencia['id'])), 200, null, Lang::t('ok.modificado'));
    }

    /** PUT /incidencias/{id}/asignar: asigna una cuadrilla y pasa la incidencia a "en curso". */
    public function asignar(Request $req): Response
    {
        $incidencia = $this->buscarVisible($req);
        $datos = $this->validar($req, ['cuadrilla_id' => 'required|int', 'comentario' => 'nullable|string|max:300']);
        if (!in_array($incidencia['estado'], ['abierta', 'en_curso'], true)) {
            throw $this->conflicto('gestion.transicion_invalida', ['de' => $incidencia['estado'], 'a' => 'en_curso']);
        }
        if (!Incidencia::cuadrillaActiva($datos['cuadrilla_id'])) {
            throw $this->conflicto('gestion.cuadrilla_inexistente');
        }
        Incidencia::actualizar((int) $incidencia['id'], [
            'cuadrilla_id' => $datos['cuadrilla_id'],
            'estado' => 'en_curso',
            'asignado_en' => Clock::ahora(),
        ]);
        Incidencia::agregarHistorial((int) $incidencia['id'], $incidencia['estado'], 'en_curso', $req->usuario['id'],
            $datos['comentario'] ?? Lang::t('gestion.asignada_a', ['cuadrilla' => $datos['cuadrilla_id']]));
        Notifier::aCuadrilla($datos['cuadrilla_id'], 'incidencia_asignada', Lang::t('gestion.tarea_nueva_titulo'),
            Lang::t('gestion.tarea_nueva', ['tipo' => $incidencia['tipo'], 'codigo' => $incidencia['contenedor_codigo'], 'direccion' => $incidencia['direccion']]),
            '#/tareas');
        Audit::registrar($req, 'asignar', 'incidencia', $incidencia['id'], (string) $datos['cuadrilla_id']);
        return Response::ok($this->vista(Incidencia::buscar((int) $incidencia['id'])), 200, null, Lang::t('ok.modificado'));
    }

    /** PUT /incidencias/{id}/estado: cambia el estado respetando las transiciones permitidas. */
    public function estado(Request $req): Response
    {
        $incidencia = $this->buscarVisible($req);
        $datos = $this->validar($req, [
            'estado' => 'required|in:abierta,en_curso,resuelta,descartada',
            'comentario' => 'nullable|string|max:300',
        ]);
        $de = $incidencia['estado'];
        $a = $datos['estado'];
        if (!in_array($a, Incidencia::TRANSICIONES[$de] ?? [], true)) {
            throw $this->conflicto('gestion.transicion_invalida', ['de' => $de, 'a' => $a]);
        }
        // la cuadrilla solo puede marcar como resueltas sus propias tareas; lo demás lo hace el administrador
        if ($req->usuario['rol'] === 'cuadrilla' && $a !== 'resuelta') {
            throw $this->prohibido();
        }
        if ($a === 'en_curso' && empty($incidencia['cuadrilla_id'])) {
            throw $this->conflicto('gestion.sin_cuadrilla');
        }
        if ($a === 'resuelta' && empty($datos['comentario'])) {
            throw new HttpException(422, Lang::t('error.validacion'), ['comentario' => Lang::t('gestion.resolucion_requerida')]);
        }

        $cambios = ['estado' => $a];
        if ($a === 'resuelta') {
            $cambios['resuelto_en'] = Clock::ahora();
            $cambios['resolucion'] = $datos['comentario'];
        } elseif ($a === 'abierta') {
            $cambios += ['resuelto_en' => null, 'resolucion' => null, 'cuadrilla_id' => null, 'asignado_en' => null];
        }

        $db = Database::conexion();
        $db->beginTransaction();
        try {
            Incidencia::actualizar((int) $incidencia['id'], $cambios);
            Incidencia::agregarHistorial((int) $incidencia['id'], $de, $a, $req->usuario['id'], $datos['comentario'] ?? null);
            if ($a === 'resuelta') {
                $this->normalizarContenedor($incidencia);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        if ($a === 'resuelta' && !empty($incidencia['reportado_por']) && (int) $incidencia['reportado_por'] !== $req->usuario['id']) {
            Notifier::aUsuarios([(int) $incidencia['reportado_por']], 'incidencia_resuelta', Lang::t('gestion.resuelta_titulo'),
                Lang::t('gestion.resuelta', ['codigo' => $incidencia['contenedor_codigo']]), '#/mis-reportes');
        }
        Audit::registrar($req, 'estado_' . $a, 'incidencia', $incidencia['id']);
        return Response::ok($this->vista(Incidencia::buscar((int) $incidencia['id'])), 200, null, Lang::t('ok.modificado'));
    }

    /** Solo se eliminan incidencias que no llegaron a atenderse (reportes falsos o de prueba). */
    public function destroy(Request $req): Response
    {
        $incidencia = $this->buscarVisible($req);
        if (!in_array($incidencia['estado'], ['abierta', 'descartada'], true) || !empty($incidencia['cuadrilla_id'])) {
            throw $this->conflicto('gestion.no_se_puede_eliminar');
        }
        Incidencia::eliminar((int) $incidencia['id']);
        Audit::registrar($req, 'eliminar', 'incidencia', $incidencia['id']);
        return Response::ok(null, 200, null, Lang::t('ok.eliminado'));
    }

    public function historial(Request $req): Response
    {
        $incidencia = $this->buscarVisible($req);
        return Response::ok(array_map(fn ($h) => JsonView::render($h), Incidencia::historial((int) $incidencia['id'])));
    }

    // ----------------------------------------------------------- auxiliares

    /** Qué incidencias puede ver cada rol. */
    private function alcance(Request $req): array
    {
        $extra = [];
        if (!empty($req->query['zona_id'])) {
            $extra['c.zona_id = ?'] = $req->query['zona_id'];
        }
        if (!empty($req->query['desde'])) {
            $extra['t.creado_en >= ?'] = $req->query['desde'];
        }
        if (!empty($req->query['hasta'])) {
            $extra['t.creado_en <= ?'] = $req->query['hasta'] . ' 23:59:59';
        }
        if (($req->query['activas'] ?? '') === '1') {
            $extra["t.estado IN ('abierta','en_curso')"] = null;
        }
        return $extra + match ($req->usuario['rol']) {
            'cuadrilla' => ['t.cuadrilla_id = ?' => Incidencia::cuadrillaDeUsuario($req->usuario['id']) ?? 0],
            'vecino' => ['t.reportado_por = ?' => $req->usuario['id']],
            default => [],
        };
    }

    private function buscarVisible(Request $req): array
    {
        $incidencia = Incidencia::buscar($this->idParam($req)) ?? throw $this->noEncontrado();
        $rol = $req->usuario['rol'];
        $visible = match ($rol) {
            'cuadrilla' => (int) $incidencia['cuadrilla_id'] === (int) Incidencia::cuadrillaDeUsuario($req->usuario['id']),
            'vecino' => (int) $incidencia['reportado_por'] === $req->usuario['id'],
            'administrador' => true,
            default => false,
        };
        if (!$visible) {
            throw $this->noEncontrado();   // no se revela que existe
        }
        return $incidencia;
    }

    private function contenedorActivo(array $datos): array
    {
        $contenedor = !empty($datos['contenedor_id']) ? Contenedor::buscar($datos['contenedor_id'])
            : (!empty($datos['contenedor_codigo']) ? Contenedor::porCodigo($datos['contenedor_codigo']) : null);
        if ($contenedor === null) {
            throw new HttpException(422, Lang::t('error.validacion'), ['contenedor_id' => Lang::t('gestion.contenedor_requerido')]);
        }
        if (!$contenedor['activo'] || $contenedor['estado'] === 'fuera_servicio') {
            throw $this->conflicto('gestion.contenedor_inactivo');
        }
        return $contenedor;
    }

    /** Al resolver, el contenedor vuelve a "funcional" si no le quedan otras incidencias activas. */
    private function normalizarContenedor(array $incidencia): void
    {
        if (Contenedor::incidenciasActivas((int) $incidencia['contenedor_id']) === 0) {
            $nivel = in_array($incidencia['tipo'], ['desborde', 'falta_recoleccion'], true) ? 0 : null;
            Contenedor::cambiarEstado((int) $incidencia['contenedor_id'], 'funcional', $nivel);
        }
    }
}
