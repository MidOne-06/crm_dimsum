<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class BrandingSetting extends Model
{
    protected $fillable = ['brand_name', 'logo_path'];

    public static function current(): self
    {
        return static::query()->find(1) ?? new static(['brand_name' => 'Panel Administrativo']);
    }

    public function logoUrl(): string
    {
        return filled($this->logo_path)
            ? Storage::disk('public')->url($this->logo_path)
            : asset('images/opm-mark.svg');
    }

    protected static function booted(): void
    {
        static::updating(function (self $setting): void {
            if ($setting->isDirty('logo_path') && filled($setting->getOriginal('logo_path'))) {
                Storage::disk('public')->delete($setting->getOriginal('logo_path'));
            }
        });
    }
}
