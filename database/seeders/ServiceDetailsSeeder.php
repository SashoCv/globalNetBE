<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

/**
 * Populates services.details with the references shown on each public
 * service-detail page (events by year, training items with images, clients).
 * Data is generated from the original frontend content into
 * database/seeders/data/service_details.json and matched to services by name.
 *
 * Run standalone:  php artisan db:seed --class=Database\\Seeders\\ServiceDetailsSeeder
 */
class ServiceDetailsSeeder extends Seeder
{
    public function run(): void
    {
        $file = database_path('seeders/data/service_details.json');

        if (! is_file($file)) {
            $this->command?->warn('service_details.json not found — skipping ServiceDetailsSeeder.');

            return;
        }

        $byName = json_decode(file_get_contents($file), true) ?: [];

        foreach ($byName as $name => $details) {
            $service = Service::where('name', $name)->first();

            if (! $service) {
                $this->command?->warn("Service \"{$name}\" not found — skipping its details.");

                continue;
            }

            $service->update(['details' => $details]);
            $this->command?->info("Details set for: {$name} ({$details['refsType']}).");
        }
    }
}
