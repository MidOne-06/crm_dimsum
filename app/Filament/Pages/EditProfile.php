<?php

namespace App\Filament\Pages;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        FileUpload::make('avatar_path')
                            ->label('Foto de perfil')
                            ->disk('public')
                            ->directory('avatars')
                            ->visibility('public')
                            ->avatar()
                            ->imageEditor()
                            ->maxSize(2048)
                            ->columnSpanFull(),
                        Grid::make(['default' => 1, 'md' => 2])
                            ->schema([
                                $this->getNameFormComponent(),
                                $this->getEmailFormComponent(),
                                $this->getPasswordFormComponent(),
                                $this->getPasswordConfirmationFormComponent(),
                                $this->getCurrentPasswordFormComponent(),
                            ]),
                    ]),
            ]);
    }
}
