<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates/updates the eshop-only admin accounts (role = 'eshop'), scoped to
 * the E-Shop platform in the admin panel — see RestrictEshopRole middleware
 * and the isEshopOnly gating in frontend-marketing's AdminLayout.
 *
 * Idempotent — safe to re-run, sets the password/role to the values below
 * every time (matches the DatabaseSeeder admin@globalnetadv.mk convention).
 *
 * Run on demand: php artisan db:seed --class=EshopAdminUsersSeeder
 */
class EshopAdminUsersSeeder extends Seeder
{
    private const ACCOUNTS = [
        ['name' => 'Elena', 'email' => 'elena@gnaeshop.mk', 'password' => 'a9015e693943'],
        ['name' => 'Person', 'email' => 'person@gnaeshop.mk', 'password' => '5fcaf7cf93e3'],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                ['name' => $account['name'], 'password' => $account['password'], 'role' => 'eshop']
            );

            $this->command?->info("Seeded {$account['email']}");
        }
    }
}
