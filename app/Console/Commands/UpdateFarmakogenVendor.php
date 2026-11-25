<?php

namespace App\Console\Commands;

use App\Models\ShopVendor;
use Illuminate\Console\Command;

/**
 * New name and text of vendor 25, ФармакоГен. Contact details stay.
 */
class UpdateFarmakogenVendor extends Command
{
    private const ID = 25;

    protected $signature = 'shop:update-farmakogen {--dry-run : Show what would change, without saving}';

    protected $description = 'Update the name and description of vendor 25 (ФармакоГен)';

    public function handle(): int
    {
        $vendor = ShopVendor::find(self::ID);

        if (! $vendor || ! str_contains(mb_strtolower($vendor->name), 'фармакоген')) {
            $this->error('Добавувачот #' . self::ID . ' не постои или не е ФармакоГен' . ($vendor ? " ({$vendor->name})" : '') . '. Ништо не е сменето.');

            return self::FAILURE;
        }

        $new = [
            'name' => 'ФармакоГен',
            // Same letters, so the link stays.
            'slug' => $vendor->slug,
            'description' => "ФармакоГен нуди фармакогенетски испитувања со цел персонализиран пристап, односно прецизен избор на терапијата за одредени заболувања.\n"
                . 'Фармакогенетиката (PGx) проучува како генетските варијации влијаат врз одговорот на организмот на лековите. Преку едноставен генетски тест, здравствените работници добиваат дополнителни информации за избор на соодветен лек и оптимална доза, со цел поефикасна и побезбедна терапија.',
        ];

        $this->line("  Име: {$vendor->name} → {$new['name']}");
        $this->line("  Опис:\n{$new['description']}");

        if ($this->option('dry-run')) {
            $this->info('DRY RUN — ништо не е зачувано.');

            return self::SUCCESS;
        }

        $vendor->update($new);
        $this->info('Готово.');

        return self::SUCCESS;
    }
}
