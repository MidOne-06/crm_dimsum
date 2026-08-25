<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLocal extends Model
{
    protected $fillable = [
        'user_id',
        'local_id',
        'local_nombre',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
