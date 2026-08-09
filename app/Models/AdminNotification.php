<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'type', 'title', 'body', 'data', 'link', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public static function notify(string $type, string $title, ?string $body = null, array $data = [], ?string $link = null): self
    {
        return self::create([
            'type'  => $type,
            'title' => $title,
            'body'  => $body,
            'data'  => $data,
            'link'  => $link,
        ]);
    }
}
