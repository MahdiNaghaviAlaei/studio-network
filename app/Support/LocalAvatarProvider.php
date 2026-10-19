<?php

namespace App\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

class LocalAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $initial = htmlspecialchars(mb_substr($record->name ?? 'A', 0, 1), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80" rx="40" fill="#0f172a"/><text x="40" y="48" text-anchor="middle" fill="#67e8f9" font-family="sans-serif" font-size="30">'.$initial.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
