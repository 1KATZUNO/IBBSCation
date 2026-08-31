<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Promesa extends Model
{
    protected $table = 'promesas';

    protected $fillable = [
        'persona_id',
        'categoria',
        'monto',
        'frecuencia',
        'vigente_desde',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'vigente_desde' => 'date',
    ];

    /**
     * Primer (año, mes) en que esta promesa debe cobrarse.
     * Si no hay vigente_desde se cae al mes de creacion del registro.
     */
    public function mesInicio(): array
    {
        $f = $this->vigente_desde ?: $this->created_at;

        return $f ? [(int) $f->format('Y'), (int) $f->format('n')] : [0, 0];
    }

    /**
     * Indica si la promesa ya estaba vigente en el mes dado.
     */
    public function vigenteEn(int $año, int $mes): bool
    {
        [$y, $m] = $this->mesInicio();

        if ($y === 0) {
            return true; // sin fecha conocida: no bloquear el calculo
        }

        return [$año, $mes] >= [$y, $m];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}
