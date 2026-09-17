<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaPayloadArchivo extends Model
{
    protected $table = 'venta_payload_archivos';

    protected $fillable = [
        'venta_id',
        'disk',
        'path',
        'sha256',
        'bytes_originales',
        'bytes_comprimidos',
        'formato',
        'archivado_en',
        'verificado_en',
    ];

    protected function casts(): array
    {
        return [
            'archivado_en' => 'datetime',
            'verificado_en' => 'datetime',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id', 'venta_id');
    }
}
