<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sobre extends Model
{
    protected $table = 'sobres';

    protected $fillable = [
        'culto_id',
        'persona_id',
        'numero_sobre',
        'metodo_pago',
        'comprobante_numero',
        'total_declarado',
        'moneda',
        'tipo_cambio_venta',
        'tipo_cambio_id',
        'notas',
    ];

    protected $casts = [
        'total_declarado' => 'decimal:2',
        'tipo_cambio_venta' => 'decimal:4',
    ];

    public function getTotalDeclaradoCrcAttribute(): float
    {
        if ($this->moneda === 'USD' && $this->tipo_cambio_venta > 0) {
            return round($this->total_declarado * $this->tipo_cambio_venta, 2);
        }
        return (float) $this->total_declarado;
    }

    public function getMontoCrcAttribute(): float
    {
        return $this->getTotalDeclaradoCrcAttribute();
    }

    public function culto(): BelongsTo
    {
        return $this->belongsTo(Culto::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(SobreDetalle::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($sobre) {
            if (!$sobre->numero_sobre) {
                $maxNumero = static::where('culto_id', $sobre->culto_id)
                    ->max('numero_sobre');
                $sobre->numero_sobre = $maxNumero ? $maxNumero + 1 : 1;
            }
        });
    }
}
