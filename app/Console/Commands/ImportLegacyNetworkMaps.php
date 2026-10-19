<?php

namespace App\Console\Commands;

use App\Models\Network;
use App\Services\NetworkGraph;
use App\Support\NetworkCatalog;
use Illuminate\Console\Command;
use PDO;

class ImportLegacyNetworkMaps extends Command
{
    protected $signature = 'network:import-legacy';

    protected $description = 'Import the previous local SQLite diagrams once, without contacting another service';

    public function handle(NetworkGraph $graphs): int
    {
        $path = storage_path('app/homelable-data/homelable.db');
        if (! is_file($path)) {
            $this->info('No legacy database found.');

            return self::SUCCESS;
        }
        $db = new PDO('sqlite:'.$path);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA query_only=ON');
        $devices = [];
        foreach ($db->query('SELECT * FROM device_inventory') as $device) {
            $devices[$device['id']] = $device;
        }
        $decode = fn ($value) => is_string($value) ? (json_decode($value, true) ?: []) : [];
        $imported = 0;
        foreach ($db->query('SELECT * FROM designs') as $design) {
            $network = Network::firstOrCreate(['id' => $design['id']], ['name' => $design['name'], 'design_type' => $design['design_type']]);
            if ($network->revision > 0) {
                continue;
            }
            $nodes = [];
            $query = $db->prepare('SELECT * FROM nodes WHERE design_id = ?');
            $query->execute([$design['id']]);
            foreach ($query as $row) {
                $device = $devices[$row['device_id'] ?? ''] ?? [];
                $oldType = $device['type'] ?? $row['type'];
                $kind = match ($oldType) {
                    'groupRect', 'group' => 'zone', 'text' => 'text', default => 'device'
                };
                $type = $kind === 'device' ? (array_key_exists($oldType, NetworkCatalog::devices()) ? $oldType : 'generic') : $kind;
                $colors = $decode($row['custom_colors'] ?? '');
                $color = $colors['border'] ?? '#38bdf8';
                if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                    $color = '#38bdf8';
                }
                $services = [];
                foreach ($decode($device['services'] ?? '') as $service) {
                    $services[] = ['name' => (string) ($service['service_name'] ?? 'Service'), 'port' => $service['port'] ?? null, 'protocol' => $service['protocol'] ?? 'tcp', 'status' => 'unknown'];
                }
                $properties = [];
                foreach ($decode($device['properties'] ?? '') as $property) {
                    $properties[] = ['key' => (string) ($property['key'] ?? 'property'), 'value' => (string) ($property['value'] ?? '')];
                }
                $nodes[] = [
                    'id' => $row['id'], 'kind' => $kind, 'type' => $type, 'label' => $device['label'] ?? $row['label'],
                    'x' => (float) $row['pos_x'] + 100, 'y' => (float) $row['pos_y'] + 50,
                    'width' => max(40, (float) ($row['width'] ?: ($kind === 'zone' ? 400 : 172))),
                    'height' => max(24, (float) ($row['height'] ?: ($kind === 'zone' ? 300 : 78))),
                    'parent' => $row['parent_id'],
                    'style' => ['color' => $color],
                    'data' => $kind === 'device' ? [
                        'ip' => $device['ip'] ?? '', 'hostname' => $device['hostname'] ?? '', 'mac' => $device['mac'] ?? '',
                        'os' => $device['os'] ?? '', 'status' => 'unknown', 'notes' => $device['notes'] ?? '',
                        'cpu' => (string) ($device['cpu_count'] ?? ''), 'ram' => (string) ($device['ram_gb'] ?? ''),
                        'disk' => (string) ($device['disk_gb'] ?? ''), 'services' => $services, 'properties' => $properties,
                    ] : [],
                ];
            }
            $ids = array_column($nodes, 'id');
            foreach ($nodes as &$node) {
                if (! in_array($node['parent'], $ids, true)) {
                    $node['parent'] = null;
                }
            }
            unset($node);
            $query = $db->prepare('SELECT * FROM edges WHERE design_id = ?');
            $query->execute([$design['id']]);
            $edges = [];
            foreach ($query as $edge) {
                if (! in_array($edge['source'], $ids, true) || ! in_array($edge['target'], $ids, true)) {
                    continue;
                }
                $edges[] = ['id' => $edge['id'], 'source' => $edge['source'], 'target' => $edge['target'],
                    'label' => $edge['label'] ?? '', 'type' => array_key_exists($edge['type'], NetworkCatalog::links()) ? $edge['type'] : 'ethernet',
                    'data' => ['speed' => $edge['speed'] ?? '', 'vlan' => $edge['vlan_id'] ?? null],
                    'style' => ['line' => 'solid', 'width' => 2, 'animated' => false, 'arrow' => false],
                ];
            }
            $graphs->save($network, ['revision' => 0, 'nodes' => $nodes, 'edges' => $edges,
                'settings' => ['theme' => 'dark', 'grid' => true, 'snap' => false, 'minimap' => true], 'viewport' => []]);
            $imported++;
        }
        $this->info("Imported {$imported} local maps. Existing native maps were preserved.");

        return self::SUCCESS;
    }
}
