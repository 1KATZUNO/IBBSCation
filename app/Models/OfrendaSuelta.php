<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfrendaSuelta extends Model
{
    protected $table = 'ofrenda_suelta';

    protected $fillable = [
        'culto_id',
        'monto',
        'moneda',
        'tipo_cambio_venta',
        'metodo_pago',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'tipo_cambio_venta' => 'decimal:4',
    ];

    /**
     * Monto convertido a colones.
     *
     * Las vistas del recuento (y los PDF) suman con $ofrenda->monto_crc, pero
     * este accesor no existia: Eloquent devolvia null y el dinero suelto
     * terminaba sumando 0, por eso "Total Efectivo" y "Total General" no lo
     * incluian. Se replica la misma logica que Sobre::getTotalDeclaradoCrcAttribute.
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
