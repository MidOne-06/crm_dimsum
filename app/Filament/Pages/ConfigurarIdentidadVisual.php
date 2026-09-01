<?php

namespace App\Filament\Pages;

use App\Models\BrandingSetting;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
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
        $setting = BrandingSetting::current();

        $this->form->fill([
            ...$setting->only(['brand_name', 'logo_path', 'favicon_path', 'logo_height', 'primary_color']),
            'logo_height' => $setting->logoHeight(),
            'primary_color' => $setting->primaryColor(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Identidad del panel')
                    ->description('Logo, nombre y color usados en el panel administrativo.')
                    ->compact()
                    ->schema([
                        FileUpload::make('logo_path')
                            ->label('Logo principal')
                            ->disk('public')
                            ->directory('branding')
                            ->visibility('public')
                            ->image()
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                            ->imageEditor()
                            ->imagePreviewHeight('72')
                            ->maxSize(2048)
                            ->helperText('PNG, JPG o WEBP · máximo 2 MB'),
                        FileUpload::make('favicon_path')
                            ->label('Icono de pestaña')
                            ->disk('public')
                            ->directory('branding/favicons')
                            ->visibility('public')
                            ->image()
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/x-icon'])
                            ->imagePreviewHeight('72')
                            ->maxSize(1024)
                            ->helperText('Se muestra en la pestaña del navegador.'),
                        TextInput::make('brand_name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(120)
                            ->columnSpan(['default' => 1, 'md' => 2]),
                        Select::make('logo_height')
                            ->label('Tamaño del logo')
                            ->options([
                                '1.75rem' => 'Compacto',
                                '2.25rem' => 'Estándar',
                                '2.75rem' => 'Grande',
                            ])
                            ->required(),
                        ColorPicker::make('primary_color')
                            ->label('Color principal')
                            ->required()
                            ->hex(),
                    ])
                    ->columns(['default' => 1, 'sm' => 2, 'xl' => 4]),
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
            ->body('La identidad del panel se actualizó correctamente.')
            ->success()
            ->send();

        // El logo se evalúa al construir el panel. Una redirección completa
        // evita conservar en el encabezado la imagen anterior del estado Livewire.
        $this->redirect(static::getUrl(), navigate: false);
    }
}
