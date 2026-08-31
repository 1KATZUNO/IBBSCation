<?php

namespace App\Console\Commands;

use App\Models\Compromiso;
use App\Models\Persona;
use App\Services\CalculoPromesasService;
use Illuminate\Console\Command;

/**
 * Reconstruye la tabla derivada `compromisos` a partir de las promesas y de
 * los sobres realmente registrados.
 *
 * Hacia falta porque `compromisos` solo se actualizaba al abrir la pantalla
 * del mes en curso: los meses anteriores quedaban con monto_dado = 0 (o sin
 * fila), y los miembros veian como impagos meses que si habian pagado.
 *
 *   php artisan compromisos:sincronizar --dry-run   # solo reporta
 *   php artisan compromisos:sincronizar             # corrige
 *   php artisan compromisos:sincronizar --todas     # incluye personas inactivas
 */
class SincronizarCompromisos extends Command
{
    protected $signature = 'compromisos:sincronizar {--dry-run} {--todas}';

    protected $description = 'Recalcula los compromisos de todas las personas (historial completo) segun sus promesas y sobres.';

    public function handle(CalculoPromesasService $servicio): int
    {
        $query = Persona::with('promesas');
        if (! $this->option('todas')) {
            $query->where('activo', true);
        }
        $personas = $query->get();

        $dry = (bool) $this->option('dry-run');

        $this->info(sprintf(
            '%s %d personas%s',
            $dry ? 'Analizando' : 'Sincronizando',
            $personas->count(),
            $this->option('todas') ? ' (incluye inactivas)' : ' activas'
        ));

        $antes = Compromiso::count();
        $sumaDadoAntes = (float) Compromiso::sum('monto_dado');

        if ($dry) {
            // En modo simulacion no se escribe: solo se reporta el estado actual
            // para poder comparar contra la corrida real.
            $this->line('  Filas actuales en compromisos: '.$antes);
            $this->line('  Suma monto_dado actual:        '.number_format($sumaDadoAntes, 2));
            $this->warn('  --dry-run activo: no se escribio nada.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($personas->count());
        $bar->start();

        $meses = 0;
        foreach ($personas as $persona) {
            $meses += $servicio->sincronizarHistorial($persona);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $despues = Compromiso::count();
        $sumaDadoDespues = (float) Compromiso::sum('monto_dado');

        $this->table(
            ['Metrica', 'Antes', 'Despues', 'Diferencia'],
            [
                ['Filas en compromisos', $antes, $despues, $despues - $antes],
                [
                    'Suma monto_dado',
                    number_format($sumaDadoAntes, 2),
                    number_format($sumaDadoDespues, 2),
                    number_format($sumaDadoDespues - $sumaDadoAntes, 2),
                ],
            ]
        );

        $this->info("Meses procesados: {$meses}");

        return self::SUCCESS;
    }
}
