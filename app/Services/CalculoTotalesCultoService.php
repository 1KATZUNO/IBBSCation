<?php

namespace App\Services;

use App\Models\Culto;
use App\Models\TotalesCulto;

class CalculoTotalesCultoService
{
    public function recalcular(Culto $culto): TotalesCulto
    {
        $sobres = $culto->sobres()->with('detalles')->get();
        $ofrendasSueltas = $culto->ofrendasSueltas;

        $totales = [
            'total_diezmo' => 0,
            'total_ofrenda_especial' => 0,
            'total_misiones' => 0,
            'total_seminario' => 0,
            'total_campa' => 0,
            'total_prestamo' => 0,
            'total_construccion' => 0,
            'total_micro' => 0,
            'total_suelto' => 0,
            'cantidad_sobres' => $sobres->count(),
            'cantidad_transferencias' => 0,
        ];

        // Calcular totales de sobres por categoría
        foreach ($sobres as $sobre) {
            if ($sobre->metodo_pago === 'transferencia') {
                $totales['cantidad_transferencias']++;
            }

            foreach ($sobre->detalles as $detalle) {
                $categoria = strtolower($detalle->categoria);
                $key = 'total_' . $categoria;
                
                if (isset($totales[$key])) {
                    $totales[$key] += $detalle->monto;
                }
            }
        }

        // Calcular total de ofrendas sueltas
        foreach ($ofrendasSueltas as $ofrenda) {
            $totales['total_suelto'] += $ofrenda->monto;
        }

        // Calcular total general
        $totales['total_general'] = array_sum([
            $totales['total_diezmo'],
            $totales['total_ofrenda_especial'],
            $totales['total_misiones'],
            $totales['total_seminario'],
            $totales['total_campa'],
            $totales['total_prestamo'],
            $totales['total_construccion'],
            $totales['total_micro'],
            $totales['total_suelto'],
        ]);

        // Actualizar o crear registro de totales
        return $culto->totales()->updateOrCreate(
            ['culto_id' => $culto->id],
            $totales
        );
    }
}
