<?php

namespace App\Http\Controllers;

use App\Models\Compromiso;
use App\Models\Persona;
use App\Services\CalculoPromesasService;
use Carbon\Carbon;

class CompromisoController extends Controller
{
    public function __construct(private CalculoPromesasService $promesas)
    {
    }

    /**
     * Muestra el estado de compromisos de una persona.
     *
     * Se resincroniza TODO el historial (no solo el mes seleccionado): antes
     * solo se recalculaba el mes en curso, asi que los meses anteriores
     * quedaban con monto_dado viejo -- normalmente 0 -- y la persona veia
     * como impagos meses que si habia pagado.
     */
    public function show(Persona $persona)
    {
        $año = (int) request('año', Carbon::now()->year);
        $mes = (int) request('mes', Carbon::now()->month);

        // Deja toda la tabla derivada de esta persona igual a la realidad.
        $this->promesas->sincronizarHistorial($persona);

        // Asegura que el mes consultado exista aunque quede fuera del rango
        // historico (por ejemplo si se navega a un mes futuro).
        $compromisos = $this->promesas->sincronizar($persona, $año, $mes);

        $historial = Compromiso::where('persona_id', $persona->id)
            ->orderBy('año', 'desc')
            ->orderBy('mes', 'desc')
            ->get()
            ->groupBy(fn ($item) => $item->año.'-'.str_pad($item->mes, 2, '0', STR_PAD_LEFT));

        $resumenTotal = [
            'total_prometido' => $compromisos->sum('monto_prometido'),
            'total_dado' => $compromisos->sum('monto_dado'),
            'saldo_total' => $compromisos->sum('saldo_actual'),
        ];

        // Si va al dia o no se decide con el acumulado, no con el mes que se
        // este viendo: quien no dio en un mes pero repuso en otro esta al dia.
        $acumulado = $this->promesas->resumenAcumulado($persona, $año, $mes);

        // Plata que la persona aporto en categorias sin promesa: no computa
        // contra ningun compromiso, y antes no se veia en ninguna parte.
        $aportesSinPromesa = $this->promesas->aportesSinPromesa($persona, $año);

        return view('compromisos.show', compact(
            'persona', 'compromisos', 'año', 'mes', 'historial', 'resumenTotal',
            'aportesSinPromesa', 'acumulado'
        ));
    }

