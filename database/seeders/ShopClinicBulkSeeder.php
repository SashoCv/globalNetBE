<?php

namespace Database\Seeders;

use App\Models\ShopClinic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds ~1400 fake clinics to stress-test the admin UI at scale.
 *
 *   php artisan db:seed --class=ShopClinicBulkSeeder
 *
 *   # Custom count:
 *   COUNT=2000 php artisan db:seed --class=ShopClinicBulkSeeder
 */
class ShopClinicBulkSeeder extends Seeder
{
    public function run(): void
    {
        $count = max(1, (int) (env('COUNT') ?: 1400));
        $cities = ['Скопје', 'Битола', 'Тетово', 'Куманово', 'Прилеп', 'Велес', 'Охрид', 'Штип', 'Струмица', 'Гевгелија', 'Кочани', 'Кавадарци', 'Кичево', 'Гостивар', 'Дебар'];
        $firsts = ['Анета', 'Никола', 'Маја', 'Сашо', 'Илија', 'Симона', 'Петар', 'Ана', 'Стефан', 'Лидија', 'Дејан', 'Емилија', 'Бојан', 'Кристина', 'Александар', 'Биљана', 'Игор', 'Снежана'];
        $lasts = ['Стојановски', 'Костовски', 'Трајковска', 'Петровски', 'Илиев', 'Тодоровска', 'Стефанов', 'Митровска', 'Николовски', 'Манасиев', 'Чочев', 'Спасов', 'Јовановски'];
        $names = ['Поликлиника', 'Ординација', 'Клиника', 'Амбуланта', 'Дом на здравјето', 'Семејна Амбуланта', 'Стоматолошка'];
        $suffixes = ['Здравје', 'Виталис', 'Витамед', 'Биодент', 'Аркадија', 'Пулс', 'Медика', 'Кардиа', 'Хармонија', 'Бел Камен', 'Соларис', 'Корпус', 'Венус', 'Иванов', 'Стојан'];

        $statuses = ['pending', 'pending', 'pending', 'approved', 'approved', 'approved', 'approved', 'approved', 'rejected'];

        $batch = [];
        $now = now();
        $startIdx = ShopClinic::count();

        for ($i = 0; $i < $count; $i++) {
            $globalI = $startIdx + $i + 1;
            $name = $names[array_rand($names)] . ' ' . $suffixes[array_rand($suffixes)] . ' ' . $globalI;
            $first = $firsts[array_rand($firsts)];
            $last = $lasts[array_rand($lasts)];
            $city = $cities[array_rand($cities)];
            $status = $statuses[array_rand($statuses)];
            $slug = Str::slug($name) . '-' . $globalI;
            $email = 'klinika' . $globalI . '@' . Str::lower(Str::random(6)) . '.mk';

            $batch[] = [
                'name' => $name,
                'slug' => $slug,
                'edb' => 'MK40' . str_pad((string) random_int(1000000000, 9999999999), 11, '0', STR_PAD_LEFT),
                'contact_person' => "Д-р $first $last",
                'email' => $email,
                'phone' => '07' . random_int(0, 9) . ' ' . random_int(100, 999) . ' ' . random_int(100, 999),
                'city' => $city,
                'address' => 'ул. ' . $suffixes[array_rand($suffixes)] . ' ' . random_int(1, 200),
                'description' => null,
                'logo' => null,
                'status' => $status,
                'admin_note' => $status === 'rejected' ? 'Невалидна регистрација.' : null,
                'reviewed_at' => $status !== 'pending' ? $now->copy()->subDays(random_int(0, 60)) : null,
                'created_at' => $now->copy()->subDays(random_int(0, 90))->subMinutes(random_int(0, 1440)),
                'updated_at' => $now,
            ];

            if (count($batch) >= 200) {
                ShopClinic::insert($batch);
                $batch = [];
            }
        }
        if (!empty($batch)) ShopClinic::insert($batch);

        $this->command->info("$count fake clinics seeded.");
    }
}
