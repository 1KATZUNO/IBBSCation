<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Egreso extends Model
{
    protected $table = 'egresos';

    protected $fillable = [
        'culto_id',
        'monto',
        'moneda',
        'tipo_cambio_venta',
        'descripcion',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'tipo_cambio_venta' => 'decimal:4',
    ];

    /**
     * Monto convertido a colones. Faltaba igual que en OfrendaSuelta: las
     * vistas restaban $egreso->monto_crc, que era null, asi que los egresos
     * nunca se descontaban del efectivo.
     */
    public function getMontoCrcAttribute(): float
    {
        if ($this->moneda === 'USD' && $this->tipo_cambio_venta > 0) {
            return round($this->monto * $this->tipo_cambio_venta, 2);
        }

        return (float) $this->monto;
    }

    public function culto(): BelongsTo
    {
        return $this->belongsTo(Culto::class);
    }
}
