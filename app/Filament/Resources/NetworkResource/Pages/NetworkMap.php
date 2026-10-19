<?php

namespace App\Filament\Resources\NetworkResource\Pages;

use App\Filament\Resources\EquipmentResource;
use App\Filament\Resources\NetworkResource;
use App\Models\Equipment;
use App\Services\NetworkGraph;
use App\Support\NetworkCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components as F;
use Filament\Forms\Form;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Renderless;

class NetworkMap extends Page
{
    use InteractsWithRecord;

    protected static string $resource = NetworkResource::class;

    protected static string $view = 'filament.resources.network-resource.pages.network-map';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NetworkResource::canView($this->record), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->name;
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }

    public function initialGraph(): array
    {
        return app(NetworkGraph::class)->load($this->record);
    }

    #[Renderless]
    public function saveMap(array $graph): array
    {
        abort_unless(NetworkResource::canEdit($this->record), 403);
        try {
            return ['ok' => true, 'graph' => app(NetworkGraph::class)->save($this->record, $graph, auth()->id())];
        } catch (ValidationException $exception) {
            return ['ok' => false, 'error' => collect($exception->errors())->flatten()->first()];
        }
    }

    #[Renderless]
    public function reloadMap(): array
    {
        abort_unless(NetworkResource::canView($this->record), 403);

        return $this->initialGraph();
    }

    #[Renderless]
    public function validateImport(array $graph): array
    {
        abort_unless(NetworkResource::canEdit($this->record), 403);
        try {
            app(NetworkGraph::class)->validate($graph);

            return ['ok' => true];
        } catch (ValidationException $exception) {
            return ['ok' => false, 'error' => collect($exception->errors())->flatten()->first()];
        }
    }

    public function deviceAction(): Action
    {
        return Action::make('device')->label('تجهیز جدید')->icon('heroicon-o-server')->modalHeading('مشخصات تجهیز')
            ->authorize(fn () => NetworkResource::canEdit($this->record) && EquipmentResource::canCreate())
            ->modalDescription('مشخصات تجهیز در فهرست تجهیزات مشترک است؛ رنگ، محل قرارگیری و یونیت رک مخصوص همین نقشه هستند.')
            ->modalWidth('3xl')->modalSubmitActionLabel('اعمال روی نقشه')
            ->form([
                F\Tabs::make('deviceTabs')->tabs([
                    F\Tabs\Tab::make('مشخصات')->schema([
                        F\TextInput::make('label')->label('نام تجهیز')->required()->maxLength(255),
                        F\Select::make('type')->label('نوع تجهیز')->options(NetworkCatalog::devices())->searchable()->required()->default('server'),
                        F\TextInput::make('data.ip')->label('IP')->maxLength(255),
                        F\TextInput::make('data.hostname')->label('Hostname')->maxLength(255),
                        F\TextInput::make('data.mac')->label('MAC')->maxLength(64),
                        F\TextInput::make('data.os')->label('سیستم‌عامل')->maxLength(255),
                        F\Select::make('data.status')->label('وضعیت ثبت‌شده')->options(NetworkCatalog::statuses())->default('unknown')->required()
                            ->helperText('وضعیت به‌صورت دستی ثبت می‌شود؛ درخواست پایش به شبکه ارسال نمی‌شود.'),
                        F\ColorPicker::make('style.color')->label('رنگ تجهیز')->hex()->default('#38bdf8'),
                        F\Select::make('parent')->label('ناحیه / میزبان / رک')->options(fn () => $this->getMountedAction()?->getArguments()['parents'] ?? [])->nullable(),
                        F\TextInput::make('data.ports')->label('تعداد پورت')->numeric()->integer()->minValue(0)->maxValue(64),
                        F\TextInput::make('data.rack_u')->label('یونیت شروع در رک')->numeric()->integer()->minValue(1)->maxValue(48)->default(1),
                        F\TextInput::make('data.height_u')->label('ارتفاع در رک (U)')->numeric()->integer()->minValue(1)->maxValue(48)->default(1),
                    ])->columns(2),
                    F\Tabs\Tab::make('سرویس‌ها')->schema([
                        F\Repeater::make('data.services')->label('سرویس‌های تجهیز')->schema([
                            F\TextInput::make('name')->label('نام سرویس')->required()->maxLength(100),
                            F\TextInput::make('port')->label('پورت')->numeric()->integer()->minValue(1)->maxValue(65535),
                            F\Select::make('protocol')->label('پروتکل')->options(['tcp' => 'TCP', 'udp' => 'UDP'])->default('tcp'),
                            F\Select::make('status')->label('وضعیت')->options(NetworkCatalog::statuses())->default('unknown'),
                        ])->columns(2)->defaultItems(0)->maxItems(64)->addActionLabel('افزودن سرویس')->collapsible(),
                    ]),
                    F\Tabs\Tab::make('سخت‌افزار و توضیحات')->schema([
                        F\TextInput::make('data.cpu')->label('CPU')->maxLength(100),
                        F\TextInput::make('data.ram')->label('RAM')->maxLength(100),
                        F\TextInput::make('data.disk')->label('Disk')->maxLength(100),
                        F\TagsInput::make('data.tags')->label('برچسب‌ها'),
                        F\Textarea::make('data.notes')->label('یادداشت')->maxLength(10000)->columnSpanFull(),
                        F\Repeater::make('data.properties')->label('ویژگی‌های دلخواه')->schema([
                            F\TextInput::make('key')->label('ویژگی')->required()->maxLength(100),
                            F\TextInput::make('value')->label('مقدار')->maxLength(1000),
                        ])->columns(2)->defaultItems(0)->maxItems(64)->columnSpanFull(),
                    ])->columns(2),
                ])->columnSpanFull(),
            ])->mountUsing(function (Form $form, array $arguments) {
                $node = $arguments['node'] ?? [];
                $form->fill([
                    'label' => $node['label'] ?? '', 'type' => $node['type'] ?? $arguments['type'] ?? 'server',
                    'parent' => $node['parent'] ?? null,
                    'data' => array_replace(['status' => 'unknown', 'services' => [], 'properties' => [], 'tags' => [], 'rack_u' => 1, 'height_u' => 1], $node['data'] ?? []),
                    'style' => array_replace(['color' => '#38bdf8'], $node['style'] ?? []),
                ]);
            })->action(function (array $data, array $arguments) {
                $this->dispatch('network-upsert-node', networkId: $this->record->id, node: array_replace($arguments['node'] ?? [], $data, ['kind' => 'device']));
            });
    }

    public function existingEquipmentAction(): Action
    {
        return Action::make('existingEquipment')->label('افزودن تجهیز')->modalHeading('افزودن از تجهیزات موجود')
            ->authorize(fn () => NetworkResource::canEdit($this->record) && EquipmentResource::canViewAny())
            ->modalDescription('تجهیز ثبت‌شده را انتخاب کنید. تجهیز تکراری روی همین بوم دوباره اضافه نمی‌شود.')
            ->modalSubmitActionLabel('افزودن به بوم')
            ->form([
                F\Select::make('equipment_id')->label('انتخاب تجهیز')->searchable()->required()
                    ->getSearchResultsUsing(function (string $search) {
                        return EquipmentResource::getEloquentQuery()->where(function ($query) use ($search) {
                            $query->where('label', 'like', '%'.$search.'%')->orWhere('data->ip', 'like', '%'.$search.'%')
                                ->orWhere('data->hostname', 'like', '%'.$search.'%');
                        })->orderBy('label')->limit(50)->get()->mapWithKeys(fn ($equipment) => [$equipment->id => $this->equipmentLabel($equipment)]);
                    })
                    ->options(fn () => EquipmentResource::getEloquentQuery()->orderBy('label')->limit(50)->get()
                        ->mapWithKeys(fn ($equipment) => [$equipment->id => $this->equipmentLabel($equipment)]))
                    ->getOptionLabelUsing(fn ($value) => ($equipment = EquipmentResource::getEloquentQuery()->find($value)) ? $this->equipmentLabel($equipment) : null)
                    ->helperText('نام، IP یا Hostname را جستجو کنید. ساخت تجهیز جدید با دکمه جداگانه «تجهیز جدید» انجام می‌شود.'),
            ])->action(function (array $data) {
                $equipment = EquipmentResource::getEloquentQuery()->findOrFail($data['equipment_id']);
                abort_unless(EquipmentResource::canView($equipment), 403);
                $this->dispatch('network-upsert-node', networkId: $this->record->id, node: [
                    'kind' => 'device', 'type' => $equipment->type, 'label' => $equipment->label,
                    'equipment_id' => $equipment->id, 'equipment_revision' => $equipment->revision,
                    'data' => $equipment->data ?? [], 'style' => ['color' => '#38bdf8'],
                ]);
            });
    }

    private function equipmentLabel(Equipment $equipment): string
    {
        return $equipment->label.' — '.(NetworkCatalog::devices()[$equipment->type] ?? $equipment->type)
            .(! empty($equipment->data['ip']) ? ' · '.$equipment->data['ip'] : '');
    }

    public function zoneAction(): Action
    {
        return $this->annotationAction('zone', 'ناحیه', '#38bdf8', 480, 340);
    }

    public function textAction(): Action
    {
        return $this->annotationAction('text', 'یادداشت', '#cbd5e1', 220, 70);
    }

    public function rackAction(): Action
    {
        return $this->annotationAction('rack', 'رک', '#a78bfa', 300, 840);
    }

    protected function annotationAction(string $kind, string $label, string $color, int $width, int $height): Action
    {
        return Action::make($kind)->label('افزودن '.$label)->modalHeading($label)->modalSubmitActionLabel('اعمال روی نقشه')
            ->form(fn (Form $form) => $form->schema([
                F\TextInput::make('label')->label('عنوان')->required()->maxLength(255),
                F\ColorPicker::make('style.color')->label('رنگ')->hex()->default($color),
                F\TextInput::make('width')->label('عرض')->numeric()->required()->minValue(40)->maxValue(5000)->default($width),
                F\TextInput::make('height')->label('ارتفاع')->numeric()->required()->minValue(24)->maxValue(5000)->default($height),
                F\TextInput::make('style.font_size')->label('اندازه متن')->numeric()->integer()->minValue(10)->maxValue(64)->default($kind === 'text' ? 20 : 14),
                F\TextInput::make('data.rack_units')->label('تعداد یونیت رک')->numeric()->integer()->minValue(6)->maxValue(48)->default(42)->visible($kind === 'rack'),
            ])->columns(2))->mountUsing(function (Form $form, array $arguments) use ($color, $width, $height, $kind) {
                $form->fill(array_replace_recursive([
                    'width' => $width, 'height' => $height, 'style' => ['color' => $color, 'font_size' => $kind === 'text' ? 20 : 14], 'data' => ['rack_units' => 42],
                ], $arguments['node'] ?? []));
            })->action(function (array $data, array $arguments) use ($kind) {
                $this->dispatch('network-upsert-node', networkId: $this->record->id, node: array_replace($arguments['node'] ?? [], $data, ['kind' => $kind, 'type' => $kind]));
            });
    }

    public function edgeAction(): Action
    {
        return Action::make('edge')->label('ارتباط')->modalHeading('مشخصات ارتباط')->modalSubmitActionLabel('اعمال ارتباط')
            ->form(fn (Form $form) => $form->schema([
                F\TextInput::make('label')->label('عنوان ارتباط')->maxLength(255),
                F\Select::make('type')->label('نوع')->options(NetworkCatalog::links())->default('ethernet')->required(),
                F\TextInput::make('data.source_port')->label('پورت مبدا')->maxLength(64),
                F\TextInput::make('data.target_port')->label('پورت مقصد')->maxLength(64),
                F\TextInput::make('data.vlan')->label('VLAN')->numeric()->integer()->minValue(1)->maxValue(4094),
                F\TextInput::make('data.speed')->label('سرعت')->placeholder('1 Gbps')->maxLength(64),
                F\ColorPicker::make('style.color')->label('رنگ')->hex()->default('#64748b'),
                F\Select::make('style.line')->label('نوع خط')->options(['solid' => 'پیوسته', 'dashed' => 'خط‌چین', 'dotted' => 'نقطه‌چین'])->default('solid'),
                F\TextInput::make('style.width')->label('ضخامت')->numeric()->minValue(1)->maxValue(8)->default(2),
                F\Toggle::make('style.arrow')->label('نمایش جهت')->default(false),
                F\Toggle::make('style.animated')->label('حرکت روی ارتباط')->default(false),
            ])->columns(2))->mountUsing(fn (Form $form, array $arguments) => $form->fill(array_replace_recursive([
                'type' => 'ethernet', 'style' => ['color' => '#64748b', 'line' => 'solid', 'width' => 2, 'arrow' => false, 'animated' => false], 'data' => [],
            ], $arguments['edge'] ?? [])))->action(function (array $data, array $arguments) {
                $this->dispatch('network-upsert-edge', networkId: $this->record->id, edge: array_replace($arguments['edge'] ?? [], $data));
            });
    }

    public function historyAction(): Action
    {
        return Action::make('history')->label('تاریخچه')->icon('heroicon-o-clock')
            ->modalSubmitActionLabel('بازگردانی روی بوم')
            ->modalHeading('بازگردانی نسخه ذخیره‌شده')->modalDescription('نسخه انتخاب‌شده روی بوم قرار می‌گیرد. برای نهایی شدن، ذخیره نقشه را بزنید.')
            ->form([F\Select::make('version')->label('نسخه')->options(fn () => $this->record->versions()->latest('revision')->get()
                ->mapWithKeys(fn ($v) => [$v->id => 'نسخه '.$v->revision.' — '.$v->created_at->format('Y-m-d H:i:s')]))->required()])
            ->action(function (array $data) {
                $version = $this->record->versions()->findOrFail($data['version']);
                $graph = $version->graph;
                $graph['revision'] = $this->record->fresh()->revision;
                $this->dispatch('network-restore', networkId: $this->record->id, graph: $graph);
            });
    }
}
