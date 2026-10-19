<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Network;
use App\Models\User;
use App\Services\NetworkGraph;
use Database\Seeders\NetworkDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_install_recreates_the_two_racks_with_port_connections_without_a_shared_password(): void
    {
        $this->seed(NetworkDemoSeeder::class);
        $network = Network::findOrFail(NetworkDemoSeeder::NETWORK_ID);
        $graph = app(NetworkGraph::class)->load($network);
        $this->assertCount(11, $graph['nodes']);
        $this->assertSame(2, collect($graph['nodes'])->where('kind', 'rack')->count());
        $this->assertSame(6, Equipment::count());
        $this->assertSame(0, User::count());
        $this->assertCount(5, $graph['edges']);
        $ports = [];
        foreach ($graph['edges'] as $edge) {
            foreach (['source', 'target'] as $end) {
                $port = $edge[$end].':'.$edge['data'][$end.'_port'];
                $this->assertNotContains($port, $ports);
                $ports[] = $port;
            }
        }
        $devices = collect($graph['nodes'])->where('kind', 'device');
        $this->assertCount(5, $devices->whereNotNull('parent'));
        $server = $devices->firstWhere('type', 'server');
        $this->assertSame(3, $server['data']['rack_u']);
        $this->assertSame(2, $server['data']['height_u']);
        $this->assertSame(['unknown'], $devices->pluck('data.status')->unique()->values()->all());
    }

    public function test_rerunning_setup_keeps_demo_edits_and_does_not_duplicate_inventory(): void
    {
        $this->seed(NetworkDemoSeeder::class);
        $network = Network::findOrFail(NetworkDemoSeeder::NETWORK_ID);
        $graph = app(NetworkGraph::class)->load($network);
        $graph['nodes'][0]['label'] = 'Edited locally';
        $saved = app(NetworkGraph::class)->save($network, $graph);
        $this->seed(NetworkDemoSeeder::class);
        $this->assertSame($saved, app(NetworkGraph::class)->load($network));
        $this->assertDatabaseCount('networks', 1);
        $this->assertDatabaseCount('equipment', 6);
        $this->assertDatabaseCount('network_nodes', 11);
    }
}
