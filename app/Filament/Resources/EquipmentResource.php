<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EquipmentResource\Pages;
use App\Models\Equipment;
use App\Support\NetworkCatalog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EquipmentResource extends Resource
{
    protected static ?string $model = Equipment::class;

    protected static ?string $slug = 'equipment';

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationLabel = 'فهرست تجهیزات';

    protected static ?string $modelLabel = 'تجهیز';

    protected static ?string $pluralModelLabel = 'فهرست تجهیزات';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('مشخصات تجهیز')->schema([
                Forms\Components\TextInput::make('label')->label('نام تجهیز')->required()->maxLength(255),
                Forms\Components\Select::make('type')->label('نوع')->options(NetworkCatalog::devices())->default('server')->required(),
                Forms\Components\TextInput::make('data.ip')->label('IP')->maxLength(255),
                Forms\Components\TextInput::make('data.hostname')->label('Hostname')->maxLength(255),
                Forms\Components\TextInput::make('data.mac')->label('MAC')->maxLength(64),
                Forms\Components\TextInput::make('data.os')->label('سیستم‌عامل')->maxLength(255),
                Forms\Components\Select::make('data.status')->label('وضعیت ثبت‌شده')->options(NetworkCatalog::statuses())->default('unknown')->required(),
                Forms\Components\TagsInput::make('data.tags')->label('برچسب‌ها'),
                Forms\Components\Textarea::make('data.notes')->label('یادداشت')->columnSpanFull()->maxLength(10000),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('label')->label('نام تجهیز')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('networks.name')->label('نقشه‌های مرتبط')->searchable()->listWithLineBreaks()->placeholder('هنوز روی نقشه نیست'),
            Tables\Columns\TextColumn::make('type')->label('نوع')->formatStateUsing(fn ($state) => NetworkCatalog::devices()[$state] ?? $state),
            Tables\Columns\TextColumn::make('data.ip')->label('IP')->searchable(),
            Tables\Columns\TextColumn::make('data.status')->label('وضعیت')->badge()
                ->formatStateUsing(fn ($state) => NetworkCatalog::statuses()[$state] ?? 'نامشخص')
                ->color(fn ($state) => match ($state) {
                    'online' => 'success', 'offline' => 'danger', 'warning' => 'warning', default => 'gray'
                }),
        ])->filters([
            Tables\Filters\SelectFilter::make('network')->relationship('networks', 'name')->label('شبکه'),
            Tables\Filters\SelectFilter::make('type')->label('نوع')->options(NetworkCatalog::devices()),
        ])->actions([
            Tables\Actions\Action::make('map')->label('نقشه شبکه')->icon('heroicon-o-map')
                ->visible(fn (Equipment $record) => $record->nodes()->exists())
                ->form([Forms\Components\Select::make('network_id')->label('شبکه')->required()
                    ->options(fn (Equipment $record) => $record->networks()->pluck('networks.name', 'networks.id'))])
                ->action(function (Equipment $record, array $data, $livewire) {
                    $network = $record->networks()->findOrFail($data['network_id']);
                    $livewire->redirect(NetworkResource::getUrl('map', ['record' => $network]));
                }),
            Tables\Actions\EditAction::make()->label('ویرایش'),
        ])->emptyStateHeading('تجهیزی ثبت نشده است')->emptyStateDescription('تجهیز را اینجا بسازید و در نقشه با «افزودن تجهیز» از فهرست انتخاب کنید.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListEquipment::route('/'), 'create' => Pages\CreateEquipment::route('/create'), 'edit' => Pages\EditEquipment::route('/{record}/edit')];
    }
}
