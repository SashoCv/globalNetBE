<?php

namespace App\Console\Commands;

use App\Models\ShopProduct;
use App\Models\ShopVendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Pictures for the services of vendor 23 (ВФП) in place of the companies' logos,
 * which the product card crops or cannot show, and the plain WVP logo for ВФП.
 */
class UpdateWvpImages extends Command
{
    private const VENDOR = 23;

    private const IMAGES = [
        'dobrovolno-penzisko-osiguruvanje' => 'wvp-penzisko.jpg',
        'investiciski-fondovi' => 'wvp-fondovi.jpg',
        'osiguritelno-brokerstvo' => 'wvp-brokerstvo.jpg',
    ];

    // Vendor logos are full addresses; this one ships with the e-shop.
    private const LOGO = 'https://www.gnaeshop.mk/wvp-logo.png';

    protected $signature = 'shop:update-wvp-images';

    protected $description = 'Set the pictures of the ВФП services (vendor 23) and the vendor logo';

    public function handle(): int
    {
        $vendor = ShopVendor::find(self::VENDOR);

        if (! $vendor || $vendor->name !== 'ВФП') {
            $this->error('Добавувачот #' . self::VENDOR . ' не е ВФП. Прво пуштете shop:merge-wvp-vendors. Ништо не е сменето.');

            return self::FAILURE;
        }

        foreach (self::IMAGES as $slug => $file) {
            $service = ShopProduct::where('shop_vendor_id', $vendor->id)->where('slug', $slug)->first();

            if (! $service) {
                $this->warn("  Услугата {$slug} не постои — прескокната.");
                continue;
            }

            // Relative to /storage; the shop and the admin add the host.
            $path = 'shop-products/' . $file;
            Storage::disk('public')->put($path, file_get_contents(database_path('seeders/data/wvp/' . $file)));
            $service->update(['image' => $path]);
            $this->line("  {$service->name} → {$path}");
        }

        $vendor->update(['logo' => self::LOGO]);
        $this->info('Готово.');

        return self::SUCCESS;
    }
}
