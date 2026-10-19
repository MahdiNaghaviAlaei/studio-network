<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['graph' => 'array'];
    }
}
