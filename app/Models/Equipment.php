<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

class Equipment extends Model
{
    use HasUuids;

    protected $table = 'equipment';

    protected $fillable = ['label', 'type', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array', 'revision' => 'integer'];
    }

    public static function sharedData(array $data): array
    {
        return Arr::except($data, ['rack_u', 'height_u']);
    }

    protected static function booted(): void
    {
        static::updating(function (Equipment $equipment) {
            if ($equipment->isDirty(['label', 'type', 'data'])) {
                $equipment->revision++;
            }
        });
        static::updated(function (Equipment $equipment) {
            if (! $equipment->wasChanged(['label', 'type', 'data'])) {
                return;
            }
            // Synchronize shared facts; rack position and appearance belong to each map.
            foreach ($equipment->nodes()->get() as $node) {
                $node->update([
                    'label' => $equipment->label, 'type' => $equipment->type,
                    'data' => array_replace($equipment->data ?? [], Arr::only($node->data ?? [], ['rack_u', 'height_u'])),
                ]);
            }
            Network::whereIn('id', $equipment->nodes()->select('network_id'))->increment('revision');
        });
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(NetworkNode::class);
    }

    public function networks(): BelongsToMany
    {
        return $this->belongsToMany(Network::class, 'network_nodes', 'equipment_id', 'network_id');
    }
}
