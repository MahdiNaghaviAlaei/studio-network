<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\Network;
use App\Models\NetworkEdge;
use App\Models\NetworkNode;
use App\Support\NetworkCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NetworkGraph
{
    public function load(Network $network): array
    {
        $network->refresh();

        return [
            'format' => 'filament-network', 'version' => 1, 'network_id' => $network->id,
            'name' => $network->name, 'revision' => $network->revision,
            'nodes' => $network->nodes()->with('equipment')->orderBy('created_at')->get()->map(fn ($node) => [
                'id' => $node->id, 'kind' => $node->kind, 'type' => $node->type, 'label' => $node->label,
                'equipment_id' => $node->equipment_id, 'equipment_revision' => $node->equipment?->revision,
                'x' => $node->x, 'y' => $node->y, 'width' => $node->width, 'height' => $node->height,
                'parent' => $node->parent_id, 'data' => $node->data ?? [], 'style' => $node->style ?? [],
            ])->all(),
            'edges' => $network->edges()->get()->map(fn ($edge) => [
                'id' => $edge->id, 'source' => $edge->source, 'target' => $edge->target,
                'type' => $edge->type, 'label' => $edge->label ?? '', 'data' => $edge->data ?? [], 'style' => $edge->style ?? [],
            ])->all(),
            'settings' => $network->settings ?? ['theme' => 'dark', 'grid' => true, 'snap' => false],
            'viewport' => $network->viewport ?? [],
        ];
    }

    public function save(Network $network, array $graph, ?int $userId = null): array
    {
        $graph = $this->validate($graph);

        return DB::transaction(function () use ($network, $graph, $userId) {
            $locked = Network::whereKey($network->id)->lockForUpdate()->firstOrFail();
            if ($locked->revision !== $graph['revision']) {
                throw ValidationException::withMessages(['graph' => 'این نقشه در پنجره دیگری تغییر کرده است. ابتدا از تغییرات خود خروجی بگیرید و سپس نسخه سرور را باز کنید.']);
            }
            $nodeIds = array_column($graph['nodes'], 'id');
            $edgeIds = array_column($graph['edges'], 'id');
            if (NetworkNode::whereIn('id', $nodeIds)->where('network_id', '!=', $locked->id)->exists()
                || NetworkEdge::whereIn('id', $edgeIds)->where('network_id', '!=', $locked->id)->exists()) {
                throw ValidationException::withMessages(['graph' => 'شناسه‌های این نقشه متعلق به شبکه دیگری هستند. فایل را از ابزار ورود نقشه وارد کنید.']);
            }
            $locked->edges()->whereNotIn('id', $edgeIds)->delete();
            $locked->nodes()->whereNotIn('id', $nodeIds)->delete();
            foreach ($graph['nodes'] as $node) {
                $existing = $locked->nodes()->find($node['id']);
                $equipmentId = $node['equipment_id'] ?? $existing?->equipment_id;
                if ($existing?->equipment_id && $equipmentId !== $existing->equipment_id) {
                    throw ValidationException::withMessages(['graph' => 'تجهیز مرتبط با یک جزء نقشه قابل تعویض نیست. آن را از بوم حذف و تجهیز دیگری اضافه کنید.']);
                }
                if ($node['kind'] === 'device') {
                    if ($equipmentId) {
                        $equipment = Equipment::whereKey($equipmentId)->lockForUpdate()->first();
                        if (! $equipment || $equipment->revision !== ($node['equipment_revision'] ?? 0)) {
                            throw ValidationException::withMessages(['graph' => 'مشخصات یکی از تجهیزات تغییر کرده یا دیگر موجود نیست. نسخه سرور را باز کنید یا تجهیز را دوباره از فهرست انتخاب کنید.']);
                        }
                        $equipment->update(['label' => $node['label'], 'type' => $node['type'], 'data' => Equipment::sharedData($node['data'])]);
                    } else {
                        $equipment = new Equipment(['label' => $node['label'], 'type' => $node['type'], 'data' => Equipment::sharedData($node['data'])]);
                        // Preserve the inventory URL used by newly created canvas devices.
                        $equipment->id = Equipment::whereKey($node['id'])->exists() ? (string) Str::uuid() : $node['id'];
                        $equipment->save();
                    }
                    $node['equipment_id'] = $equipment->id;
                } else {
                    $node['equipment_id'] = null;
                }
                unset($node['equipment_revision']);
                $parent = $node['parent'] ?? null;
                unset($node['parent']);
                $locked->nodes()->updateOrCreate(['id' => $node['id']], [...$node, 'parent_id' => $parent]);
            }
            foreach ($graph['edges'] as $edge) {
                $locked->edges()->updateOrCreate(['id' => $edge['id']], $edge);
            }
            $locked->forceFill([
                'revision' => $locked->revision + 1, 'settings' => $graph['settings'] ?? [], 'viewport' => $graph['viewport'] ?? [],
                'node_count' => collect($graph['nodes'])->where('kind', 'device')->count(),
                'group_count' => collect($graph['nodes'])->whereIn('kind', ['zone', 'rack'])->count(),
            ])->save();
            $saved = $this->load($locked);
            $locked->versions()->create(['revision' => $locked->revision, 'user_id' => $userId, 'graph' => $saved]);
            $keep = $locked->versions()->latest('revision')->limit(20)->pluck('id');
            $locked->versions()->whereNotIn('id', $keep)->delete();

            return $saved;
        });
    }

    public function duplicate(Network $network, string $name): Network
    {
        return DB::transaction(function () use ($network, $name) {
            $copy = Network::create(['name' => $name, 'description' => $network->description, 'design_type' => $network->design_type]);
            $graph = $this->load($network);
            $ids = [];
            foreach ($graph['nodes'] as $node) {
                $ids[$node['id']] = (string) Str::uuid();
            }
            foreach ($graph['nodes'] as &$node) {
                $node['id'] = $ids[$node['id']];
                $node['parent'] = $node['parent'] ? $ids[$node['parent']] : null;
            }
            unset($node);
            foreach ($graph['edges'] as &$edge) {
                $edge['id'] = (string) Str::uuid();
                $edge['source'] = $ids[$edge['source']];
                $edge['target'] = $ids[$edge['target']];
            }
            unset($edge);
            $graph['revision'] = 0;
            $this->save($copy, $graph, auth()->id());

            return $copy;
        });
    }

    public function validate(array $graph): array
    {
        if (strlen(json_encode($graph)) > 3_000_000) {
            throw ValidationException::withMessages(['graph' => 'حجم نقشه بیشتر از حد مجاز است.']);
        }
        $color = ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $graph = Validator::make($graph, [
            'revision' => 'required|integer|min:0', 'nodes' => 'present|array|max:1000', 'edges' => 'present|array|max:3000',
            'nodes.*' => 'array:id,kind,type,label,x,y,width,height,parent,data,style,equipment_id,equipment_revision',
            'nodes.*.id' => 'required|uuid|distinct',
            'nodes.*.equipment_id' => 'nullable|uuid|distinct',
            'nodes.*.equipment_revision' => 'nullable|integer|min:0',
            'nodes.*.kind' => ['required', Rule::in(['device', 'zone', 'text', 'rack'])],
            'nodes.*.type' => ['required', Rule::in([...array_keys(NetworkCatalog::devices()), 'zone', 'text', 'rack'])],
            'nodes.*.label' => 'required|string|max:255',
            'nodes.*.x' => 'required|numeric|between:-100000,100000', 'nodes.*.y' => 'required|numeric|between:-100000,100000',
            'nodes.*.width' => 'required|numeric|between:40,5000', 'nodes.*.height' => 'required|numeric|between:24,5000',
            'nodes.*.parent' => 'nullable|uuid',
            'nodes.*.data' => 'present|array:ip,hostname,mac,os,status,notes,services,cpu,ram,disk,properties,tags,rack_units,rack_u,height_u,ports',
            'nodes.*.data.ip' => 'nullable|string|max:255', 'nodes.*.data.hostname' => 'nullable|string|max:255',
            'nodes.*.data.mac' => 'nullable|string|max:64', 'nodes.*.data.os' => 'nullable|string|max:255',
            'nodes.*.data.status' => ['nullable', Rule::in(array_keys(NetworkCatalog::statuses()))],
            'nodes.*.data.notes' => 'nullable|string|max:10000',
            'nodes.*.data.cpu' => 'nullable|string|max:100', 'nodes.*.data.ram' => 'nullable|string|max:100', 'nodes.*.data.disk' => 'nullable|string|max:100',
            'nodes.*.data.services' => 'nullable|array|max:64', 'nodes.*.data.services.*' => 'array:name,port,protocol,status',
            'nodes.*.data.services.*.name' => 'required|string|max:100', 'nodes.*.data.services.*.port' => 'nullable|integer|between:1,65535',
            'nodes.*.data.services.*.protocol' => ['nullable', Rule::in(['tcp', 'udp'])],
            'nodes.*.data.services.*.status' => ['nullable', Rule::in(array_keys(NetworkCatalog::statuses()))],
            'nodes.*.data.properties' => 'nullable|array|max:64', 'nodes.*.data.properties.*' => 'array:key,value',
            'nodes.*.data.properties.*.key' => 'required|string|max:100', 'nodes.*.data.properties.*.value' => 'nullable|string|max:1000',
            'nodes.*.data.tags' => 'nullable|array|max:32', 'nodes.*.data.tags.*' => 'string|max:64',
            'nodes.*.data.rack_units' => 'nullable|integer|between:6,48',
            'nodes.*.data.rack_u' => 'nullable|integer|between:1,48', 'nodes.*.data.height_u' => 'nullable|integer|between:1,48',
            'nodes.*.data.ports' => 'nullable|integer|between:0,64',
            'nodes.*.style' => 'present|array:color,font_size,collapsed',
            'nodes.*.style.color' => $color, 'nodes.*.style.font_size' => 'nullable|integer|between:10,64', 'nodes.*.style.collapsed' => 'sometimes|boolean',
            'edges.*' => 'array:id,source,target,type,label,data,style',
            'edges.*.id' => 'required|uuid|distinct', 'edges.*.source' => 'required|uuid', 'edges.*.target' => 'required|uuid',
            'edges.*.type' => ['required', Rule::in(array_keys(NetworkCatalog::links()))], 'edges.*.label' => 'nullable|string|max:255',
            'edges.*.data' => 'present|array:vlan,speed,source_port,target_port',
            'edges.*.data.vlan' => 'nullable|integer|between:1,4094', 'edges.*.data.speed' => 'nullable|string|max:64',
            'edges.*.data.source_port' => 'nullable|string|max:64', 'edges.*.data.target_port' => 'nullable|string|max:64',
            'edges.*.style' => 'present|array:color,line,width,animated,arrow', 'edges.*.style.color' => $color,
            'edges.*.style.line' => ['nullable', Rule::in(['solid', 'dashed', 'dotted'])],
            'edges.*.style.width' => 'nullable|numeric|between:1,8', 'edges.*.style.animated' => 'sometimes|boolean', 'edges.*.style.arrow' => 'sometimes|boolean',
            'settings' => 'sometimes|array:theme,grid,snap,minimap', 'settings.theme' => ['sometimes', Rule::in(['dark', 'light', 'neon', 'matrix'])],
            'settings.grid' => 'sometimes|boolean', 'settings.snap' => 'sometimes|boolean', 'settings.minimap' => 'sometimes|boolean',
            'viewport' => 'sometimes|array:zoom,pan', 'viewport.zoom' => 'sometimes|numeric|between:0.05,5',
            'viewport.pan' => 'sometimes|array:x,y', 'viewport.pan.x' => 'required_with:viewport.pan|numeric|between:-100000,100000',
            'viewport.pan.y' => 'required_with:viewport.pan|numeric|between:-100000,100000',
        ])->validate();
        $nodes = collect($graph['nodes'])->keyBy('id');
        foreach ($graph['nodes'] as $node) {
            if (! empty($node['equipment_id']) && $node['kind'] !== 'device') {
                throw ValidationException::withMessages(['graph' => 'فقط تجهیز می‌تواند به فهرست تجهیزات متصل باشد.']);
            }
            $seen = [$node['id']];
            $parentId = $node['parent'] ?? null;
            while ($parentId) {
                $parent = $nodes->get($parentId);
                if (! $parent || in_array($parentId, $seen, true) || $parent['kind'] === 'text') {
                    throw ValidationException::withMessages(['graph' => 'گروه‌بندی نامعتبر است: والد باید در همین نقشه باشد و چرخه ایجاد نکند.']);
                }
                $seen[] = $parentId;
                $parentId = $parent['parent'] ?? null;
            }
        }
        foreach ($graph['edges'] as $edge) {
            if (! $nodes->has($edge['source']) || ! $nodes->has($edge['target']) || $edge['source'] === $edge['target']) {
                throw ValidationException::withMessages(['graph' => 'دو سر هر ارتباط باید دو تجهیز متفاوت در همین نقشه باشند.']);
            }
            if ($nodes[$edge['source']]['kind'] !== 'device' || $nodes[$edge['target']]['kind'] !== 'device') {
                throw ValidationException::withMessages(['graph' => 'ارتباط فقط بین تجهیزات قابل ساخت است.']);
            }
        }
        foreach ($nodes->where('kind', 'rack') as $rack) {
            $occupied = [];
            foreach ($nodes->where('parent', $rack['id']) as $child) {
                $start = $child['data']['rack_u'] ?? 1;
                $height = $child['data']['height_u'] ?? 1;
                if ($start + $height - 1 > ($rack['data']['rack_units'] ?? 42)) {
                    throw ValidationException::withMessages(['graph' => 'تجهیز از محدوده یونیت‌های رک خارج شده است.']);
                }
                for ($u = $start; $u < $start + $height; $u++) {
                    if (isset($occupied[$u])) {
                        throw ValidationException::withMessages(['graph' => 'دو تجهیز در یک یونیت رک قرار گرفته‌اند.']);
                    }
                    $occupied[$u] = true;
                }
            }
        }

        return $graph;
    }
}
