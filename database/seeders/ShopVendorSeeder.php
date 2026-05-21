<?php

namespace Database\Seeders;

use App\Models\ShopVendor;
use Illuminate\Database\Seeder;

class ShopVendorSeeder extends Seeder
{
    public function run(): void
    {
        $vendors = [
            [
                'name' => 'Алкалоид АД Скопје',
                'contact_person' => 'Марина Стојановска',
                'email' => 'sales@alkaloid.com.mk',
                'phone' => '02 311 0500',
                'city' => 'Скопје',
                'address' => 'бул. Александар Македонски 12',
                'description' => 'Водечка фармацевтска компанија во регионот, со широка палета на лекови, козметика и хемикалии.',
                'logo' => 'https://logo.clearbit.com/alkaloid.com.mk',
                'website' => 'https://www.alkaloid.com.mk',
                'status' => 'active',
                'products_count' => 142,
            ],
            [
                'name' => 'Реплек Фарм',
                'contact_person' => 'Бојан Стефановски',
                'email' => 'info@replekfarm.com.mk',
                'phone' => '02 308 8200',
                'city' => 'Скопје',
                'address' => 'ул. Козле 188',
                'description' => 'Производство и дистрибуција на лекови и медицински производи.',
                'logo' => 'https://logo.clearbit.com/replek.com.mk',
                'website' => 'https://www.replek.com.mk',
                'status' => 'active',
                'products_count' => 87,
            ],
            [
                'name' => 'Нови Здравствени',
                'contact_person' => 'Елена Тодоровска',
                'email' => 'kontakt@novi-zdravstveni.mk',
                'phone' => '071 555 100',
                'city' => 'Битола',
                'address' => 'ул. Партизанска 22',
                'description' => 'Дистрибутер на медицински помагала и опрема за домашна нега.',
                'logo' => null,
                'website' => null,
                'status' => 'pending',
                'products_count' => 0,
            ],
            [
                'name' => 'Фарма Експерт',
                'contact_person' => 'Никола Костов',
                'email' => 'office@farmaexpert.mk',
                'phone' => '02 333 4455',
                'city' => 'Скопје',
                'address' => 'ул. Орце Николов 75',
                'description' => 'Велепродажба на витамини, додатоци во исхрана и природна козметика.',
                'logo' => 'https://logo.clearbit.com/farma.mk',
                'website' => 'https://www.farmaexpert.mk',
                'status' => 'active',
                'products_count' => 56,
            ],
            [
                'name' => 'Дом и Здравје',
                'contact_person' => 'Симона Илиева',
                'email' => 'hello@domzdravje.mk',
                'phone' => '078 200 300',
                'city' => 'Тетово',
                'address' => 'ул. Илинденска 8',
                'description' => 'Online ретејлер за хигиена, бебешка нега и помагала за стари лица.',
                'logo' => null,
                'website' => 'https://www.domzdravje.mk',
                'status' => 'pending',
                'products_count' => 23,
            ],
            [
                'name' => 'Стар Гери',
                'contact_person' => 'Игор Петров',
                'email' => 'sales@stargery.mk',
                'phone' => '070 123 456',
                'city' => 'Куманово',
                'address' => 'ул. Маршал Тито бб',
                'description' => 'Стара регистрација, не активна повеќе.',
                'logo' => null,
                'website' => null,
                'status' => 'cancel',
                'products_count' => 0,
            ],
        ];

        foreach ($vendors as $i => $data) {
            $data['sort_order'] = $i + 1;
            ShopVendor::updateOrCreate(
                ['name' => $data['name']],
                $data
            );
        }

        $this->command->info(count($vendors) . ' shop vendors seeded.');
    }
}
