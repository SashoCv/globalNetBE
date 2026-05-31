<?php

namespace Database\Seeders;

use App\Models\GalleryEvent;
use App\Models\GalleryImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Replaces the gallery tables with the real images that live under
 * storage/app/public/gallery/{nastani,promocii}. Idempotent: wipes the gallery
 * tables first, then rebuilds two events from whatever files are on disk.
 *
 * Run standalone:  php artisan db:seed --class=Database\\Seeders\\GallerySeeder
 */
class GallerySeeder extends Seeder
{
    public function run(): void
    {
        // Wipe existing gallery data (images cascade via FK, but be explicit).
        GalleryImage::query()->delete();
        GalleryEvent::query()->delete();

        $this->seedFolder(
            folder: 'gallery/nastani',
            name: 'Реализирани настани',
            category: 'events',
            location: 'Северна Македонија',
            showOnHome: true,
            featured: true,
        );

        $this->seedFolder(
            folder: 'gallery/promocii',
            name: 'Промотивни активности',
            category: 'promotions',
            location: 'Северна Македонија',
            showOnHome: true,
            featured: false,
        );
    }

    private function seedFolder(
        string $folder,
        string $name,
        string $category,
        string $location,
        bool $showOnHome,
        bool $featured,
    ): void {
        $files = collect(Storage::disk('public')->files($folder))
            ->filter(fn ($p) => preg_match('/\.(webp|jpe?g|png|gif)$/i', $p))
            ->all();

        // Natural sort so image2 comes before image10.
        natsort($files);
        $files = array_values($files);

        if (empty($files)) {
            $this->command?->warn("No files found in storage/app/public/{$folder} — skipping {$name}.");

            return;
        }

        $event = GalleryEvent::create([
            'name' => $name,
            'category' => $category,
            'date' => '',
            'location' => $location,
            'featured' => $featured,
            'show_on_home' => $showOnHome,
        ]);

        foreach ($files as $i => $path) {
            GalleryImage::create([
                'gallery_event_id' => $event->id,
                'path' => $path,
                'is_cover' => $i === 0,
                'original_name' => basename($path),
            ]);
        }

        $this->command?->info("Seeded {$name}: ".count($files).' images.');
    }
}
