<?php

namespace Database\Seeders;

use App\Models\ShopCategory;
use App\Models\ShopProduct;
use App\Models\ShopVendor;
use Illuminate\Database\Seeder;

class ElipsoSeeder extends Seeder
{
    public function run(): void
    {
        // ── Vendor: Елипсо ────────────────────────────────────────
        $vendor = ShopVendor::updateOrCreate(
            ['slug' => 'elipso'],
            [
                'name'            => 'Елипсо',
                'slug'            => 'elipso',
                'email'           => 'info@elipso.mk',
                'phone'           => '02 25 32 888',
                'city'            => 'Скопје',
                'address'         => 'ул. Перо Наков 116',
                'description'     => 'Водечки македонски ретејлер за техника и апарати за домаќинство. Нуди широк избор на врвни брендови (KONCAR, GORENJE, BOSCH, DE LONGHI, AUX, TCL, FOX, Xiaomi и др.) со клуб и редовни цени.',
                'website'         => 'https://elipso.mk',
                'status'          => 'active',
                'has_delivery'    => true,
                'products_count'  => 0,
                'sort_order'      => 10,
            ]
        );

        $this->command->info('Vendor Елипсо креиран/ажуриран.');

        // ── Категории ─────────────────────────────────────────────
        $catDefs = [
            ['name' => 'Бела техника',             'color' => '#0891b2', 'sort_order' => 10],
            ['name' => 'Кујнски апарати',           'color' => '#f59e0b', 'sort_order' => 11],
            ['name' => 'ТВ и аудио',                'color' => '#6366f1', 'sort_order' => 12],
            ['name' => 'Ладење и греење',           'color' => '#06b6d4', 'sort_order' => 13],
            ['name' => 'Домаќинство и градина',     'color' => '#84cc16', 'sort_order' => 14],
            ['name' => 'Нега и убавина',            'color' => '#ec4899', 'sort_order' => 15],
            ['name' => 'Хигиена во домот',          'color' => '#10b981', 'sort_order' => 16],
            ['name' => 'Спорт и рекреација',        'color' => '#f97316', 'sort_order' => 17],
        ];

        $cats = [];
        foreach ($catDefs as $c) {
            $cats[$c['name']] = ShopCategory::updateOrCreate(
                ['name' => $c['name']],
                array_merge($c, ['kind' => 'product', 'is_active' => true])
            );
        }

        $vendor->categories()->syncWithoutDetaching(
            collect($cats)->pluck('id')->all()
        );

        $this->command->info(count($cats) . ' категории креирани/поврзани со Елипсо.');

        // ── Целосен каталог од elipso.mk/shop (17 страни × 72 продукти) ─────
        // price = клуб цена (цена кон ординации). cost_price = null (цена кон нас — се пополнува рачно, преку посебна табела).
        $fullCatalog = require __DIR__ . '/data/elipso_products_full.php';
        $total = 0;

        foreach ($fullCatalog as $i => $item) {
            $cat = $cats[$item['cat']] ?? null;

            ShopProduct::updateOrCreate(
                ['shop_vendor_id' => $vendor->id, 'sku' => $item['sku']],
                [
                    'shop_category_id' => $cat?->id,
                    'name'             => $item['name'],
                    'kind'             => 'product',
                    'price'            => $item['price'],
                    'cost_price'       => null,
                    'currency'         => 'MKD',
                    'stock'            => $item['stock'],
                    'image'            => $item['image'],
                    'status'           => 'active',
                    'is_featured'      => false,
                    'sort_order'       => $i + 1,
                ]
            );
            $total++;
        }

        $vendor->update(['products_count' => ShopProduct::where('shop_vendor_id', $vendor->id)->count()]);

        $this->command->info($total . ' Елипсо продукти сидирани (status=active, cost_price=null).');
    }
}
