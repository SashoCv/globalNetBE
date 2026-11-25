<?php

namespace App\Console\Commands;

use App\Models\ShopCategory;
use App\Models\ShopOrderItem;
use App\Models\ShopProcurementDispatch;
use App\Models\ShopProduct;
use App\Models\ShopVendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The three ВФП companies become one vendor, ВФП, with a service each. Vendor
 * 23 stays; 24 and 26 are hidden (status cancel, nothing is deleted) and what
 * was theirs moves to it. Running it again changes nothing.
 */
class MergeWvpVendors extends Command
{
    private const KEEP = 23;

    // vendor id => the service it becomes. The text is the vendor's own.
    private const SERVICES = [
        23 => [
            'slug' => 'dobrovolno-penzisko-osiguruvanje',
            'name' => 'Доброволно пензиско осигурување',
            'short' => 'Доброволно пензиско штедење (трет столб) и професионална пензиска шема за вработените во ординацијата.',
            'category' => 'dopolnitelni-osiguruvanja',
        ],
        24 => [
            'slug' => 'investiciski-fondovi',
            'name' => 'Инвестициски фондови',
            'short' => 'Управување со отворени и затворени инвестициски фондови.',
            'category' => 'investicii-i-fondovi',
        ],
        26 => [
            'slug' => 'osiguritelno-brokerstvo',
            'name' => 'Осигурително брокерство',
            'short' => 'Посредување при избор на осигурително покритие.',
            'category' => 'osiguruvanje-na-vraboteni-i-ordinacii',
        ],
    ];

    private const VENDOR = [
        'name' => 'ВФП',
        'slug' => 'vfp',
        'address' => 'бул. Кочо Рацин бр.3/2',
        'website' => 'https://www.wvp.mk',
        'description' => "ВФП на едно место нуди три финансиски услуги за ординациите и нивните вработени: доброволно пензиско осигурување, инвестициски фондови и осигурително брокерство.\n"
            . "Доброволно пензиско осигурување – преку ВФП Пензиско Друштво АД Скопје, кое управува со ВФП Отворен доброволен пензиски фонд - Скопје, под надзор на МАПАС.\n"
            . "Инвестициски фондови – преку ВФП Фонд Менаџмент АД Скопје, друштво за управување со отворени и затворени инвестициски фондови.\n"
            . 'Осигурително брокерство – преку Осигурително брокерско друштво ВФП АД Скопје, лиценцирано од Агенцијата за супервизија на осигурување.',
    ];

    protected $signature = 'shop:merge-wvp-vendors {--dry-run : Show what would change, without saving}';

    protected $description = 'Merge the three ВФП vendors (23, 24, 26) into one vendor with three services';

    public function handle(): int
    {
        $vendors = ShopVendor::with('categories')->whereIn('id', array_keys(self::SERVICES))->get()->keyBy('id');

        foreach (array_keys(self::SERVICES) as $id) {
            $vendor = $vendors->get($id);
            if (! $vendor || ! str_contains($vendor->name, 'ВФП')) {
                $this->error("Добавувачот #{$id} не постои или не е ВФП" . ($vendor ? " ({$vendor->name})" : '') . '. Ништо не е сменето.');

                return self::FAILURE;
            }
        }

        $keep = $vendors[self::KEEP];
        $others = $vendors->except(self::KEEP);
        $otherIds = $others->pluck('id')->all();
        $dry = (bool) $this->option('dry-run');

        DB::beginTransaction();

        try {
            foreach (self::SERVICES as $id => $service) {
                $this->addService($keep, $vendors[$id], $service);
            }

            // One by one: the model keeps slugs unique within the vendor.
            $moved = 0;
            foreach (ShopProduct::whereIn('shop_vendor_id', $otherIds)->get() as $product) {
                $product->update(['shop_vendor_id' => $keep->id]);
                $moved++;
            }
            $this->line("  Преместени производи/услуги: {$moved}");

            $this->line('  Преместени ставки од нарачки: '
                . ShopOrderItem::whereIn('shop_vendor_id', $otherIds)->update(['shop_vendor_id' => $keep->id]));
            $this->line('  Преместени испраќања: '
                . ShopProcurementDispatch::whereIn('shop_vendor_id', $otherIds)->update(['shop_vendor_id' => $keep->id]));

            $keep->categories()->syncWithoutDetaching(
                ShopProduct::where('shop_vendor_id', $keep->id)->whereNotNull('shop_category_id')->pluck('shop_category_id')
                    ->merge($others->flatMap(fn ($v) => $v->categories->pluck('id')))
                    ->unique()->values()->all()
            );

            if ($keep->name !== self::VENDOR['name']) {
                $keep->update(self::VENDOR + ['logo' => $vendors[26]->logo ?: $keep->logo]);
                $this->line('  Добавувач #' . self::KEEP . ' → ' . self::VENDOR['name']);
            }

            foreach ($others as $vendor) {
                if ($vendor->status !== 'cancel') {
                    $vendor->update(['status' => 'cancel']);
                    $this->line("  Скриен добавувач #{$vendor->id} {$vendor->name}");
                }
            }

            foreach ($vendors as $vendor) {
                $vendor->update(['products_count' => ShopProduct::where('shop_vendor_id', $vendor->id)->count()]);
            }

            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $this->info($dry ? 'DRY RUN — ништо не е зачувано.' : 'Готово.');

        return self::SUCCESS;
    }

    /**
     * @param  array{slug: string, name: string, short: string, category: string}  $service
     */
    private function addService(ShopVendor $keep, ShopVendor $from, array $service): void
    {
        if (ShopProduct::where('shop_vendor_id', $keep->id)->where('slug', $service['slug'])->exists()) {
            $this->line("  Услугата „{$service['name']}“ веќе постои");

            return;
        }

        $category = $from->categories->firstWhere('kind', 'service')
            ?? ShopCategory::where('slug', $service['category'])->first();

        ShopProduct::create([
            'shop_vendor_id' => $keep->id,
            'shop_category_id' => $category?->id,
            'name' => $service['name'],
            'slug' => $service['slug'],
            'kind' => 'service',
            'currency' => 'MKD',
            'short_description' => $service['short'],
            'description' => trim($from->description . "\n\n" . $this->contact($from)),
            'image' => $from->logo,
            'status' => 'active',
            'sort_order' => (int) ShopProduct::where('shop_vendor_id', $keep->id)->max('sort_order') + 1,
        ]);

        $this->line("  Услуга „{$service['name']}“ од #{$from->id} {$from->name}" . ($category ? " ({$category->name})" : ' (без категорија)'));
    }

    private function contact(ShopVendor $v): string
    {
        $address = implode(', ', array_filter([$v->address, $v->city]));

        return implode("\n", array_filter([
            "Контакт: {$v->name}",
            $address ?: null,
            $v->phone ? "Тел: {$v->phone}" : null,
            $v->email ? "Е-пошта: {$v->email}" : null,
            $v->website ? "Веб: {$v->website}" : null,
        ]));
    }
}
