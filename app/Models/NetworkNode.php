<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkNode extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'style' => 'array', 'x' => 'float', 'y' => 'float', 'width' => 'float', 'height' => 'float'];
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
