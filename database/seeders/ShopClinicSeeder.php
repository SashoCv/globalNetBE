<?php

namespace Database\Seeders;

use App\Models\ShopClinic;
use Illuminate\Database\Seeder;

class ShopClinicSeeder extends Seeder
{
    public function run(): void
    {
        $clinics = [
            [
                'name' => 'Поликлиника Аркадија',
                'edb' => 'MK4080012501234',
                'contact_person' => 'Д-р Анета Стојановска',
                'email' => 'office@arkadija.mk',
                'phone' => '02 311 4500',
                'city' => 'Скопје',
                'address' => 'бул. Партизански Одреди 76',
                'description' => 'Семејна поликлиника со 12 ординации.',
                'status' => 'approved',
                'reviewed_at' => now()->subDays(30),
            ],
            [
                'name' => 'Ординација Виталис',
                'edb' => 'MK4030010234567',
                'contact_person' => 'Д-р Никола Костовски',
                'email' => 'kontakt@vitalis-mk.com',
                'phone' => '02 233 8800',
                'city' => 'Скопје',
                'address' => 'ул. Кеј 13 Ноември бр. 14',
                'description' => 'Општа медицина и педијатрија.',
                'status' => 'approved',
                'reviewed_at' => now()->subDays(12),
            ],
            [
                'name' => 'Стоматолошка Биодент',
                'edb' => 'MK4080015678901',
                'contact_person' => 'Д-р Маја Трајковска',
                'email' => 'info@biodent.mk',
                'phone' => '078 200 100',
                'city' => 'Битола',
                'address' => 'ул. Климент Охридски 22',
                'description' => 'Стоматолошка ординација со 3 столчиња.',
                'status' => 'pending',
            ],
            [
                'name' => 'Клиника Здраво Дете',
                'edb' => 'MK4080020112233',
                'contact_person' => 'Д-р Сашо Петров',
                'email' => 'kontakt@zdravodete.mk',
                'phone' => '070 555 777',
                'city' => 'Тетово',
                'address' => 'ул. Маршал Тито 45',
                'description' => 'Педијатриска амбуланта за деца од 0 до 18 год.',
                'status' => 'pending',
            ],
            [
                'name' => 'Семејна Амбуланта Бел Камен',
                'email' => 'belkamen@example.mk',
                'phone' => '071 800 500',
                'city' => 'Куманово',
                'description' => 'Барањето е одбиено бидејќи лиценцата не е валидна.',
                'status' => 'rejected',
                'admin_note' => 'Невалидна регистрација во АЈАВ — проверете и обновете.',
                'reviewed_at' => now()->subDays(5),
            ],
        ];

        foreach ($clinics as $c) {
            ShopClinic::updateOrCreate(
                ['email' => $c['email']],
                $c
            );
        }
        $this->command->info(count($clinics) . ' clinics seeded.');
    }
}
