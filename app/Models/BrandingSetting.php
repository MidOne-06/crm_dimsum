<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class BrandingSetting extends Model
{
    protected $fillable = ['brand_name', 'logo_path', 'favicon_path', 'logo_height', 'primary_color'];

    public static function current(): self
    {
        return static::query()->find(1) ?? new static([
            'brand_name' => 'CRM - DIMSUM',
            'logo_height' => '2.25rem',
            'primary_color' => '#f59e0b',
        ]);
    }

    public function logoUrl(): string
    {
        return filled($this->logo_path)
            ? Storage::disk('public')->url($this->logo_path)
            : asset('images/crm-dimsum-mark.svg');
    }

    public function faviconUrl(): string
    {
        return filled($this->favicon_path)
            ? Storage::disk('public')->url($this->favicon_path)
            : asset('images/crm-dimsum-mark.svg');
    }

    public function logoHeight(): string
    {
        return $this->logo_height ?: '2.25rem';
    }

    public function primaryColor(): string
    {
        return $this->primary_color ?: '#f59e0b';
    }

    protected static function booted(): void
    {
        static::updating(function (self $setting): void {
            foreach (['logo_path', 'favicon_path'] as $attribute) {
                if ($setting->isDirty($attribute) && filled($setting->getOriginal($attribute))) {
                    Storage::disk('public')->delete($setting->getOriginal($attribute));
                }
            }
        });
    }
}
