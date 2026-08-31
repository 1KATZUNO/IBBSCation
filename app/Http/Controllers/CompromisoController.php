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

        return view('compromisos.show', compact(
            'persona', 'compromisos', 'año', 'mes', 'historial', 'resumenTotal'
        ));
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
