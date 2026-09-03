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
            $clave = [
                'persona_id' => $persona->id,
                'categoria' => $promesa->categoria,
                'año' => $año,
                'mes' => $mes,
            ];

            $dado = $this->montoDado($persona->id, $promesa->categoria, $año, $mes);

            // Una promesa no se debe antes de estar vigente: exigirla generaba
            // deuda inexistente en los meses previos a su alta.
            //
            // No se borra la fila sin mas: si la persona SI aporto en ese mes
            // hay que seguir mostrandolo (con vigente_desde sembrado desde
            // created_at hay casos de aportes anteriores al alta, porque editar
            // una persona recreaba sus promesas). Se conserva el aporte con
            // prometido = 0, y solo se elimina la fila si no hubo movimiento.
            if (! $promesa->vigenteEn($año, $mes)) {
                if ($dado <= 0) {
                    Compromiso::where($clave)->delete();

                    continue;
                }

                $resultado->push(Compromiso::updateOrCreate($clave, [
                    'monto_prometido' => 0,
                    'monto_dado' => $dado,
                    'saldo_anterior' => 0,
                    'saldo_actual' => $dado,
                ]));

                continue;
            }

            $prometido = $this->montoPrometidoMes($promesa, $año, $mes);

            $compromiso = Compromiso::updateOrCreate($clave, [
                'monto_prometido' => $prometido,
                'monto_dado' => $dado,
                // Cada mes es independiente: no se arrastra saldo previo.
                'saldo_anterior' => 0,
                'saldo_actual' => $dado - $prometido,
            ]);

            $resultado->push($compromiso);
        }

        return $resultado;
    }

    /**
     * Aportes de la persona en categorias donde NO tiene promesa registrada.
     *
     * Esa plata no aparecia por ningun lado en la vista individual: la persona
     * daba y no lo veia, lo que se leia como que el sistema "perdia" montos.
     * Se excluyen las categorias marcadas como excluidas de promesas (diezmo,
     * ofrenda especial), que por diseño nunca se prometen.
     *
     * @return array<int, array{categoria:string, nombre:string, total:float}>
     */
    public function aportesSinPromesa(Persona $persona, int $año, ?int $mes = null): array
    {
        $conPromesa = $persona->promesas->pluck('categoria')
            ->map(fn ($c) => strtolower($c))->all();

        $categorias = tenant_categories();
        $excluidas = $categorias->where('excluir_de_promesas', true)
            ->pluck('slug')->map(fn ($s) => strtolower($s))->all();
        $nombres = $categorias->pluck('nombre', 'slug')->all();

        $filas = SobreDetalle::selectRaw('categoria, SUM(monto) as total')
            ->whereHas('sobre', function ($q) use ($persona, $año, $mes) {
                $q->where('persona_id', $persona->id)
                    ->whereHas('culto', function ($q2) use ($año, $mes) {
                        $q2->whereYear('fecha', $año);
                        if ($mes) {
                            $q2->whereMonth('fecha', $mes);
                        }
                    });
            })
            ->groupBy('categoria')
            ->get();

        $out = [];
        foreach ($filas as $f) {
            $slug = strtolower($f->categoria);
            if (in_array($slug, $conPromesa, true) || in_array($slug, $excluidas, true)) {
                continue;
            }
            if ((float) $f->total <= 0) {
                continue;
            }
            $out[] = [
                'categoria' => $slug,
                'nombre' => $nombres[$f->categoria] ?? ucfirst($slug),
                'total' => (float) $f->total,
            ];
        }

        usort($out, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $out;
    }

    /**
     * Sincroniza todos los meses en que la persona tuvo actividad (o tiene
     * promesas vigentes), desde su primer aporte/creacion hasta hoy.
     */
    /**
     * Estado acumulado de la persona dentro de un anio: de enero al mes de
     * corte que se pida, contra todo lo que dio en ese mismo tramo.
     *
     * Mirar mes por mes engaña. Si alguien no dio en enero pero en marzo puso
     * el doble, enero sale en rojo y la persona parece atrasada cuando no lo
     * esta. Lo que decide si va al dia es la suma, no cada mes por separado.
     *
     * El anio acota a proposito: la iglesia lleva las promesas por anio, asi
     * que arrastrar diciembre del anio anterior ensucia el porcentaje.
     */
    public function resumenAcumulado(Persona $persona, ?int $anio = null, ?int $hastaMes = null): array
    {
        $hoy = Carbon::now();
        $anio = $anio ?: $hoy->year;
        $hastaMes = min(12, max(1, $hastaMes ?: ($anio < $hoy->year ? 12 : $hoy->month)));

        $filas = Compromiso::where('persona_id', $persona->id)
            ->where('año', $anio)
            ->where('mes', '<=', $hastaMes)
            ->get();

        // Si el corte cae en el mes en curso, ese mes todavia no se puede
        // exigir: contarlo dejaria a todos atrasados el dia 1. Se cobra hasta
        // el mes anterior, pero SI se cuenta lo que dieron en el mes abierto,
        // porque es justo cuando la gente se pone al dia.
        $enCurso = ($anio === $hoy->year && $hastaMes === $hoy->month);
        $tope = $enCurso ? $hastaMes - 1 : $hastaMes;

        $cerrados = $filas->filter(fn ($c) => $c->mes <= $tope);

        $prometido = (float) $cerrados->sum('monto_prometido');
        $dado = (float) $filas->sum('monto_dado');
        $diferencia = $dado - $prometido;

        // Meses que de verdad se le podian exigir, para expresar el atraso en
        // mensualidades suyas: deber un mes no es lo mismo que deber siete.
        $mesesExigibles = $cerrados->where('monto_prometido', '>', 0)
            ->groupBy(fn ($c) => $c->mes)
            ->count();
        $promedioMes = $mesesExigibles > 0 ? $prometido / $mesesExigibles : 0.0;

        $ordenadas = $cerrados->sortBy('mes');
        $primera = $ordenadas->first();
        $ultima = $ordenadas->last();

        return [
            'anio' => $anio,
            'hasta_mes' => $hastaMes,
            'mes_en_curso' => $enCurso,
            'prometido' => $prometido,
            'dado' => $dado,
            'diferencia' => $diferencia,
            // Un colon de tolerancia: si no, un redondeo deja a alguien
            // "atrasado" por centimos.
            'al_dia' => $diferencia >= -1,
            'porcentaje' => $prometido > 0 ? round($dado / $prometido * 100) : null,
            'meses_exigibles' => $mesesExigibles,
            'meses_atraso' => $promedioMes > 0 ? round(max(0, -$diferencia) / $promedioMes, 1) : 0.0,
            'desde' => $primera ? Carbon::create($anio, $primera->mes, 1) : null,
            'hasta' => $ultima ? Carbon::create($anio, $ultima->mes, 1) : null,
        ];
    }

    public function sincronizarHistorial(Persona $persona): int
    {
        if (! $persona->relationLoaded('promesas')) {
            $persona->load('promesas');
        }

        // Limpia compromisos de promesas que ya no existen (categoria eliminada
        // de la persona): sincronizar() solo recorre las promesas vigentes, asi
        // que esas filas quedarian huerfanas para siempre.
        $categoriasVigentes = $persona->promesas->pluck('categoria')->all();
        Compromiso::where('persona_id', $persona->id)
            ->whereNotIn('categoria', $categoriasVigentes ?: [''])
            ->delete();

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
