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
        $acumulado = $this->promesas->resumenAcumulado($persona);

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
        $this->promesas->sincronizarHistorial($persona);
        $acumulado = $this->promesas->resumenAcumulado($persona);

        // Compromisos mes a mes, del mas reciente al mas viejo, sin los meses
        // futuros que pudo crear alguien navegando el selector.
        $hoy = Carbon::now();
        $filas = Compromiso::where('persona_id', $persona->id)
            ->where(function ($q) use ($hoy) {
                $q->where('año', '<', $hoy->year)
                    ->orWhere(function ($q2) use ($hoy) {
                        $q2->where('año', $hoy->year)->where('mes', '<=', $hoy->month);
                    });
            })
            ->orderByDesc('año')->orderByDesc('mes')
            ->get();

        // Por rubro: cuanto le tocaba y cuanto lleva dado en total.
        $porRubro = $filas->groupBy('categoria')->map(fn ($g, $cat) => [
            'categoria' => $cat,
            'prometido' => (float) $g->sum('monto_prometido'),
            'dado' => (float) $g->sum('monto_dado'),
        ])->sortByDesc('prometido')->values();

        // Mes a mes, sumando todos los rubros de cada mes.
        $porMes = $filas->groupBy(fn ($c) => $c->año.'-'.str_pad($c->mes, 2, '0', STR_PAD_LEFT))
            ->map(fn ($g, $k) => [
                'etiqueta' => Carbon::createFromFormat('Y-m-d', $k.'-01')->locale('es')->isoFormat('MMMM YYYY'),
                'prometido' => (float) $g->sum('monto_prometido'),
                'dado' => (float) $g->sum('monto_dado'),
            ])->values();

        // Cada sobre que entrego, con su fecha: es la parte que la gente
        // reconoce, porque es el papel que llenaron ese domingo.
        $aportes = $persona->sobres()
            ->with(['culto', 'detalles'])
            ->join('cultos', 'cultos.id', '=', 'sobres.culto_id')
            ->orderBy('cultos.fecha')
            ->select('sobres.*')
            ->get();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdfs.estado-persona', [
            'persona' => $persona,
            'acumulado' => $acumulado,
            'porRubro' => $porRubro,
            'porMes' => $porMes,
            'aportes' => $aportes,
            'aportesSinPromesa' => $this->promesas->aportesSinPromesa($persona, $hoy->year),
        ] + tenant_pdf_data())->setPaper('a4', 'portrait');

        $nombre = str_replace(' ', '_', trim($persona->nombre)) ?: 'persona';

        return $pdf->download('estado_'.$nombre.'_'.$hoy->format('Y-m-d').'.pdf');
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
