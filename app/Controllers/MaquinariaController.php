<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\CrudController;
use App\Core\Database;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Models\Centro;
use App\Models\Maquinaria;

/**
 * Maquinaria básica de los centros. El operario ve la de su centro y puede
 * informar su estado; el ABM completo es del administrador.
 */
class MaquinariaController extends CrudController
{
    protected string $modelo = Maquinaria::class;
    protected string $entidad = 'maquinaria';
    protected array $reglas = [
        'centro_id' => 'required|int',
        'codigo' => 'required|regex:/^MAQ-\d{3,5}$/',
        'descripcion' => 'required|string|min:3|max:100',
        'tipo' => 'required|in:compactadora,prensa,balanza,cargadora,trituradora,cinta,otro',
        'estado' => 'nullable|in:operativa,mantenimiento,fuera_servicio',
        'fecha_adquisicion' => 'nullable|date',
        'ultimo_mantenimiento' => 'nullable|date',
    ];

    protected function filtrosExtra(Request $req): array
    {
        return $req->usuario['rol'] === 'operario' ? ['t.centro_id = ?' => $this->centroDelOperario($req)] : [];
    }

    protected function autorizarLectura(Request $req, array $fila): void
    {
        if ($req->usuario['rol'] === 'operario' && (int) $fila['centro_id'] !== $this->centroDelOperario($req)) {
            throw $this->prohibido();
        }
    }

    protected function antesDeGuardar(Request $req, array &$datos, ?array $actual): void
    {
        if (isset($datos['codigo']) && Maquinaria::existe('codigo', $datos['codigo'], $actual['id'] ?? null)) {
            throw $this->conflicto('gestion.codigo_repetido');
        }
        if (isset($datos['centro_id']) && !Centro::buscar($datos['centro_id'])) {
            throw $this->conflicto('gestion.centro_inexistente');
        }
    }

    /** PUT /maquinaria/{id}/estado */
    public function estado(Request $req): Response
    {
        $id = $this->idParam($req);
        $maquina = Maquinaria::buscar($id) ?? throw $this->noEncontrado();
        $this->autorizarLectura($req, $maquina);
        $datos = $this->validar($req, ['estado' => 'required|in:operativa,mantenimiento,fuera_servicio']);
        if ($maquina['estado'] === 'mantenimiento' && $datos['estado'] === 'operativa') {
            $datos['ultimo_mantenimiento'] = Clock::hoy();
        }
        Maquinaria::actualizar($id, $datos);
        Audit::registrar($req, 'estado_' . $datos['estado'], 'maquinaria', $id);
        return Response::ok($this->vista(Maquinaria::buscar($id)), 200, null, Lang::t('ok.modificado'));
    }

    private function centroDelOperario(Request $req): int
    {
        $st = Database::conexion()->prepare('SELECT centro_id FROM usuarios WHERE id = ?');
        $st->execute([$req->usuario['id']]);
        return (int) $st->fetchColumn();
    }
}
