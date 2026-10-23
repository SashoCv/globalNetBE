<?php

namespace Database\Seeders;

use App\Models\ShopCategory;
use App\Models\ShopProduct;
use App\Models\ShopVendor;
use Illuminate\Database\Seeder;

/**
 * Nexsol Labs and its three services: web sites, logo design and custom
 * software. Services have no price — a clinic sends a request and gets a quote.
 *
 *   php artisan db:seed --class=NexsolLabsSeeder --force
 *
 * Safe to run again: it updates the same vendor and services.
 */
class NexsolLabsSeeder extends Seeder
{
    public function run(): void
    {
        $vendor = ShopVendor::updateOrCreate(
            ['slug' => 'nexsol-labs'],
            [
                'name' => 'Nexsol Labs',
                'edb' => '4045025500642',
                'contact_person' => 'Сашо Цветаноски',
                'email' => 'sasocvetanoski@nexsollabs.com',
                'phone' => '078 927 041',
                'city' => 'Скопје',
                'description' => 'Софтверска куќа од Скопје. Изработуваме професионални веб страни, '
                    . 'дизајн на лого и визуелен идентитет, и софтвер по мерка — од идеја до готов '
                    . 'производ, со поддршка и по пуштањето во употреба. Ги разбираме потребите на '
                    . 'ординациите и здравствените установи, па решенијата се едноставни за користење '
                    . 'и за Вас и за Вашите пациенти.',
                'logo' => 'https://www.nexsollabs.com/logo.png',
                'website' => 'https://www.nexsollabs.com',
                'status' => 'active',
                'has_delivery' => false,
            ]
        );

        $it = ShopCategory::where('slug', 'it-uslugi-kompjuterska-oprema-i-komunikacija')->first();
        $marketing = ShopCategory::where('slug', 'marketing-i-promotivni-aktivnosti')->first();

        $services = [
            [
                'sku' => 'NXL-WEB',
                'category' => $it,
                'name' => 'Изработка на професионална веб страна',
                'short_description' => 'Модерна, брза веб страна за Вашата ординација — изгледа одлично на '
                    . 'мобилен и компјутер, лесно се наоѓа на Google и Ви носи нови пациенти.',
                'description' => implode("\n", [
                    'Дизајн по мерка, во бојата и стилот на Вашата ординација',
                    'Прилагодена за мобилен, таблет и компјутер',
                    'Страници за услуги, тим, ценовник, галерија и контакт',
                    'Онлајн закажување термини и контакт форма',
                    'Поврзување со Google Maps, Viber, WhatsApp и социјални мрежи',
                    'SEO оптимизација за подобра видливост на Google',
                    'Брзо вчитување и SSL сертификат (https)',
                    'Едноставно уредување на содржината без технички знаења',
                    'Домен, хостинг и деловни е-пошта адреси по договор',
                    'Поддршка и одржување по пуштањето во употреба',
                ]),
            ],
            [
                'sku' => 'NXL-LOGO',
                'category' => $marketing,
                'name' => 'Дизајн на лого и визуелен идентитет',
                'short_description' => 'Препознатливо лого што гради доверба кај пациентите и Ве издвојува '
                    . 'од другите — со бои, фонтови и примена на сите Ваши материјали.',
                'description' => implode("\n", [
                    'Неколку идејни решенија за лого, по Ваш избор',
                    'Корекции додека не сте целосно задоволни',
                    'Палета на бои и фонтови за целиот бренд',
                    'Верзии во боја, црно-бело и за темна позадина',
                    'Фајлови за печат и за веб (PDF, SVG, PNG)',
                    'Примена на визит карти, меморандум и печат',
                    'Профилни слики и насловни фотографии за социјални мрежи',
                    'Кратко упатство за правилна употреба на брендот',
                ]),
            ],
            [
                'sku' => 'NXL-SOFT',
                'category' => $it,
                'name' => 'Custom софтверски решенија',
                'short_description' => 'Софтвер изработен точно според начинот на кој работи Вашата ординација '
                    . '— помалку администрација, повеќе време за пациентите.',
                'description' => implode("\n", [
                    'Анализа на Вашите процеси и предлог решение',
                    'Евиденција на пациенти, прегледи и термини',
                    'Онлајн закажување со потсетници по SMS и е-пошта',
                    'Фактурирање, извештаи и статистика',
                    'Апликации за веб и мобилен',
                    'Поврзување со постоечки системи и уреди',
                    'Безбедно чување на податоците со резервни копии',
                    'Обука за користење и техничка поддршка',
                ]),
            ],
        ];

        foreach ($services as $i => $service) {
            ShopProduct::updateOrCreate(
                ['shop_vendor_id' => $vendor->id, 'sku' => $service['sku']],
                [
                    'shop_category_id' => $service['category']?->id,
                    'name' => $service['name'],
                    'kind' => 'service',
                    'price' => null,
                    'cost_price' => null,
                    'currency' => 'MKD',
                    'stock' => null,
                    'short_description' => $service['short_description'],
                    'description' => $service['description'],
                    'status' => 'active',
                    'is_featured' => false,
                    'sort_order' => $i + 1,
                ]
            );
        }

        $vendor->categories()->syncWithoutDetaching(array_filter([$it?->id, $marketing?->id]));
        $vendor->update(['products_count' => ShopProduct::where('shop_vendor_id', $vendor->id)->count()]);

        $this->command->info('Nexsol Labs: добавувач и ' . count($services) . ' услуги.');
    }
}
