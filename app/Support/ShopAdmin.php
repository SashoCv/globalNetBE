<?php

namespace App\Support;

/**
 * SHOP_ADMIN_EMAIL may list several addresses, separated by commas: every one
 * receives the admin notifications, and the first is the contact address shown
 * to clinics and vendors.
 */
class ShopAdmin
{
    /**
     * @return string[]
     */
    public static function emails(): array
    {
        $list = explode(',', (string) config('app.shop_admin_email'));

        return array_values(array_filter(array_map('trim', $list)));
    }

    public static function contactEmail(): string
    {
        return self::emails()[0] ?? '';
    }
}
