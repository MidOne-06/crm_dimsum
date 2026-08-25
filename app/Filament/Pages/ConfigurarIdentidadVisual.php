<?php

namespace App\Filament\Pages;

use App\Models\BrandingSetting;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ConfigurarIdentidadVisual extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';
    protected static ?string $navigationLabel = 'Apariencia';
    protected static ?string $title = 'Apariencia';
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';
    protected static ?int $navigationSort = 93;
    protected string $view = 'filament.pages.configurar-identidad-visual';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('branding.manage');
    }

    public function mount(): void
    {
        $this->form->fill(BrandingSetting::current()->only(['brand_name', 'logo_path']));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make()
                    ->schema([
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->disk('public')
                            ->directory('branding')
                            ->visibility('public')
                            ->image()
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                            ->imageEditor()
                            ->imagePreviewHeight('96')
                            ->maxSize(2048),
                        TextInput::make('brand_name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(120),
                    ])
                    ->columns(['default' => 1, 'md' => 2]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $setting = BrandingSetting::current();
        $setting->fill($this->form->getState());
        $setting->save();

        Notification::make()
            ->title('Apariencia actualizada')
            ->success()
            ->send();
    }
}
