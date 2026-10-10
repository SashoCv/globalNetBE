<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\HasApiTokens;

class ShopClinic extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable;

    protected $fillable = [
        'name', 'slug', 'edb', 'association', 'contact_person',
        'email', 'phone', 'city', 'address',
        'description', 'logo', 'current_status_document',
        'status', 'admin_note', 'reviewed_at',
        'wallet_balance',
        'password', 'email_verified_at',
        'password_reset_token', 'password_reset_expires_at',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'password_reset_token',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password_reset_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
            'wallet_balance' => 'decimal:2',
            'password' => 'hashed',
        ];
    }

    /**
     * Registration rule for the association: one of the configured names,
     * required once the list is filled in.
     *
     * @return array<int, mixed>
     */
    public static function associationRule(): array
    {
        $names = array_values(config('shop.associations'));

        if ($names === []) {
            return ['nullable', 'string', 'max:255'];
        }

        return ['required', 'string', Rule::in($names)];
    }

    protected static function booted(): void
    {
        static::creating(function (ShopClinic $c) {
            if (empty($c->slug)) {
                $c->slug = self::uniqueSlug($c->name);
            }
        });
        static::updating(function (ShopClinic $c) {
            if ($c->isDirty('name') && !$c->isDirty('slug')) {
                $c->slug = self::uniqueSlug($c->name, $c->id);
            }
        });
    }

    private static function uniqueSlug(string $name, ?int $ignore = null): string
    {
        $base = Str::slug($name) ?: 'clinic';
        $slug = $base;
        $i = 2;
        while (self::where('slug', $slug)
            ->when($ignore, fn($q) => $q->where('id', '!=', $ignore))
            ->exists()) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }
}
