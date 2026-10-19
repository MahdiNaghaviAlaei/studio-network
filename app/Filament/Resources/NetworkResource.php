<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NetworkResource\Pages;
use App\Models\Network;
use App\Services\NetworkGraph;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NetworkResource extends Resource
{
    protected static ?string $model = Network::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'مدیریت شبکه';

    protected static ?string $modelLabel = 'شبکه';

    protected static ?string $pluralModelLabel = 'مدیریت شبکه';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('مشخصات شبکه')->schema([
                Forms\Components\TextInput::make('name')->label('نام شبکه')->required()->maxLength(255),
                Forms\Components\Select::make('design_type')->label('نوع نقشه')
                    ->options(['network' => 'توپولوژی شبکه', 'rack' => 'چیدمان رک'])
                    ->default('network')->required()->disabledOn('edit')->dehydrated(),
                Forms\Components\Textarea::make('description')->label('توضیحات')->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->label('نام شبکه')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('design_type')->label('نوع')->badge()
                ->formatStateUsing(fn (string $state) => ['network' => 'شبکه', 'rack' => 'رک', 'electrical' => 'برق'][$state] ?? $state),
            Tables\Columns\TextColumn::make('node_count')->label('تجهیزات')->numeric(),
            Tables\Columns\TextColumn::make('group_count')->label('ناحیه‌ها')->numeric(),
            Tables\Columns\TextColumn::make('revision')->label('نسخه ذخیره‌شده')->numeric(),
            Tables\Columns\TextColumn::make('updated_at')->label('آخرین تغییر')->since(),
        ])->actions([
            Tables\Actions\Action::make('map')->label('باز کردن نقشه')->icon('heroicon-o-map')
                ->url(fn (Network $record) => static::getUrl('map', ['record' => $record])),
            Tables\Actions\EditAction::make()->label('ویرایش'),
            Tables\Actions\Action::make('duplicate')->label('کپی شبکه')->icon('heroicon-o-document-duplicate')
                ->form([Forms\Components\TextInput::make('name')->label('نام نسخه جدید')->required()->maxLength(255)])
                ->action(function (Network $record, array $data) {
                    $copy = app(NetworkGraph::class)->duplicate($record, $data['name']);

                    return redirect(static::getUrl('map', ['record' => $copy]));
                }),
            Tables\Actions\DeleteAction::make()->label('حذف'),
        ])->recordUrl(fn (Network $record) => static::getUrl('map', ['record' => $record]))
            ->emptyStateHeading('اولین شبکه را بسازید')
            ->emptyStateDescription('یک شبکه بسازید و تجهیزات و ارتباط‌ها را روی نقشه بچینید.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNetworks::route('/'),
            'create' => Pages\CreateNetwork::route('/create'),
            'edit' => Pages\EditNetwork::route('/{record}/edit'),
            'map' => Pages\NetworkMap::route('/{record}/map'),
        ];
    }
}