    /**
     * Estado de cuenta de una persona en PDF: lo mismo que ve en pantalla,
     * pero para imprimir o mandarle por WhatsApp cuando lo pide.
     */
    public function pdf(Persona $persona)
    {
        $hoy = Carbon::now();
        $anio = (int) request('año', $hoy->year);
        $hasta = min(12, max(1, (int) request('mes', $anio < $hoy->year ? 12 : $hoy->month)));

        $this->promesas->sincronizarHistorial($persona);
        $acumulado = $this->promesas->resumenAcumulado($persona, $anio, $hasta);

        // Todo el reporte va acotado al anio y al mes de corte elegidos: la
        // iglesia lleva las promesas por anio, asi que arrastrar diciembre del
        // anio anterior ensuciaba el porcentaje.
        $filas = Compromiso::where('persona_id', $persona->id)
            ->where('año', $anio)
            ->where('mes', '<=', $hasta)
            ->orderByDesc('mes')
            ->get();

        // Por rubro: cuanto le tocaba y cuanto lleva dado en el periodo.
        $porRubro = $filas->groupBy('categoria')->map(fn ($g, $cat) => [
            'categoria' => $cat,
            'prometido' => (float) $g->sum('monto_prometido'),
            'dado' => (float) $g->sum('monto_dado'),
        ])->sortByDesc('prometido')->values();

        // Cada sobre que entrego en el periodo, con su fecha: es la parte que
        // la gente reconoce, porque es el papel que llenaron ese domingo.
        $aportes = $persona->sobres()
            ->with(['culto', 'detalles'])
            ->join('cultos', 'cultos.id', '=', 'sobres.culto_id')
            ->whereYear('cultos.fecha', $anio)
            ->whereMonth('cultos.fecha', '<=', $hasta)
            ->orderBy('cultos.fecha')
            ->select('sobres.*')
            ->get();

        // Todo lo entregado, rubro por rubro, incluidos diezmo y ofrenda
        // especial. El cuadro de cumplimiento solo mira los rubros de la
        // promesa, asi que sin esto el diezmo -- que suele ser la mayor parte
        // de lo que da la persona -- solo aparecia dentro del detalle de cada
        // sobre y no se veia sumado en ninguna parte.
        $nombresCat = tenant_categories()->pluck('nombre', 'slug')->all();
        $rubrosPromesa = $porRubro->pluck('categoria')->all();

        $entregado = collect();
        foreach ($aportes as $sobre) {
            foreach ($sobre->detalles as $d) {
                $slug = strtolower($d->categoria);
                $monto = (float) $d->monto;
                if ($sobre->moneda === 'USD' && $sobre->tipo_cambio_venta > 0) {
                    $monto = round($monto * (float) $sobre->tipo_cambio_venta, 2);
                }
                $entregado[$slug] = ($entregado[$slug] ?? 0) + $monto;
            }
        }
        $entregado = $entregado->map(fn ($total, $slug) => [
            'nombre' => $nombresCat[$slug] ?? ucfirst($slug),
            'total' => $total,
            'de_promesa' => in_array($slug, $rubrosPromesa, true),
        ])->sortByDesc('total')->values();

        // Mes a mes. Ademas de la promesa se muestra el diezmo y el total
        // entregado en el mes: sin eso, un mes donde la persona solo diezmo
        // salia en rojo y aparentaba que no habia dado nada.
        $mensual = [];
        foreach ($filas as $c) {
            $m = (int) $c->mes;
            $mensual[$m] ??= ['prometido' => 0.0, 'dado' => 0.0, 'diezmo' => 0.0, 'total' => 0.0];
            $mensual[$m]['prometido'] += (float) $c->monto_prometido;
            $mensual[$m]['dado'] += (float) $c->monto_dado;
        }
        foreach ($aportes as $sobre) {
            if (! $sobre->culto) {
                continue;
            }
            $m = (int) $sobre->culto->fecha->format('n');
            $mensual[$m] ??= ['prometido' => 0.0, 'dado' => 0.0, 'diezmo' => 0.0, 'total' => 0.0];
            foreach ($sobre->detalles as $d) {
                $monto = (float) $d->monto;
                if ($sobre->moneda === 'USD' && $sobre->tipo_cambio_venta > 0) {
                    $monto = round($monto * (float) $sobre->tipo_cambio_venta, 2);
                }
                $mensual[$m]['total'] += $monto;
                if (strtolower($d->categoria) === 'diezmo') {
                    $mensual[$m]['diezmo'] += $monto;
                }
            }
        }
        krsort($mensual);
        $porMes = collect($mensual)->map(fn ($v, $m) => $v + [
            'etiqueta' => Carbon::create($anio, (int) $m, 1)->locale('es')->isoFormat('MMMM YYYY'),
        ])->values();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdfs.estado-persona', [
            'entregado' => $entregado,
            'persona' => $persona,
            'acumulado' => $acumulado,
            'porRubro' => $porRubro,
            'porMes' => $porMes,
            'aportes' => $aportes,
            'periodo' => 'Enero a '.Carbon::create($anio, $hasta, 1)->locale('es')->isoFormat('MMMM').' de '.$anio,
            'aportesSinPromesa' => $this->promesas->aportesSinPromesa($persona, $anio),
        ] + tenant_pdf_data())->setPaper('a4', 'portrait');

        $nombre = str_replace(' ', '_', trim($persona->nombre)) ?: 'persona';

        return $pdf->download(sprintf('estado_%s_%d-%02d.pdf', $nombre, $anio, $hasta));
    }

    /**
     * Recalcula el historial completo de todas las personas activas.
     * Antes solo tocaba el mes en curso.
     */
    public function recalcular()
    {
        $personas = Persona::with('promesas')->where('activo', true)->get();

        foreach ($personas as $persona) {
            $this->promesas->sincronizarHistorial($persona);
        }

        return redirect()->back()->with(
            'success',
            'Compromisos recalculados para '.$personas->count().' personas (historial completo).'
        );
    }
}
