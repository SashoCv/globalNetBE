<?php

namespace Database\Seeders;

use App\Models\ShopCategory;
use App\Models\ShopProduct;
use App\Models\ShopVendor;
use Illuminate\Database\Seeder;

class ShopCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // ── Categories ────────────────────────────────────
        $categories = [
            ['name' => 'Лекови без рецепт', 'kind' => 'product', 'color' => '#0d9488', 'sort_order' => 1],
            ['name' => 'Витамини и додатоци', 'kind' => 'product', 'color' => '#14b8a6', 'sort_order' => 2],
            ['name' => 'Медицинска опрема', 'kind' => 'product', 'color' => '#0891b2', 'sort_order' => 3],
            ['name' => 'Козметика и нега', 'kind' => 'product', 'color' => '#f59e0b', 'sort_order' => 4],
            ['name' => 'Хигиена', 'kind' => 'product', 'color' => '#84cc16', 'sort_order' => 5],
            ['name' => 'Консултации', 'kind' => 'service', 'color' => '#7c3aed', 'sort_order' => 6],
            ['name' => 'Дистрибуција и логистика', 'kind' => 'service', 'color' => '#ef4444', 'sort_order' => 7],
        ];
        $createdCats = [];
        foreach ($categories as $c) {
            $createdCats[$c['name']] = ShopCategory::updateOrCreate(
                ['name' => $c['name']],
                array_merge($c, ['is_active' => true])
            );
        }

        // ── Sample products per vendor ────────────────────
        $byVendor = [
            'Алкалоид АД Скопје' => [
                ['name' => 'Алкадол 500мг (20 таблети)', 'category' => 'Лекови без рецепт', 'price' => 95.00, 'stock' => 320, 'kind' => 'product', 'sku' => 'ALK-001'],
                ['name' => 'Витамин Ц 1000мг', 'category' => 'Витамини и додатоци', 'price' => 280.00, 'stock' => 180, 'kind' => 'product', 'sku' => 'ALK-V01'],
                ['name' => 'Магнезиум + Б6', 'category' => 'Витамини и додатоци', 'price' => 320.00, 'stock' => 95, 'kind' => 'product', 'sku' => 'ALK-V02'],
                ['name' => 'Beauty Crema хидратантна', 'category' => 'Козметика и нега', 'price' => 590.00, 'stock' => 64, 'kind' => 'product', 'sku' => 'ALK-C10'],
            ],
            'Реплек Фарм' => [
                ['name' => 'Реплек D3 1000', 'category' => 'Витамини и додатоци', 'price' => 250.00, 'stock' => 220, 'kind' => 'product', 'sku' => 'RPL-D01'],
                ['name' => 'Аспирин (30 таблети)', 'category' => 'Лекови без рецепт', 'price' => 110.00, 'stock' => 410, 'kind' => 'product', 'sku' => 'RPL-A30'],
                ['name' => 'Стручна консултација (30 мин)', 'category' => 'Консултации', 'price' => 1200.00, 'kind' => 'service', 'sku' => 'RPL-CONSULT'],
            ],
            'Нови Здравствени' => [
                ['name' => 'Тенсиометар (дигитален)', 'category' => 'Медицинска опрема', 'price' => 2400.00, 'stock' => 18, 'kind' => 'product', 'sku' => 'NZ-T200'],
                ['name' => 'Оксиметар за прсти', 'category' => 'Медицинска опрема', 'price' => 980.00, 'stock' => 32, 'kind' => 'product', 'sku' => 'NZ-OX1'],
                ['name' => 'Глукометар Старт', 'category' => 'Медицинска опрема', 'price' => 1450.00, 'stock' => 12, 'kind' => 'product', 'sku' => 'NZ-G45'],
            ],
            'Фарма Експерт' => [
                ['name' => 'Омега 3 (60 капсули)', 'category' => 'Витамини и додатоци', 'price' => 690.00, 'stock' => 110, 'kind' => 'product', 'sku' => 'FE-O3'],
                ['name' => 'Природна крема за раце', 'category' => 'Козметика и нега', 'price' => 350.00, 'stock' => 88, 'kind' => 'product', 'sku' => 'FE-NCR'],
                ['name' => 'Дермоактивен серум', 'category' => 'Козметика и нега', 'price' => 1290.00, 'stock' => 25, 'kind' => 'product', 'sku' => 'FE-DS'],
                ['name' => 'Дистрибуција до аптеки (региони)', 'category' => 'Дистрибуција и логистика', 'price' => null, 'kind' => 'service', 'sku' => 'FE-LOG'],
            ],
            'Дом и Здравје' => [
                ['name' => 'Пелени за возрасни (М, 30 ком)', 'category' => 'Хигиена', 'price' => 750.00, 'stock' => 45, 'kind' => 'product', 'sku' => 'DZ-PA-M'],
                ['name' => 'Бебешки шампон 250мл', 'category' => 'Хигиена', 'price' => 220.00, 'stock' => 130, 'kind' => 'product', 'sku' => 'DZ-SH'],
            ],
        ];

        $total = 0;
        foreach ($byVendor as $vendorName => $items) {
            $vendor = ShopVendor::where('name', $vendorName)->first();
            if (!$vendor) continue;

            foreach ($items as $i => $item) {
                $cat = $createdCats[$item['category']] ?? null;
                ShopProduct::updateOrCreate(
                    ['shop_vendor_id' => $vendor->id, 'sku' => $item['sku']],
                    [
                        'shop_category_id' => $cat?->id,
                        'name' => $item['name'],
                        'kind' => $item['kind'],
                        'price' => $item['price'] ?? null,
                        'currency' => 'MKD',
                        'stock' => $item['stock'] ?? null,
                        'short_description' => null,
                        'status' => 'active',
                        'is_featured' => $i === 0,
                        'sort_order' => $i + 1,
                    ]
                );
                $total++;
            }

            $vendor->update(['products_count' => ShopProduct::where('shop_vendor_id', $vendor->id)->count()]);
        }

        $this->command->info(count($createdCats) . ' categories seeded.');
        $this->command->info($total . ' products/services seeded.');
    }
}
