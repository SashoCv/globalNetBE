<?php

namespace Database\Seeders;

use App\Models\GalleryCategory;
use Illuminate\Database\Seeder;

/**
 * Seeds the two original gallery categories so existing events (which store
 * category slugs "events"/"promotions") keep mapping. Idempotent.
 */
class GalleryCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'events', 'name' => 'Настани', 'name_en' => 'Events', 'sort_order' => 0],
            ['slug' => 'promotions', 'name' => 'Промоции', 'name_en' => 'Promotions', 'sort_order' => 1],
        ];

        foreach ($categories as $c) {
            GalleryCategory::updateOrCreate(
                ['slug' => $c['slug']],
                ['name' => $c['name'], 'name_en' => $c['name_en'], 'sort_order' => $c['sort_order']]
            );
        }
    }
}
