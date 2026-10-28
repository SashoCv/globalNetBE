<?php

namespace Database\Seeders;

use App\Models\ShopCategory;
use App\Models\ShopProduct;
use App\Models\ShopVendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * ИТД ДИСТРИБУЦИЈА ДОО: 26 products (laptops, PCs, monitors, accessories)
 * from the vendor's import sheet, with their pictures.
 *
 *   php artisan db:seed --class=ItdDistribucijaSeeder --force
 *
 * The sheet files everything under "Бела техника"; it all goes to the IT
 * equipment category instead. Prices in the shop are VAT-inclusive:
 * cost_price is the purchase price with VAT, price adds the sheet's 10%
 * recommended retail markup.
 *
 * Safe to run again: it updates the same vendor and products.
 */
class ItdDistribucijaSeeder extends Seeder
{
    private const MARKUP_PERCENT = 10;

    public function run(): void
    {
        // The vendor already exists in production; leave its details alone.
        $vendor = ShopVendor::firstOrCreate(
            ['slug' => 'itd-distribucija-doo'],
            [
                'name' => 'ИТД ДИСТРИБУЦИЈА ДОО',
                'city' => 'Скопје',
                'description' => 'Дистрибутер на компјутерска опрема — лаптопи, персонални компјутери, '
                    . 'монитори и додатоци од HP, Dell, Lenovo и Jabra.',
                'status' => 'active',
                'has_delivery' => true,
            ]
        );

        $category = ShopCategory::firstOrCreate(
            ['slug' => 'it-uslugi-kompjuterska-oprema-i-komunikacija'],
            ['name' => 'ИТ услуги, компјутерска опрема и комуникација', 'kind' => 'product', 'is_active' => true]
        );

        $products = require __DIR__ . '/data/itd_distribucija_products.php';

        foreach ($products as $i => $item) {
            $cost = round($item['cost'] * (1 + $item['vat'] / 100), 2);

            ShopProduct::updateOrCreate(
                ['shop_vendor_id' => $vendor->id, 'sku' => $item['sku']],
                [
                    'shop_category_id' => $category->id,
                    'name' => $item['name'],
                    'kind' => 'product',
                    'price' => round($cost * (1 + self::MARKUP_PERCENT / 100)),
                    'cost_price' => $cost,
                    'vat_rate' => $item['vat'],
                    'margin_percent' => self::MARKUP_PERCENT,
                    'currency' => 'MKD',
                    'stock' => $item['stock'],
                    'image' => $this->publishImage($item['image']),
                    'short_description' => "{$item['type']} {$item['brand']}, гаранција {$item['warranty']}.",
                    'description' => implode("\n", array_filter([
                        $item['spec'],
                        "Производител: {$item['brand']}",
                        "Гаранција: {$item['warranty']}",
                        "Рок на испорака: {$item['delivery_days']} работни дена",
                        $item['ean'] ? "Баркод (EAN): {$item['ean']}" : null,
                    ])),
                    'status' => 'active',
                    'is_featured' => false,
                    'sort_order' => $i + 1,
                ]
            );
        }

        $vendor->categories()->syncWithoutDetaching([$category->id]);
        $vendor->update(['products_count' => ShopProduct::where('shop_vendor_id', $vendor->id)->count()]);

        $this->command->info("ИТД ДИСТРИБУЦИЈА ДОО: " . count($products) . " производи во „{$category->name}“.");
    }

    /**
     * Copy a picture shipped with this seeder (data/itd) to public storage.
     * The path is relative to /storage; the shop and admin add the host.
     */
    private function publishImage(string $file): string
    {
        $path = 'shop-products/itd/' . $file;

        Storage::disk('public')->put($path, file_get_contents(__DIR__ . '/data/itd/' . $file));

        return $path;
    }
}
