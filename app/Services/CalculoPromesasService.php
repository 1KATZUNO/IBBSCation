<?php

namespace App\Services;

use App\Models\Compromiso;
use App\Models\Culto;
use App\Models\Persona;
use App\Models\Promesa;
use App\Models\SobreDetalle;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Fuente unica de verdad para el calculo de promesas y compromisos.
 *
 * Antes esta logica estaba duplicada en tres lugares y una de las copias
 * estaba mal:
 *   - CompromisoController::calcularMontoPrometido      (correcta)
 *   - PromesasReporteController::calcularMontoPrometidoMes (correcta, duplicada)
 *   - DashboardController (inline)                      (INCORRECTA: usaba
 *     promesa->monto sin ajustar por frecuencia, por eso el dashboard y el
 *     reporte mostraban cifras distintas)
 *
 * Ademas centraliza la sincronizacion de la tabla `compromisos`, que es
 * derivada: se calcula a partir de las promesas y de los sobres realmente
 * registrados. Antes solo se actualizaba cuando alguien abria la pantalla
 * del mes en curso, asi que los meses pasados quedaban con monto_dado
 * desactualizado (o sin fila) y los miembros veian como impagos meses que
 * si habian pagado.
 */
class CalculoPromesasService
{
    /**
     * Monto que se espera de una promesa en un mes concreto, segun su frecuencia.
     *
     *   mensual    -> el monto tal cual
     *   quincenal  -> monto x 2
     *   semanal    -> monto x cantidad de domingos del mes
     */
    public function montoPrometidoMes(Promesa $promesa, int $año, int $mes): float
    {
        switch ($promesa->frecuencia) {
            case 'semanal':
                return (float) $promesa->monto * $this->domingosDelMes($año, $mes);

            case 'quincenal':
                return (float) $promesa->monto * 2;

            case 'mensual':
            default:
                return (float) $promesa->monto;
        }
    }

    public function domingosDelMes(int $año, int $mes): int
    {
        $fecha = Carbon::create($año, $mes, 1)->startOfMonth();
        $fin = $fecha->copy()->endOfMonth();

        $domingos = 0;
        while ($fecha->lte($fin)) {
            if ($fecha->dayOfWeek === Carbon::SUNDAY) {
                $domingos++;
            }
            $fecha->addDay();
        }

        return $domingos;
    }

    /**
     * Cuanto aporto realmente una persona en una categoria durante un mes,
     * sumando los detalles de todos sus sobres de ese mes.
     */
    public function montoDado(int $personaId, string $categoria, int $año, int $mes): float
    {
        return (float) SobreDetalle::whereHas('sobre', function ($q) use ($personaId, $año, $mes) {
            $q->where('persona_id', $personaId)
                ->whereHas('culto', function ($q2) use ($año, $mes) {
                    $q2->whereYear('fecha', $año)->whereMonth('fecha', $mes);
                });
        })
            ->where('categoria', $categoria)
            ->sum('monto');
    }

    /**
     * Deja la tabla `compromisos` de una persona/mes igual a la realidad:
     * una fila por promesa, con el prometido segun frecuencia y el dado
     * segun los sobres. Devuelve las filas resultantes.
     */
    public function sincronizar(Persona $persona, int $año, int $mes): Collection
    {
        if (! $persona->relationLoaded('promesas')) {
            $persona->load('promesas');
        }

        $resultado = collect();

        foreach ($persona->promesas as $promesa) {
            $prometido = $this->montoPrometidoMes($promesa, $año, $mes);
            $dado = $this->montoDado($persona->id, $promesa->categoria, $año, $mes);

            $compromiso = Compromiso::updateOrCreate(
                [
                    'persona_id' => $persona->id,
                    'categoria' => $promesa->categoria,
                    'año' => $año,
                    'mes' => $mes,
                ],
                [
                    'monto_prometido' => $prometido,
                    'monto_dado' => $dado,
                    // Cada mes es independiente: no se arrastra saldo previo.
                    'saldo_anterior' => 0,
                    'saldo_actual' => $dado - $prometido,
                ]
            );

            $resultado->push($compromiso);
        }

        return $resultado;
    }

    /**
     * Sincroniza todos los meses en que la persona tuvo actividad (o tiene
     * promesas vigentes), desde su primer aporte/creacion hasta hoy.
     */
    public function sincronizarHistorial(Persona $persona): int
    {
        $desde = $this->primerMesRelevante($persona);
        $hasta = Carbon::now()->startOfMonth();

        $meses = 0;
        $cursor = $desde->copy();
        while ($cursor->lte($hasta)) {
            $this->sincronizar($persona, $cursor->year, $cursor->month);
            $cursor->addMonth();
            $meses++;
        }

        return $meses;
    }

    /**
     * Tras guardar/editar/borrar un sobre hay que refrescar los compromisos
     * de las personas involucradas en ese culto, para el mes del culto.
     */
    public function sincronizarCulto(Culto $culto, ?int $personaId = null): void
    {
        $fecha = $culto->fecha instanceof Carbon
            ? $culto->fecha
            : Carbon::parse($culto->fecha);

        $ids = $personaId
            ? [$personaId]
            : $culto->sobres()->whereNotNull('persona_id')->distinct()->pluck('persona_id')->all();

        if (empty($ids)) {
            return;
        }

        Persona::with('promesas')->whereIn('id', $ids)->get()
            ->each(fn (Persona $p) => $this->sincronizar($p, $fecha->year, $fecha->month));
    }

    /**
     * Primer mes a considerar: el mas antiguo entre su creacion y su primer
     * aporte registrado (puede haber aportes anteriores a la fecha de alta).
     */
    protected function primerMesRelevante(Persona $persona): Carbon
    {
        $creacion = $persona->created_at
            ? Carbon::parse($persona->created_at)->startOfMonth()
            : Carbon::now()->startOfMonth();

        $primerAporte = $persona->sobres()
            ->join('cultos', 'cultos.id', '=', 'sobres.culto_id')
            ->min('cultos.fecha');

        if ($primerAporte) {
            $fechaAporte = Carbon::parse($primerAporte)->startOfMonth();
            if ($fechaAporte->lt($creacion)) {
                return $fechaAporte;
            }
        }

        return $creacion;
    }
}
