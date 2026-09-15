<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        DateTimePicker::make('email_verified_at')
                            ->label('Email verified at'),
                        TextInput::make('password')
                            ->label('New password')
                            ->password()
                            ->revealable()
                            ->rule(Password::default())
                            ->helperText('Leave blank to keep the current password.')
                            ->dehydrated(fn (?string $state): bool => filled($state)),
                    ]),
            ]);
    }
}
