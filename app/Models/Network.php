<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Network extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'description', 'design_type', 'settings', 'viewport'];

    protected $attributes = ['design_type' => 'network', 'revision' => 0];

    protected function casts(): array
    {
        return ['settings' => 'array', 'viewport' => 'array', 'revision' => 'integer'];
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(NetworkNode::class);
    }

    public function edges(): HasMany
    {
        return $this->hasMany(NetworkEdge::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(NetworkVersion::class);
    }
}
