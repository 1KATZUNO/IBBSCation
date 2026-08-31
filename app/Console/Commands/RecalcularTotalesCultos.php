<?php

namespace App\Console\Commands;

use App\Models\Culto;
use App\Services\CalculoTotalesCultoService;
use Illuminate\Console\Command;

/**
 * Recalcula la tabla derivada `totales_culto` para todos los cultos.
 *
 * Hace falta despues de corregir CalculoTotalesCultoService, que antes sumaba
 * los montos sin convertir de USD: un sobre de $10 quedaba guardado como 10
 * colones. Los totales ya escritos siguen mal hasta que se recalculen.
 *
 *   php artisan totales:recalcular --dry-run   # solo muestra que cambiaria
 *   php artisan totales:recalcular             # escribe
 */
class RecalcularTotalesCultos extends Command
{
    protected $signature = 'totales:recalcular {--dry-run}';

    protected $description = 'Recalcula totales_culto de todos los cultos (convierte USD a colones).';

    public function handle(CalculoTotalesCultoService $servicio): int
    {
        $cultos = Culto::with(['sobres.detalles', 'ofrendasSueltas', 'egresos', 'totales'])
            ->orderBy('fecha')
            ->get();

        $dry = (bool) $this->option('dry-run');
        $this->info(($dry ? 'Analizando ' : 'Recalculando ').$cultos->count().' cultos');

        $cambios = [];
        foreach ($cultos as $culto) {
            $antes = $culto->totales?->total_general;

            if ($dry) {
                // Reproduce el calculo sin escribir.
                $cats = 0.0;
                foreach ($culto->sobres as $s) {
                    $tc = ($s->moneda === 'USD' && $s->tipo_cambio_venta > 0)
                        ? (float) $s->tipo_cambio_venta : 1.0;
                    foreach ($s->detalles as $d) {
                        $cats += $tc === 1.0 ? (float) $d->monto : round((float) $d->monto * $tc, 2);
                    }
                }
                $suelto = $culto->ofrendasSueltas->sum(fn ($o) => $o->monto_crc);
                $egr = $culto->egresos->sum(fn ($e) => $e->monto_crc);
                $despues = $cats + $suelto - $egr;
            } else {
                $despues = $servicio->recalcular($culto)->total_general;
            }

            if ($antes === null || abs((float) $antes - (float) $despues) > 0.5) {
                $cambios[] = [
                    $culto->fecha->format('Y-m-d'),
                    $culto->tipo_culto,
                    $antes === null ? '(sin totales)' : number_format((float) $antes, 2),
                    number_format((float) $despues, 2),
                    number_format((float) $despues - (float) ($antes ?? 0), 2),
                ];
            }
        }

        $this->newLine();
        if (empty($cambios)) {
            $this->info('Ningun culto cambia: los totales ya estaban correctos.');
        } else {
            $this->warn(count($cambios).' culto(s) con diferencia:');
            $this->table(['Fecha', 'Tipo', 'Antes', 'Despues', 'Diferencia'], $cambios);
        }

        if ($dry) {
            $this->warn('--dry-run activo: no se escribio nada.');
        }

        return self::SUCCESS;
    }
}
