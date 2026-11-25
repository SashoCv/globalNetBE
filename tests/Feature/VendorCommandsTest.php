<?php

namespace Tests\Feature;

use App\Models\ShopCategory;
use App\Models\ShopClinic;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\ShopProduct;
use App\Models\ShopVendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VendorCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            23 => ['ВФП Пензиско Друштво АД Скопје', 'penzisko@example.com', 'Опис на пензиско.'],
            24 => ['ВФП Фонд Менаџмент АД Скопје', 'fond@example.com', 'Опис на фонд.'],
            25 => ['Фармакоген', 'bio@example.com', 'Стар текст.'],
            26 => ['Акционерско осигурително брокерско друштво ВФП АД Скопје', 'broker@example.com', 'Опис на брокер.'],
        ] as $id => [$name, $email, $description]) {
            (new ShopVendor)->forceFill([
                'id' => $id, 'name' => $name, 'email' => $email, 'description' => $description, 'phone' => '070' . $id,
                'logo' => "https://example.com/{$id}.png", 'status' => 'active',
            ])->save();
        }
    }

    public function test_the_three_companies_become_one_vendor_with_three_services(): void
    {
        $own = ShopCategory::create(['name' => 'Инвестиции и Фондови', 'slug' => 'investicii-i-fondovi', 'kind' => 'service', 'is_active' => true]);
        ShopCategory::create(['name' => 'Дополнителни', 'slug' => 'dopolnitelni-osiguruvanja', 'kind' => 'service', 'is_active' => true]);
        $chosen = ShopCategory::create(['name' => 'Избрана', 'slug' => 'izbrana', 'kind' => 'service', 'is_active' => true]);
        ShopVendor::find(26)->categories()->attach($chosen->id);

        $old = ShopProduct::create(['shop_vendor_id' => 24, 'name' => 'Стара', 'kind' => 'service', 'status' => 'active']);
        $clinic = ShopClinic::create(['name' => 'ПЗУ', 'slug' => 'pzu', 'email' => 'c@example.com', 'status' => 'approved', 'password' => bcrypt('x')]);
        $order = ShopOrder::create(['order_number' => 'GNA-1', 'shop_clinic_id' => $clinic->id, 'status' => 'new', 'payment_status' => 'not_required', 'subtotal' => 0, 'total' => 0, 'placed_at' => now()]);
        $item = ShopOrderItem::create(['shop_order_id' => $order->id, 'shop_product_id' => $old->id, 'shop_vendor_id' => 24, 'product_name' => 'Стара', 'kind' => 'service', 'unit_sale_price' => 0, 'unit_cost_price' => 0, 'quantity' => 1, 'subtotal' => 0, 'cost_subtotal' => 0]);

        $this->artisan('shop:merge-wvp-vendors', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('ВФП Пензиско Друштво АД Скопје', ShopVendor::find(23)->name);
        $this->assertSame(1, ShopProduct::count());

        // A second run changes nothing.
        $this->artisan('shop:merge-wvp-vendors')->assertSuccessful();
        $this->artisan('shop:merge-wvp-vendors')->assertSuccessful();

        $keep = ShopVendor::find(23);
        $this->assertSame(['ВФП', 'vfp', 'active', 4, 'https://example.com/26.png'], [$keep->name, $keep->slug, $keep->status, $keep->products_count, $keep->logo]);
        $this->assertSame(['cancel', 'cancel'], ShopVendor::whereIn('id', [24, 26])->pluck('status')->all());
        $this->assertSame(23, $old->fresh()->shop_vendor_id);
        $this->assertSame(23, $item->fresh()->shop_vendor_id);

        $services = ShopProduct::where('shop_vendor_id', 23)->where('id', '!=', $old->id)->orderBy('id')->get();
        $this->assertSame(['Доброволно пензиско осигурување', 'Инвестициски фондови', 'Осигурително брокерство'], $services->pluck('name')->all());
        $this->assertSame(['service'], $services->pluck('kind')->unique()->all());
        $this->assertStringContainsString('Опис на пензиско.', $services[0]->description);
        $this->assertStringContainsString('fond@example.com', $services[1]->description);
        $this->assertSame($own->id, $services[1]->shop_category_id);
        $this->assertSame($chosen->id, $services[2]->shop_category_id);

        // On the e-shop: one ВФП, its services open to clinics.
        $names = collect($this->getJson('/api/public/shop-vendors')->json())->pluck('name');
        $this->assertSame(1, $names->filter(fn ($n) => str_contains($n, 'ВФП'))->count());
        Sanctum::actingAs($clinic, [], 'sanctum');
        $this->getJson('/api/public/shop-vendors/23')->assertOk()->assertJsonPath('total', 4);
        $this->getJson('/api/public/shop-vendors/24')->assertNotFound();
    }

    public function test_the_services_get_their_pictures(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $this->artisan('shop:update-wvp-images')->assertFailed();   // not merged yet

        $this->artisan('shop:merge-wvp-vendors')->assertSuccessful();
        $this->artisan('shop:update-wvp-images')->assertSuccessful();

        $images = ShopProduct::where('shop_vendor_id', 23)->orderBy('id')->pluck('image')->all();
        $this->assertSame(['shop-products/wvp-penzisko.jpg', 'shop-products/wvp-fondovi.jpg', 'shop-products/wvp-brokerstvo.jpg'], $images);
        foreach ($images as $path) {
            \Illuminate\Support\Facades\Storage::disk('public')->assertExists($path);
        }
        $this->assertSame('https://www.gnaeshop.mk/wvp-logo.png', ShopVendor::find(23)->logo);
    }

    public function test_nothing_is_merged_when_an_id_is_another_vendor(): void
    {
        ShopVendor::find(26)->update(['name' => 'Друг добавувач']);

        $this->artisan('shop:merge-wvp-vendors')->assertFailed();

        $this->assertSame(0, ShopProduct::count());
        $this->assertSame('active', ShopVendor::find(24)->status);
    }

    public function test_farmakogen_gets_its_new_name_and_text(): void
    {
        $slug = ShopVendor::find(25)->slug;

        $this->artisan('shop:update-farmakogen', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('Стар текст.', ShopVendor::find(25)->description);

        $this->artisan('shop:update-farmakogen')->assertSuccessful();
        $this->artisan('shop:update-farmakogen')->assertSuccessful();

        $v = ShopVendor::find(25);
        $this->assertSame(['ФармакоГен', $slug, 'bio@example.com', '07025'], [$v->name, $v->slug, $v->email, $v->phone]);
        $this->assertStringStartsWith('ФармакоГен нуди фармакогенетски испитувања', $v->description);
    }
}
