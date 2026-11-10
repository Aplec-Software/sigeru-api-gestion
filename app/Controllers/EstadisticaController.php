<?php

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Controller;
use App\Core\Params;
use App\Core\Request;
use App\Core\Response;
use App\Models\Estadistica;
use App\Views\JsonView;

/**
 * Datos para el panel de control y los informes estadísticos.
 * Por defecto se analizan los últimos 30 días (?dias=N para cambiarlo).
 */
class EstadisticaController extends Controller
{
    private function desde(Request $req): string
    {
        return Clock::mas(-$req->queryInt('dias', 30, 365) * 24 * 60);
    }

    private function filas(array $filas): array
    {
        return array_map(fn ($f) => JsonView::render($f), $filas);
    }

    public function resumen(Request $req): Response
    {
        $datos = Estadistica::resumen(Params::int('umbral_ocupacion_centro', 85));
        foreach (['contenedores', 'incidencias', 'camiones'] as $grupo) {
            $datos[$grupo] = array_map('intval', $datos[$grupo]);
        }
        return Response::ok($datos);
    }

    public function topDesbordados(Request $req): Response
    {
        return Response::ok($this->filas(Estadistica::topDesbordados($this->desde($req), $req->queryInt('limite', 10, 50))));
    }

    public function zonasCriticas(Request $req): Response
    {
        $zonas = $this->filas(Estadistica::zonasCriticas($this->desde($req)));
        foreach ($zonas as &$z) {
            // índice = incidencias por contenedor en el período
            $z['indice'] = $z['contenedores'] ? round($z['incidencias'] / $z['contenedores'], 2) : 0;
            $z['horas_promedio'] = $z['horas_promedio'] === null ? null : (float) $z['horas_promedio'];
        }
        usort($zonas, fn ($a, $b) => $b['indice'] <=> $a['indice']);
        return Response::ok($zonas);
    }

    public function tiemposResolucion(Request $req): Response
    {
        $filas = $this->filas(Estadistica::tiemposResolucion($this->desde($req)));
        foreach ($filas as &$f) {
            foreach (['horas_promedio', 'horas_minimo', 'horas_maximo'] as $c) {
                $f[$c] = (float) $f[$c];
            }
        }
        return Response::ok($filas);
    }

    public function incidenciasPorMes(Request $req): Response
    {
        $desde = Clock::mas(-$req->queryInt('meses', 6, 24) * 30 * 24 * 60);
        return Response::ok($this->filas(Estadistica::incidenciasPorMes($desde)));
    }

    public function ocupacionCentros(Request $req): Response
    {
        return Response::ok($this->filas(Estadistica::ocupacionCentros()));
    }

    /**
     * Proyección de llenado: con las recolecciones de los últimos 30 días se
     * calcula cuánto se llena por día cada contenedor y en cuántos días
     * llegaría al 100 % desde su nivel actual. Sirve para anticipar desbordes.
     */
    public function proyeccionLlenado(Request $req): Response
    {
        $porContenedor = [];
        foreach (Estadistica::recoleccionesDesde(Clock::mas(-30 * 24 * 60)) as $r) {
            $porContenedor[$r['contenedor_id']][] = $r;
        }
        $resultado = [];
        foreach (Estadistica::contenedoresActivos() as $c) {
            $lista = $porContenedor[$c['id']] ?? [];
            if (count($lista) < 2) {
                continue;
            }
            $dias = (strtotime(end($lista)['creado_en']) - strtotime($lista[0]['creado_en'])) / 86400 / (count($lista) - 1);
            $nivelPromedio = array_sum(array_column($lista, 'nivel_previo')) / count($lista);
            $porDia = $dias > 0 ? $nivelPromedio / $dias : 0;
            if ($porDia <= 0) {
                continue;
            }
            $resultado[] = [
                'id' => (int) $c['id'],
                'codigo' => $c['codigo'],
                'direccion' => $c['direccion'],
                'zona' => $c['zona'],
                'nivel_actual' => (int) $c['nivel_llenado'],
                'llenado_por_dia' => round($porDia, 1),
                'dias_hasta_lleno' => round(max(0, 100 - (int) $c['nivel_llenado']) / $porDia, 1),
            ];
        }
        usort($resultado, fn ($a, $b) => $a['dias_hasta_lleno'] <=> $b['dias_hasta_lleno']);
        return Response::ok(array_slice($resultado, 0, $req->queryInt('limite', 15, 100)));
    }
}
