<?php

namespace App\Services;

use App\Models\Culto;
use App\Models\TotalesCulto;

class CalculoTotalesCultoService
{
    // Legacy column mapping for dual-write backward compatibility
    private const LEGACY_COLUMNS = [
        'diezmo' => 'total_diezmo',
        'ofrenda_especial' => 'total_ofrenda_especial',
        'misiones' => 'total_misiones',
        'seminario' => 'total_seminario',
        'campa' => 'total_campa',
        'prestamo' => 'total_prestamo',
        'construccion' => 'total_construccion',
        'micro' => 'total_micro',
    ];

    public function recalcular(Culto $culto): TotalesCulto
    {
        $sobres = $culto->sobres()->with('detalles')->get();
        $ofrendasSueltas = $culto->ofrendasSueltas;

        // Build category totals dynamically from sobre detalles
        $categoriaTotales = [];
        $cantidadTransferencias = 0;

        foreach ($sobres as $sobre) {
            if ($sobre->metodo_pago === 'transferencia') {
                $cantidadTransferencias++;
            }

            // Los montos se guardan en colones. Si el sobre esta en USD hay que
            // convertir cada detalle con su tipo de cambio: antes se sumaba el
            // monto crudo, asi que un sobre de $10 quedaba registrado como 10
            // colones y el total del culto no cuadraba con lo que mostraban
            // las vistas (que si convierten).
            $tc = ($sobre->moneda === 'USD' && $sobre->tipo_cambio_venta > 0)
                ? (float) $sobre->tipo_cambio_venta
                : 1.0;

            foreach ($sobre->detalles as $detalle) {
                $slug = strtolower($detalle->categoria);
                $monto = $tc === 1.0
                    ? (float) $detalle->monto
                    : round((float) $detalle->monto * $tc, 2);
                $categoriaTotales[$slug] = ($categoriaTotales[$slug] ?? 0) + $monto;
            }
        }

        // Suelto
        $totalSuelto = 0;
        foreach ($ofrendasSueltas as $ofrenda) {
            $totalSuelto += $ofrenda->monto_crc;
        }

        // Egresos
        $totalEgresos = 0;
        $egresos = $culto->egresos ?? collect();
        foreach ($egresos as $egreso) {
            $totalEgresos += $egreso->monto_crc;
        }

        // Total general = sum of all categories + suelto - egresos
        $sumaIngresos = array_sum($categoriaTotales) + $totalSuelto;
        $totalGeneral = $sumaIngresos - $totalEgresos;

        // Build the data array
        $totales = [
            'total_suelto' => $totalSuelto,
            'total_egresos' => $totalEgresos,
            'total_general' => $totalGeneral,
            'cantidad_sobres' => $sobres->count(),
            'cantidad_transferencias' => $cantidadTransferencias,
            'totales_por_categoria' => $categoriaTotales,
        ];

        // Dual-write: also populate legacy columns for backward compat
        foreach (self::LEGACY_COLUMNS as $slug => $column) {
            $totales[$column] = $categoriaTotales[$slug] ?? 0;
        }

        return $culto->totales()->updateOrCreate(
            ['culto_id' => $culto->id],
            $totales
        );
    }
}
