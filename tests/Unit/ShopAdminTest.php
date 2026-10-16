<?php

namespace Tests\Unit;

use App\Support\ShopAdmin;
use Tests\TestCase;

class ShopAdminTest extends TestCase
{
    public function test_several_addresses_are_split_on_commas(): void
    {
        config(['app.shop_admin_email' => 'info@gnaeshop.mk, prodazba@gnaeshop.mk,']);

        $this->assertSame(['info@gnaeshop.mk', 'prodazba@gnaeshop.mk'], ShopAdmin::emails());
        $this->assertSame('info@gnaeshop.mk', ShopAdmin::contactEmail());
    }

    public function test_an_empty_setting_sends_to_nobody(): void
    {
        config(['app.shop_admin_email' => '']);

        $this->assertSame([], ShopAdmin::emails());
    }
}
