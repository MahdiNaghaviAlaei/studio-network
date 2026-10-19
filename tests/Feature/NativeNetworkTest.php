<?php

namespace Tests\Feature;

use App\Filament\Resources\EquipmentResource\Pages\CreateEquipment;
use App\Filament\Resources\EquipmentResource\Pages\EditEquipment;
use App\Filament\Resources\NetworkResource\Pages\NetworkMap;
use App\Models\Equipment;
use App\Models\Network;
use App\Models\NetworkNode;
use App\Models\User;
use App\Services\NetworkGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class NativeNetworkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    private function node(string $label = 'Router', string $kind = 'device'): array
    {
        return ['id' => (string) Str::uuid(), 'kind' => $kind, 'type' => $kind === 'device' ? 'router' : $kind,
            'label' => $label, 'x' => 50, 'y' => 50, 'width' => 172, 'height' => 78,
            'parent' => null, 'data' => ['status' => 'unknown'], 'style' => ['color' => '#38bdf8']];
    }

    private function graph(array $nodes = [], array $edges = [], int $revision = 0): array
    {
        return ['revision' => $revision, 'nodes' => $nodes, 'edges' => $edges,
            'settings' => ['theme' => 'dark', 'grid' => true], 'viewport' => []];
    }

    private function link(array $a, array $b): array
    {
        return ['id' => (string) Str::uuid(), 'source' => $a['id'], 'target' => $b['id'], 'type' => 'ethernet',
            'label' => 'Uplink', 'data' => ['source_port' => 'eth0', 'target_port' => 'Gi0/1', 'vlan' => 20], 'style' => ['line' => 'solid']];
    }

    public function test_each_network_has_its_own_native_graph_without_http_requests(): void
    {
        $a = Network::create(['name' => 'A']);
        $b = Network::create(['name' => 'B']);
        $node = $this->node();
        $service = app(NetworkGraph::class);
        $saved = $service->save($a, $this->graph([$node]));
        $this->assertSame(1, $saved['revision']);
        $this->assertCount(1, $service->load($a)['nodes']);
        $this->assertSame([], $service->load($b)['nodes']);
        $this->assertSame(1, $a->fresh()->node_count);
        $this->assertDatabaseHas('network_nodes', ['id' => $node['id'], 'network_id' => $a->id]);
        Http::assertNothingSent();
    }

    public function test_save_and_clear_keep_connections_positions_ports_and_services(): void
    {
        $network = Network::create(['name' => 'Lab']);
        $a = $this->node();
        $b = $this->node('Server');
        $b['x'] = 400;
        $b['data']['services'] = [['name' => 'HTTPS', 'port' => 443, 'protocol' => 'tcp', 'status' => 'unknown']];
        $edge = $this->link($a, $b);
        $service = app(NetworkGraph::class);
        $result = $service->save($network, $this->graph([$a, $b], [$edge]));
        $this->assertSame(400.0, collect($result['nodes'])->firstWhere('id', $b['id'])['x']);
        $this->assertSame(443, collect($result['nodes'])->firstWhere('id', $b['id'])['data']['services'][0]['port']);
        $this->assertSame('eth0', $result['edges'][0]['data']['source_port']);
        $service->save($network, $this->graph([], [], 1));
        $this->assertSame([], $service->load($network)['nodes']);
        $this->assertDatabaseCount('network_edges', 0);
        $this->assertDatabaseCount('network_versions', 2);
    }

    public function test_a_stale_window_cannot_overwrite_a_newer_save(): void
    {
        $network = Network::create(['name' => 'Lab']);
        $service = app(NetworkGraph::class);
        $service->save($network, $this->graph([$this->node('Saved')]));
        try {
            $service->save($network, $this->graph([$this->node('Stale')]));
            $this->fail('Expected conflict');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('graph', $exception->errors());
        }
        $this->assertSame('Saved', $service->load($network)['nodes'][0]['label']);
        $this->assertDatabaseCount('network_versions', 1);
    }

    public function test_foreign_node_identifiers_cannot_move_between_networks(): void
    {
        $a = Network::create(['name' => 'A']);
        $b = Network::create(['name' => 'B']);
        $node = $this->node();
        $service = app(NetworkGraph::class);
        $service->save($a, $this->graph([$node]));
        try {
            $service->save($b, $this->graph([$node]));
            $this->fail('Expected foreign id rejection');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('graph', $exception->errors());
        }
        $this->assertSame(0, $b->fresh()->revision);
        $this->assertSame($a->id, NetworkNode::find($node['id'])->network_id);
    }

    public function test_cross_network_connection_is_rejected_atomically(): void
    {
        $a = Network::create(['name' => 'A']);
        $b = Network::create(['name' => 'B']);
        $node = $this->node();
        $foreign = $this->node('Other');
        $service = app(NetworkGraph::class);
        $service->save($b, $this->graph([$foreign]));
        try {
            $service->save($a, $this->graph([$node], [$this->link($node, $foreign)]));
            $this->fail('Expected invalid endpoint');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('network_nodes', ['id' => $node['id']]);
        }
        $this->assertSame(0, $a->fresh()->revision);
    }

    public function test_cycles_and_unsafe_style_inputs_are_rejected(): void
    {
        $a = $this->node('A', 'zone');
        $b = $this->node('B', 'zone');
        $a['parent'] = $b['id'];
        $b['parent'] = $a['id'];
        try {
            app(NetworkGraph::class)->validate($this->graph([$a, $b]));
            $this->fail('Expected cycle rejection');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('graph', $exception->errors());
        }
        $a['parent'] = null;
        $a['style']['color'] = 'url(https://outside.test/image)';
        $this->expectException(ValidationException::class);
        app(NetworkGraph::class)->validate($this->graph([$a]));
    }

    public function test_duplicate_preserves_content_but_remaps_all_ids_and_parents(): void
    {
        $network = Network::create(['name' => 'Source']);
        $zone = $this->node('DMZ', 'zone');
        $a = $this->node();
        $b = $this->node('Server');
        $a['parent'] = $zone['id'];
        $service = app(NetworkGraph::class);
        $service->save($network, $this->graph([$zone, $a, $b], [$this->link($a, $b)]));
        $copy = $service->duplicate($network, 'Copy');
        $graph = $service->load($copy);
        $this->assertCount(3, $graph['nodes']);
        $this->assertEmpty(array_intersect(array_column($graph['nodes'], 'id'), [$zone['id'], $a['id'], $b['id']]));
        $this->assertContains($graph['edges'][0]['source'], array_column($graph['nodes'], 'id'));
        $copiedRouter = collect($graph['nodes'])->firstWhere('label', 'Router');
        $this->assertSame(collect($graph['nodes'])->firstWhere('label', 'DMZ')['id'], $copiedRouter['parent']);
        Http::assertNothingSent();
    }

    public function test_overlapping_rack_units_are_rejected(): void
    {
        $rack = $this->node('Rack', 'rack');
        $rack['data'] = ['rack_units' => 12];
        $a = $this->node();
        $b = $this->node('Other');
        $a['parent'] = $b['parent'] = $rack['id'];
        $a['data']['rack_u'] = $b['data']['rack_u'] = 3;
        $this->expectException(ValidationException::class);
        app(NetworkGraph::class)->validate($this->graph([$rack, $a, $b]));
    }

    public function test_history_is_bounded_and_network_deletion_cascades(): void
    {
        $network = Network::create(['name' => 'Lab']);
        $service = app(NetworkGraph::class);
        for ($revision = 0; $revision < 23; $revision++) {
            $service->save($network, $this->graph([], [], $revision));
        }
        $this->assertSame(20, $network->versions()->count());
        $this->assertSame(4, $network->versions()->min('revision'));
        $network->delete();
        $this->assertDatabaseCount('network_versions', 0);
    }

    public function test_the_native_page_has_no_iframe_and_legacy_routes_are_removed(): void
    {
        $network = Network::create(['name' => 'Local']);
        $this->get('/admin/networks/'.$network->id.'/map')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create());
        config(['app.env' => 'local']);
        $this->get('/admin/networks/'.$network->id.'/map')->assertOk()->assertSee('network-editor')->assertDontSee('<iframe', false);
        $this->get('/homelable/')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_livewire_save_uses_native_models_and_returns_conflict_without_losing_data(): void
    {
        $this->actingAs(User::factory()->create());
        config(['app.env' => 'local']);
        $network = Network::create(['name' => 'Native']);
        $node = $this->node();
        $page = Livewire::test(NetworkMap::class, ['record' => $network->id]);
        $page->call('saveMap', $this->graph([$node]));
        $this->assertDatabaseHas('network_nodes', ['id' => $node['id']]);
        $page->call('saveMap', $this->graph());
        $this->assertSame(1, $network->fresh()->revision);
        $this->assertDatabaseHas('network_nodes', ['id' => $node['id']]);
        Http::assertNothingSent();
    }

    public function test_device_modal_is_a_native_filament_form_and_dispatches_to_the_canvas(): void
    {
        $this->actingAs(User::factory()->create());
        config(['app.env' => 'local']);
        $network = Network::create(['name' => 'Native']);
        Livewire::test(NetworkMap::class, ['record' => $network->id])
            ->callAction('device', data: ['label' => 'Native Router', 'type' => 'router', 'data' => ['status' => 'unknown']])
            ->assertHasNoActionErrors()->assertDispatched('network-upsert-node');
        $this->assertDatabaseCount('network_nodes', 0);
    }

    public function test_annotation_and_connection_modals_are_functional_filament_forms(): void
    {
        $this->actingAs(User::factory()->create());
        config(['app.env' => 'local']);
        $network = Network::create(['name' => 'Native']);
        foreach (['zone', 'text', 'rack'] as $kind) {
            Livewire::test(NetworkMap::class, ['record' => $network->id])
                ->callAction($kind, data: ['label' => 'Example'])
                ->assertHasNoActionErrors()->assertDispatched('network-upsert-node');
        }
        Livewire::test(NetworkMap::class, ['record' => $network->id])
            ->callAction('edge', data: ['label' => 'Uplink', 'type' => 'ethernet'], arguments: ['edge' => ['source' => (string) Str::uuid(), 'target' => (string) Str::uuid()]])
            ->assertHasNoActionErrors()->assertDispatched('network-upsert-edge');
    }

    public function test_inventory_edits_preserve_services_and_invalidate_an_open_map(): void
    {
        $this->actingAs(User::factory()->create());
        config(['app.env' => 'local']);
        $network = Network::create(['name' => 'Inventory']);
        $node = $this->node('Before');
        $node['data']['services'] = [['name' => 'HTTPS', 'port' => 443, 'protocol' => 'tcp']];
        app(NetworkGraph::class)->save($network, $this->graph([$node]));
        Livewire::test(EditEquipment::class, ['record' => $node['id']])
            ->set('data.label', 'After')->call('save')->assertHasNoFormErrors();
        $record = NetworkNode::findOrFail($node['id']);
        $this->assertSame('After', $record->label);
        $this->assertSame(443, $record->data['services'][0]['port']);
        $this->assertSame(2, $network->fresh()->revision);
        Http::assertNothingSent();
    }

    public function test_inventory_can_be_created_before_it_has_a_map_and_selected_by_native_action(): void
    {
        $this->actingAs(User::factory()->create());
        config(['app.env' => 'local']);
        Livewire::test(CreateEquipment::class)
            ->fillForm(['label' => 'mehdi', 'type' => 'server', 'data' => ['ip' => '192.0.2.25', 'status' => 'unknown']])
            ->call('create')->assertHasNoFormErrors();
        $equipment = Equipment::where('label', 'mehdi')->sole();
        $network = Network::create(['name' => 'New map']);
        Livewire::test(NetworkMap::class, ['record' => $network->id])
            ->callAction('existingEquipment', data: ['equipment_id' => $equipment->id])
            ->assertHasNoActionErrors()
            ->assertDispatched('network-upsert-node', fn ($event, $params) => $params['node']['equipment_id'] === $equipment->id
                && $params['node']['label'] === 'mehdi' && $params['node']['data']['ip'] === '192.0.2.25');
        $this->assertDatabaseCount('equipment', 1);
        $this->assertDatabaseCount('network_nodes', 0); // Apply to canvas, persist only on Save.
        Http::assertNothingSent();
    }

    public function test_one_inventory_asset_can_appear_in_independent_maps_and_survive_removal(): void
    {
        $equipment = Equipment::create(['label' => 'mehdi', 'type' => 'server', 'data' => ['status' => 'online']]);
        $a = Network::create(['name' => 'A']);
        $b = Network::create(['name' => 'B']);
        $node = array_replace($this->node('mehdi'), ['type' => 'server', 'equipment_id' => $equipment->id, 'equipment_revision' => 0, 'data' => $equipment->data]);
        $other = array_replace($node, ['id' => (string) Str::uuid(), 'x' => 500]);
        $service = app(NetworkGraph::class);
        $service->save($a, $this->graph([$node]));
        $service->save($b, $this->graph([$other]));
        $this->assertDatabaseCount('equipment', 1);
        $this->assertSame(500.0, $service->load($b)['nodes'][0]['x']);
        $service->save($a, $this->graph([], [], 1));
        $this->assertDatabaseHas('equipment', ['id' => $equipment->id]);
        $this->assertCount(1, $service->load($b)['nodes']);
        $b->delete();
        $this->assertDatabaseHas('equipment', ['id' => $equipment->id]);
    }

    public function test_duplicate_asset_placement_is_rejected_without_partial_changes(): void
    {
        $equipment = Equipment::create(['label' => 'mehdi', 'type' => 'server', 'data' => []]);
        $a = array_replace($this->node('mehdi'), ['equipment_id' => $equipment->id, 'equipment_revision' => 0]);
        $b = array_replace($a, ['id' => (string) Str::uuid()]);
        $network = Network::create(['name' => 'A']);
        try {
            app(NetworkGraph::class)->save($network, $this->graph([$a, $b]));
            $this->fail('Expected duplicate equipment rejection');
        } catch (ValidationException) {
            $this->assertDatabaseCount('network_nodes', 0);
            $this->assertSame(0, $network->fresh()->revision);
        }
    }

    public function test_shared_equipment_edits_update_all_maps_without_moving_their_placements(): void
    {
        $equipment = Equipment::create(['label' => 'Before', 'type' => 'server', 'data' => ['status' => 'unknown']]);
        $a = Network::create(['name' => 'A']);
        $b = Network::create(['name' => 'B']);
        $node = array_replace($this->node('Before'), ['type' => 'server', 'equipment_id' => $equipment->id, 'equipment_revision' => 0,
            'data' => ['status' => 'unknown', 'rack_u' => 3, 'height_u' => 2]]);
        $other = array_replace($node, ['id' => (string) Str::uuid(), 'x' => 600, 'data' => ['status' => 'unknown', 'rack_u' => 7, 'height_u' => 1]]);
        $service = app(NetworkGraph::class);
        $saved = $service->save($a, $this->graph([$node]));
        $stale = $service->save($b, $this->graph([$other]));
        $saved['nodes'][0]['label'] = 'After';
        $service->save($a, $saved);
        $updated = $service->load($b);
        $this->assertSame('After', $updated['nodes'][0]['label']);
        $this->assertSame(600.0, $updated['nodes'][0]['x']);
        $this->assertSame(7, $updated['nodes'][0]['data']['rack_u']);
        $this->assertSame(2, $updated['revision']);
        $this->assertDatabaseCount('equipment', 1);
        $this->expectException(ValidationException::class);
        $service->save($b, $stale);
    }

    public function test_equipment_changed_after_selection_cannot_be_overwritten_by_attaching_it(): void
    {
        $equipment = Equipment::create(['label' => 'Before', 'type' => 'server', 'data' => []]);
        $node = array_replace($this->node('Before'), ['equipment_id' => $equipment->id, 'equipment_revision' => 0]);
        $equipment->update(['label' => 'After']);
        $network = Network::create(['name' => 'Map']);
        try {
            app(NetworkGraph::class)->save($network, $this->graph([$node]));
            $this->fail('Expected equipment revision conflict');
        } catch (ValidationException) {
            $this->assertDatabaseCount('network_nodes', 0);
            $this->assertSame('After', $equipment->fresh()->label);
            $this->assertSame(0, $network->fresh()->revision);
        }
    }
}
