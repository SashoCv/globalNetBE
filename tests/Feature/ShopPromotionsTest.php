<?php

namespace Tests\Feature;

use App\Models\ShopClinic;
use App\Models\ShopProduct;
use App\Models\ShopVendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The admin marks products as on promotion; the Акции page lists the active
 * ones to logged-in clinics only.
 */
class ShopPromotionsTest extends TestCase
{
    use RefreshDatabase;

    private ShopVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendor = ShopVendor::create(['name' => 'Медика', 'slug' => 'medika', 'status' => 'active']);
    }

    public function test_the_admin_puts_a_product_on_promotion_and_takes_it_off(): void
    {
        $product = $this->product('Ракавици');
        Sanctum::actingAs(User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => bcrypt('x'), 'role' => 'admin']), [], 'sanctum');

        $this->putJson("/api/shop-products/{$product->id}", ['is_promotion' => true])->assertOk()->assertJsonPath('is_promotion', true);
        $this->assertSame('Ракавици', $product->fresh()->name);
        $this->putJson("/api/shop-products/{$product->id}", ['is_promotion' => false])->assertOk()->assertJsonPath('is_promotion', false);
    }

    public function test_clinics_see_only_active_products_on_promotion(): void
    {
        $on = $this->product('Ракавици', ['is_promotion' => true]);
        $this->product('Маски');
        $this->product('Нацрт', ['is_promotion' => true, 'status' => 'draft']);
        $closed = ShopVendor::create(['name' => 'Неактивен', 'slug' => 'neaktiven', 'status' => 'pending']);
        $this->product('Туѓ', ['is_promotion' => true, 'shop_vendor_id' => $closed->id]);

        $this->getJson('/api/public/shop-products?promotion=1')->assertStatus(401);

        Sanctum::actingAs(ShopClinic::create([
            'name' => 'ПЗУ', 'slug' => 'pzu', 'email' => 'c@example.com', 'status' => 'approved', 'password' => bcrypt('x'),
        ]), [], 'sanctum');

        $this->getJson('/api/public/shop-products?promotion=1')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $on->id)
            ->assertJsonPath('data.0.isPromotion', true);
        $this->getJson('/api/public/shop-products')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/public/shop-vendors/{$this->vendor->id}")->assertOk()->assertJsonPath('products.0.isPromotion', true);
    }

    private function product(string $name, array $with = []): ShopProduct
    {
        return ShopProduct::create($with + [
            'shop_vendor_id' => $this->vendor->id, 'name' => $name, 'sku' => $name, 'kind' => 'product',
            'price' => 100, 'status' => 'active',
        ]);
    }
}
