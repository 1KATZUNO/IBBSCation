<?php

namespace App\Console\Commands;

use App\Models\Persona;
use App\Services\CalculoPromesasService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Detecta promesas cuya frecuencia no cuadra con lo que la persona realmente
 * aporta mes a mes.
 *
 * El caso tipico: los montos se cargaron como el total del mes pero la
 * frecuencia quedo en quincenal o semanal, asi que el sistema exige el doble
 * (o x5) y la persona aparece en rojo aunque este al dia.
 *
 * Es solo lectura: reporta y sugiere, no modifica nada. Cambiar la frecuencia
 * de una promesa es una decision de la iglesia, no algo que deba adivinar el
 * sistema.
 *
 *   php artisan promesas:diagnosticar
 *   php artisan promesas:diagnosticar --meses=6
 */
class DiagnosticarPromesas extends Command
{
    protected $signature = 'promesas:diagnosticar {--meses=6}';

    protected $description = 'Reporta promesas cuya frecuencia no coincide con el patron real de aportes.';

    public function handle(CalculoPromesasService $servicio): int
    {
        $meses = max(1, (int) $this->option('meses'));

        // Ventana: los ultimos N meses completos.
        $periodos = [];
        $cursor = Carbon::now()->startOfMonth()->subMonths($meses);
        for ($i = 0; $i < $meses; $i++) {
            $periodos[] = [(int) $cursor->year, (int) $cursor->month];
            $cursor->addMonth();
        }

        $personas = Persona::with('promesas')
            ->whereHas('promesas')
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();

        $filas = [];

        foreach ($personas as $persona) {
            // Solo interesa quien tiene alguna promesa no mensual: con mensual
            // ambas interpretaciones coinciden y no hay nada que revisar.
            $noMensuales = $persona->promesas->filter(fn ($p) => $p->frecuencia !== 'mensual');
            if ($noMensuales->isEmpty()) {
                continue;
            }

            $pagado = 0.0;
            $espAjustado = 0.0;
            $espCrudo = 0.0;
            $mesesConAporte = 0;

            foreach ($periodos as [$a, $m]) {
                $delMes = 0.0;
                foreach ($persona->promesas as $promesa) {
                    $delMes += $servicio->montoDado($persona->id, $promesa->categoria, $a, $m);
                }
                if ($delMes <= 0) {
                    continue;
                }

                $mesesConAporte++;
                $pagado += $delMes;
                foreach ($persona->promesas as $promesa) {
                    $espAjustado += $servicio->montoPrometidoMes($promesa, $a, $m);
                    $espCrudo += (float) $promesa->monto;
                }
            }

            if ($mesesConAporte === 0) {
                continue;
            }

            $prom = $pagado / $mesesConAporte;
            $aj = $espAjustado / $mesesConAporte;
            $cr = $espCrudo / $mesesConAporte;

            $dAj = abs($prom - $aj);
            $dCr = abs($prom - $cr);

            // Solo se marca cuando la evidencia es clara: el patron encaja
            // mucho mejor con el monto crudo que con el ajustado.
            if ($dCr < $dAj * 0.6) {
                $filas[] = [
                    $persona->id,
                    mb_substr($persona->nombre, 0, 26),
                    $noMensuales->pluck('frecuencia')->unique()->implode(','),
                    number_format($prom, 0),
                    number_format($aj, 0),
                    number_format($cr, 0),
                    'revisar frecuencia',
                ];
            }
        }

        $this->newLine();
        if (empty($filas)) {
            $this->info('No se detectaron promesas con frecuencia sospechosa.');

            return self::SUCCESS;
        }

        $this->warn(count($filas).' persona(s) con promesas cuya frecuencia no cuadra con lo que aportan:');
        $this->newLine();
        $this->table(
            ['ID', 'Persona', 'Frecuencia', 'Aporta/mes', 'Exige ahora', 'Si fuera mensual', 'Sugerencia'],
            $filas
        );
        $this->newLine();
        $this->line('  "Exige ahora" es el monto ajustado por frecuencia (lo que el sistema le pide).');
        $this->line('  "Si fuera mensual" es el monto tal cual, sin multiplicar.');
        $this->line('  Cuando la persona aporta consistentemente lo segundo, lo mas probable es que');
        $this->line('  los montos se hayan cargado como total del mes y la frecuencia quedara mal.');
        $this->newLine();
        $this->line('  Se corrige en la ficha de la persona. Despues conviene correr:');
        $this->line('    php artisan compromisos:sincronizar');

        return self::SUCCESS;
    }
}
