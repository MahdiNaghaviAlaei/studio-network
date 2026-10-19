<?php

namespace Database\Seeders;

use App\Models\Network;
use App\Services\NetworkGraph;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NetworkDemoSeeder extends Seeder
{
    public const NETWORK_ID = '6a68fca0-9a42-4f0f-a158-32cc71f53216';

    public function run(): void
    {
        DB::transaction(function () {
            // Re-running setup must not replace an edited demo or duplicate its inventory.
            if (Network::whereKey(self::NETWORK_ID)->exists()) {
                $this->command?->info('Demo already exists; keeping its current contents.');

                return;
            }
            $fixture = json_decode(file_get_contents(database_path('data/rack-network.json')), true, flags: JSON_THROW_ON_ERROR);
            $graph = $fixture['graph'];
            $ids = [];
            foreach ($graph['nodes'] as $node) {
                $ids[$node['id']] = (string) Str::uuid();
            }
            foreach ($graph['nodes'] as &$node) {
                $node['id'] = $ids[$node['id']];
                $node['parent'] = empty($node['parent']) ? null : $ids[$node['parent']];
                unset($node['equipment_id'], $node['equipment_revision']);
            }
            unset($node);
            foreach ($graph['edges'] as &$edge) {
                $edge['id'] = (string) Str::uuid();
                $edge['source'] = $ids[$edge['source']];
                $edge['target'] = $ids[$edge['target']];
            }
            unset($edge);
            $network = new Network(['name' => $fixture['name'], 'description' => $fixture['description']]);
            $network->id = self::NETWORK_ID;
            $network->save();
            app(NetworkGraph::class)->save($network, $graph);
            $this->command?->info('Demo ready: /admin/networks/'.self::NETWORK_ID.'/map');
        });
    }
}
