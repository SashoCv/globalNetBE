<?php

namespace Database\Seeders;

use App\Models\EduMunicipality;
use App\Models\EduRegion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Обуки — regions, municipalities and the 20 training centres from
 * database/seeders/data/edu/geo.php. Idempotent; run by hand:
 *
 *   php artisan db:seed --class=EduGeoSeeder
 *
 * Not part of DatabaseSeeder. The client's РЕОНИ sheet, once it arrives, is
 * imported over this with edu:import-workbook.
 */
class EduGeoSeeder extends Seeder
{
    public function run(): void
    {
        $data = require database_path('seeders/data/edu/geo.php');

        DB::transaction(function () use ($data) {
            $regions = [];
            foreach ($data['regions'] as $region) {
                $regions[$region['name']] = EduRegion::updateOrCreate(
                    ['name' => $region['name']],
                    ['sort_order' => $region['sort_order']],
                )->id;
            }

            $ids = [];
            foreach ($data['municipalities'] as $row) {
                $hubShort = $data['hubs'][$row['name']] ?? null;
                $ids[$row['name']] = EduMunicipality::updateOrCreate(['name' => $row['name']], [
                    'name_sq' => $row['name_sq'],
                    'region_id' => $regions[$row['region']],
                    'is_training_hub' => $hubShort !== null,
                    'hub_short_name' => $hubShort,
                    'part_of_skopje' => $row['part_of_skopje'],
                    'aliases' => $row['aliases'] ?: null,
                ])->id;
            }

            // Second pass: centres exist now.
            foreach ($data['municipalities'] as $row) {
                EduMunicipality::whereKey($ids[$row['name']])->update(['hub_municipality_id' => $ids[$row['hub']]]);
            }
        });
    }
}
