<?php

namespace Database\Seeders;

use App\Models\ShopCategory;
use App\Models\ShopProduct;
use App\Models\ShopVendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ElipsoSeeder extends Seeder
{
    public function run(): void
    {
        // ── Vendor: Елипсо ────────────────────────────────────────
        $vendor = ShopVendor::updateOrCreate(
            ['slug' => 'elipso'],
            [
                'name'            => 'Елипсо',
                'slug'            => 'elipso',
                'email'           => 'info@elipso.mk',
                'phone'           => '02 25 32 888',
                'city'            => 'Скопје',
                'address'         => 'ул. Перо Наков 116',
                'description'     => 'Водечки македонски ретејлер за техника и апарати за домаќинство. Нуди широк избор на врвни брендови (KONCAR, GORENJE, BOSCH, DE LONGHI, AUX, TCL, FOX, Xiaomi и др.) со клуб и редовни цени.',
                'website'         => 'https://elipso.mk',
                'status'          => 'active',
                'has_delivery'    => true,
                'products_count'  => 0,
                'sort_order'      => 10,
            ]
        );

        $this->command->info('Vendor Елипсо креиран/ажуриран.');

        // ── Категории ─────────────────────────────────────────────
        // price  = редовна цена (retail)
        // cost_price = клуб цена (club / набавна)
        $catDefs = [
            ['name' => 'Бела техника',    'color' => '#0891b2', 'sort_order' => 10],
            ['name' => 'Кујнски апарати', 'color' => '#f59e0b', 'sort_order' => 11],
            ['name' => 'ТВ и аудио',      'color' => '#6366f1', 'sort_order' => 12],
            ['name' => 'Клима уреди',     'color' => '#06b6d4', 'sort_order' => 13],
            ['name' => 'Домаќинство',     'color' => '#84cc16', 'sort_order' => 14],
            ['name' => 'Нега и убавина',  'color' => '#ec4899', 'sort_order' => 15],
        ];

        $cats = [];
        foreach ($catDefs as $c) {
            $cats[$c['name']] = ShopCategory::updateOrCreate(
                ['name' => $c['name']],
                array_merge($c, ['kind' => 'product', 'is_active' => true])
            );
        }

        $vendor->categories()->syncWithoutDetaching(
            collect($cats)->pluck('id')->all()
        );

        $this->command->info(count($cats) . ' категории креирани/поврзани со Елипсо.');

        // ── Продукти ──────────────────────────────────────────────
        // status = 'draft' — прегледај и активирај по потреба
        $products = [

            // ── Бела техника ─────────────────────────────────────
            ['name' => 'KONCAR Комбиниран фрижидер HC 60293NFINVIDH', 'sku' => 'KON-HC60293NFINVIDH', 'cat' => 'Бела техника', 'price' => 31700.00, 'cost_price' => 27900.00, 'stock' => 5],
            ['name' => 'KONCAR Комбиниран фрижидер HC 60293NFINVIH',  'sku' => 'KON-HC60293NFINVIH',  'cat' => 'Бела техника', 'price' => 30190.00, 'cost_price' => 26570.00, 'stock' => 5],
            ['name' => 'KONCAR Комбиниран фрижидер HC 60293NFINVBH',  'sku' => 'KON-HC60293NFINVBH',  'cat' => 'Бела техника', 'price' => 28150.00, 'cost_price' => 24770.00, 'stock' => 5],
            ['name' => 'KONCAR Фрижидер HD 90548EIM',                  'sku' => 'KON-HD90548EIM',       'cat' => 'Бела техника', 'price' => 42050.00, 'cost_price' => 36990.00, 'stock' => 3],
            ['name' => 'KONCAR Комбиниран фрижидер HLE 55206SH',       'sku' => 'KON-HLE55206SH',       'cat' => 'Бела техника', 'price' => 15890.00, 'cost_price' => 13990.00, 'stock' => 8],
            ['name' => 'KONCAR Фрижидер со една врата H 55230IM',      'sku' => 'KON-H55230IM',         'cat' => 'Бела техника', 'price' => 17990.00, 'cost_price' => 15830.00, 'stock' => 6],
            ['name' => 'KONCAR Фрижидер со една врата H 55230BM',      'sku' => 'KON-H55230BM',         'cat' => 'Бела техника', 'price' => 16300.00, 'cost_price' => 14340.00, 'stock' => 6],
            ['name' => 'KONCAR Фрижидер со една врата H 60360NFEBM',   'sku' => 'KON-H60360NFEBM',      'cat' => 'Бела техника', 'price' => 31990.00, 'cost_price' => 28150.00, 'stock' => 4],
            ['name' => 'GORENJE Комбиниран фрижидер N 619EAW4',        'sku' => 'GOR-N619EAW4',          'cat' => 'Бела техника', 'price' => 33340.00, 'cost_price' => 28990.00, 'stock' => 4],
            ['name' => 'GORENJE Комбиниран фрижидер RF 414EPS4',       'sku' => 'GOR-RF414EPS4',         'cat' => 'Бела техника', 'price' => 17240.00, 'cost_price' => 14990.00, 'stock' => 6],
            ['name' => 'GORENJE Комбиниран фрижидер RF4142PW4',        'sku' => 'GOR-RF4142PW4',         'cat' => 'Бела техника', 'price' => 16100.00, 'cost_price' => 13990.00, 'stock' => 6],
            ['name' => 'KONCAR Машина за перење PR 106ME04',            'sku' => 'KON-PR106ME04',         'cat' => 'Бела техника', 'price' => 16930.00, 'cost_price' => 13990.00, 'stock' => 8],
            ['name' => 'KONCAR Машина за перење PR 127MG17INV',         'sku' => 'KON-PR127MG17INV',      'cat' => 'Бела техника', 'price' => 20650.00, 'cost_price' => 18170.00, 'stock' => 6],
            ['name' => 'KONCAR Машина за перење PR 148MG17INV',         'sku' => 'KON-PR148MG17INV',      'cat' => 'Бела техника', 'price' => 25410.00, 'cost_price' => 22360.00, 'stock' => 5],
            ['name' => 'KONCAR Машина за перење PR 149MG17INV',         'sku' => 'KON-PR149MG17INV',      'cat' => 'Бела техника', 'price' => 26600.00, 'cost_price' => 23400.00, 'stock' => 5],
            ['name' => 'KONCAR Машина за перење PR 1410MG17INV',        'sku' => 'KON-PR1410MG17INV',     'cat' => 'Бела техника', 'price' => 28260.00, 'cost_price' => 24860.00, 'stock' => 4],
            ['name' => 'KONCAR Машина за перење PRT 127MDG',            'sku' => 'KON-PRT127MDG',         'cat' => 'Бела техника', 'price' => 27100.00, 'cost_price' => 23850.00, 'stock' => 5],
            ['name' => 'BOSCH Машина за перење WAN 2426NBY',            'sku' => 'BSH-WAN2426NBY',        'cat' => 'Бела техника', 'price' => 39770.00, 'cost_price' => 34990.00, 'stock' => 4],
            ['name' => 'BOSCH Машина за перење WGG 244Z5BY',            'sku' => 'BSH-WGG244Z5BY',        'cat' => 'Бела техника', 'price' => 47400.00, 'cost_price' => 37900.00, 'stock' => 3],
            ['name' => 'GORENJE Машина за перење WPNEI 72A1SWIFI',      'sku' => 'GOR-WPNEI72A1SWIFI',   'cat' => 'Бела техника', 'price' => 28740.00, 'cost_price' => 24990.00, 'stock' => 5],
            ['name' => 'GORENJE Машина за перење WPNEI 84A1SWIFI',      'sku' => 'GOR-WPNEI84A1SWIFI',   'cat' => 'Бела техника', 'price' => 32200.00, 'cost_price' => 27990.00, 'stock' => 5],
            ['name' => 'GORENJE Машина за перење WPNEI 94A1SWIFI',      'sku' => 'GOR-WPNEI94A1SWIFI',   'cat' => 'Бела техника', 'price' => 34500.00, 'cost_price' => 29990.00, 'stock' => 4],
            ['name' => 'GORENJE Машина за перење WPNEI 14A2SWIFI',      'sku' => 'GOR-WPNEI14A2SWIFI',   'cat' => 'Бела техника', 'price' => 37940.00, 'cost_price' => 32990.00, 'stock' => 4],
            ['name' => 'GORENJE Машина за перење и сушење WD 2PA1X64ADW', 'sku' => 'GOR-WD2PA1X64ADW', 'cat' => 'Бела техника', 'price' => 49440.00, 'cost_price' => 42990.00, 'stock' => 3],
            ['name' => 'GORENJE Машина за сушење DHNE7D',               'sku' => 'GOR-DHNE7D',           'cat' => 'Бела техника', 'price' => 29890.00, 'cost_price' => 25990.00, 'stock' => 4],
            ['name' => 'GORENJE Машина за сушење D2HNE9D',              'sku' => 'GOR-D2HNE9D',          'cat' => 'Бела техника', 'price' => 36790.00, 'cost_price' => 31990.00, 'stock' => 3],
            ['name' => 'KONCAR Машина за садови PP 45BM6',              'sku' => 'KON-PP45BM6',          'cat' => 'Бела техника', 'price' => 19490.00, 'cost_price' => 17150.00, 'stock' => 5],
            ['name' => 'KONCAR Машина за садови PP 60ICM6',             'sku' => 'KON-PP60ICM6',         'cat' => 'Бела техника', 'price' => 19310.00, 'cost_price' => 16990.00, 'stock' => 5],
            ['name' => 'FAVORIT Машина за садови BI 60I14N',            'sku' => 'FAV-BI60I14N',         'cat' => 'Бела техника', 'price' => 19600.00, 'cost_price' => 17700.00, 'stock' => 4],
            ['name' => 'KONCAR Електричен шпорет ST 5040KIS',           'sku' => 'KON-ST5040KIS',        'cat' => 'Бела техника', 'price' => 23150.00, 'cost_price' => 20370.00, 'stock' => 5],
            ['name' => 'KONCAR Електричен шпорет ST 6040KIS',           'sku' => 'KON-ST6040KIS',        'cat' => 'Бела техника', 'price' => 24990.00, 'cost_price' => 21990.00, 'stock' => 5],
            ['name' => 'KONCAR Електричен шпорет ST 6040KBS',           'sku' => 'KON-ST6040KBS',        'cat' => 'Бела техника', 'price' => 22990.00, 'cost_price' => 20230.00, 'stock' => 5],
            ['name' => 'KONCAR Комбиниран шпорет ST 6013BS',            'sku' => 'KON-ST6013BS',         'cat' => 'Бела техника', 'price' => 18980.00, 'cost_price' => 16699.00, 'stock' => 6],
            ['name' => 'SENCOR Мини шпорет SEO 4800BK',                'sku' => 'SEN-SEO4800BK',        'cat' => 'Бела техника', 'price' =>  9190.00, 'cost_price' =>  7990.00, 'stock' => 8],
            ['name' => 'KONCAR Вградна рерна UPO 659CM',                'sku' => 'KON-UPO659CM',         'cat' => 'Бела техника', 'price' => 12990.00, 'cost_price' => 11440.00, 'stock' => 5],
            ['name' => 'KONCAR Вграден сет рерна и плотна UGS 6 M',    'sku' => 'KON-UGS6M',           'cat' => 'Бела техника', 'price' => 22620.00, 'cost_price' => 19990.00, 'stock' => 4],
            ['name' => 'KONCAR Вградна индукциска плотна UK 302INDM',   'sku' => 'KON-UK302INDM',        'cat' => 'Бела техника', 'price' => 12050.00, 'cost_price' => 10600.00, 'stock' => 5],
            ['name' => 'FAVORIT Вградна плотна BIH-40',                 'sku' => 'FAV-BIH40',            'cat' => 'Бела техника', 'price' =>  9400.00, 'cost_price' =>  8500.00, 'stock' => 5],
            ['name' => 'KONCAR Аспиратор NU 60MI',                      'sku' => 'KON-NU60MI',           'cat' => 'Бела техника', 'price' =>  6590.00, 'cost_price' =>  5790.00, 'stock' => 8],
            ['name' => 'JETAIR Аспиратор PIPE ISLAND BL/A/43',          'sku' => 'JET-PIPEISLAND-BLA43', 'cat' => 'Бела техника', 'price' => 48860.00, 'cost_price' => 42990.00, 'stock' => 2],
            ['name' => 'KONCAR Бојлер за бања EGV 502RM',               'sku' => 'KON-EGV502RM',         'cat' => 'Бела техника', 'price' =>  8790.00, 'cost_price' =>  7740.00, 'stock' => 8],
            ['name' => 'KONCAR Бојлер за бања EGV 502SRDM',             'sku' => 'KON-EGV502SRDM',       'cat' => 'Бела техника', 'price' => 14990.00, 'cost_price' => 13190.00, 'stock' => 6],
            ['name' => 'KONCAR Бојлер за бања EGV 802SRDM',             'sku' => 'KON-EGV802SRDM',       'cat' => 'Бела техника', 'price' => 18130.00, 'cost_price' => 15950.00, 'stock' => 5],
            ['name' => 'KONCAR Кујнски бојлер EGV 72RPM',               'sku' => 'KON-EGV72RPM',         'cat' => 'Бела техника', 'price' =>  6440.00, 'cost_price' =>  5670.00, 'stock' => 8],
            ['name' => 'GORENJE Бојлер за бања TGR 50W-VH',             'sku' => 'GOR-TGR50WVH',         'cat' => 'Бела техника', 'price' =>  9200.00, 'cost_price' =>  7990.00, 'stock' => 7],
            ['name' => 'GORENJE Бојлер за бања TGR 80W-VH',             'sku' => 'GOR-TGR80WVH',         'cat' => 'Бела техника', 'price' => 10340.00, 'cost_price' =>  8990.00, 'stock' => 6],

            // ── Кујнски апарати ──────────────────────────────────
            ['name' => 'DE LONGHI Кафемат EC 9885.M',                   'sku' => 'DEL-EC9885M',          'cat' => 'Кујнски апарати', 'price' => 107300.00, 'cost_price' => 94400.00,  'stock' => 2],
            ['name' => 'DE LONGHI Кафемат ECAM 470.85.MB',              'sku' => 'DEL-ECAM47085MB',      'cat' => 'Кујнски апарати', 'price' =>  77900.00, 'cost_price' => 65100.00,  'stock' => 2],
            ['name' => 'DE LONGHI Кафемат ECAM 380.95.TB',              'sku' => 'DEL-ECAM38095TB',      'cat' => 'Кујнски апарати', 'price' =>  60500.00, 'cost_price' => 50580.00,  'stock' => 3],
            ['name' => 'DE LONGHI Кафемат ECAM 440.55.BG',              'sku' => 'DEL-ECAM44055BG',      'cat' => 'Кујнски апарати', 'price' =>  51400.00, 'cost_price' => 42970.00,  'stock' => 3],
            ['name' => 'DE LONGHI Кафемат EC 9455.M',                   'sku' => 'DEL-EC9455M',          'cat' => 'Кујнски апарати', 'price' =>  49990.00, 'cost_price' => 41990.00,  'stock' => 3],
            ['name' => 'DE LONGHI Кафемат ECAM 290.85.SBX',             'sku' => 'DEL-ECAM29085SBX',     'cat' => 'Кујнски апарати', 'price' =>  38990.00, 'cost_price' => 31990.00,  'stock' => 4],
            ['name' => 'DE LONGHI Кафемат ECAM 220.50.BG',              'sku' => 'DEL-ECAM22050BG',      'cat' => 'Кујнски апарати', 'price' =>  30100.00, 'cost_price' => 25590.00,  'stock' => 5],
            ['name' => 'DE LONGHI Кафемат ECAM 22.112.B',               'sku' => 'DEL-ECAM22112B',       'cat' => 'Кујнски апарати', 'price' =>  27800.00, 'cost_price' => 19990.00,  'stock' => 5],
            ['name' => 'DE LONGHI Мелница за кафе KG 79',               'sku' => 'DEL-KG79',             'cat' => 'Кујнски апарати', 'price' =>   4550.00, 'cost_price' =>  3990.00,  'stock' => 10],
            ['name' => 'SENCOR 3 во 1 Кафемат SCC 1000BK',              'sku' => 'SEN-SCC1000BK',        'cat' => 'Кујнски апарати', 'price' =>   9490.00, 'cost_price' =>  8350.00,  'stock' => 8],
            ['name' => 'SENCOR Кафемат SCE 3050SS',                     'sku' => 'SEN-SCE3050SS',        'cat' => 'Кујнски апарати', 'price' =>   3400.00, 'cost_price' =>  2990.00,  'stock' => 12],
            ['name' => 'SENCOR Кафемат SES 1721BK',                     'sku' => 'SEN-SES1721BK',        'cat' => 'Кујнски апарати', 'price' =>   6990.00, 'cost_price' =>  5890.00,  'stock' => 8],
            ['name' => 'SENCOR Мелница за кафе SCG 5060BK',             'sku' => 'SEN-SCG5060BK',        'cat' => 'Кујнски апарати', 'price' =>   4310.00, 'cost_price' =>  3790.00,  'stock' => 10],
            ['name' => 'SENCOR Ледомат SIM 3400SS',                     'sku' => 'SEN-SIM3400SS',        'cat' => 'Кујнски апарати', 'price' =>  10100.00, 'cost_price' =>  8890.00,  'stock' => 5],
            ['name' => 'SENCOR Ледомат SIM 2500BK',                     'sku' => 'SEN-SIM2500BK',        'cat' => 'Кујнски апарати', 'price' =>   8100.00, 'cost_price' =>  7130.00,  'stock' => 6],
            ['name' => 'SENCOR Миксер SHM 7950',                        'sku' => 'SEN-SHM7950',          'cat' => 'Кујнски апарати', 'price' =>   3750.00, 'cost_price' =>  3290.00,  'stock' => 12],
            ['name' => 'SENCOR Сецко SHB 5612WP',                       'sku' => 'SEN-SHB5612WP',        'cat' => 'Кујнски апарати', 'price' =>   4890.00, 'cost_price' =>  4290.00,  'stock' => 10],
            ['name' => 'SENCOR Фритеза без масло SFR 6551 WH',          'sku' => 'SEN-SFR6551WH',        'cat' => 'Кујнски апарати', 'price' =>   6210.00, 'cost_price' =>  5470.00,  'stock' => 8],
            ['name' => 'SENCOR Фритеза на топол воздух SFR 9000SS',     'sku' => 'SEN-SFR9000SS',        'cat' => 'Кујнски апарати', 'price' =>   7100.00, 'cost_price' =>  6250.00,  'stock' => 8],
            ['name' => 'SENCOR Индукциско решо SCP 3414GY',             'sku' => 'SEN-SCP3414GY',        'cat' => 'Кујнски апарати', 'price' =>   4180.00, 'cost_price' =>  3680.00,  'stock' => 10],
            ['name' => 'SENCOR Термобокал SWK 1231BK',                  'sku' => 'SEN-SWK1231BK',        'cat' => 'Кујнски апарати', 'price' =>   1670.00, 'cost_price' =>  1470.00,  'stock' => 20],
            ['name' => 'NUTRIBULLET Блендер NB 1206DGCC',               'sku' => 'NUT-NB1206DGCC',       'cat' => 'Кујнски апарати', 'price' =>   9110.00, 'cost_price' =>  7990.00,  'stock' => 6],
            ['name' => 'NUTRIBULLET Блендер NB 907GO MC',               'sku' => 'NUT-NB907GOMC',        'cat' => 'Кујнски апарати', 'price' =>   8290.00, 'cost_price' =>  7290.00,  'stock' => 6],
            ['name' => 'NUTRIBULLET Блендер NB 907',                    'sku' => 'NUT-NB907',            'cat' => 'Кујнски апарати', 'price' =>   7730.00, 'cost_price' =>  6790.00,  'stock' => 8],
            ['name' => 'NUTRIBULLET Блендер NB 907S',                   'sku' => 'NUT-NB907S',           'cat' => 'Кујнски апарати', 'price' =>   6820.00, 'cost_price' =>  5890.00,  'stock' => 8],
            ['name' => 'NUTRIBULLET Блендер NB 606DG',                  'sku' => 'NUT-NB606DG',          'cat' => 'Кујнски апарати', 'price' =>   5230.00, 'cost_price' =>  4590.00,  'stock' => 10],
            ['name' => 'NUTRIBULLET Блендер NB 505DG',                  'sku' => 'NUT-NB505DG',          'cat' => 'Кујнски апарати', 'price' =>   4299.00, 'cost_price' =>  3790.00,  'stock' => 10],
            ['name' => 'NUTRIBULLET Фритеза без масло NBA 0811',        'sku' => 'NUT-NBA0811',          'cat' => 'Кујнски апарати', 'price' =>   7900.00, 'cost_price' =>  6950.00,  'stock' => 6],
            ['name' => 'NUTRIBULLET Фритеза без масло NBA 0611',        'sku' => 'NUT-NBA0611',          'cat' => 'Кујнски апарати', 'price' =>   6810.00, 'cost_price' =>  5990.00,  'stock' => 8],
            ['name' => 'BOSCH Рачен пасатор MSM 4W410',                 'sku' => 'BSH-MSM4W410',         'cat' => 'Кујнски апарати', 'price' =>   4180.00, 'cost_price' =>  3680.00,  'stock' => 10],

            // ── ТВ и аудио ───────────────────────────────────────
            ['name' => 'FOX SMART Телевизор 85" TV85 WOS640EU', 'sku' => 'FOX-TV85WOS640EU',  'cat' => 'ТВ и аудио', 'price' => 81240.00, 'cost_price' => 70680.00, 'stock' => 2],
            ['name' => 'FOX SMART Телевизор 75" TV75 WOS626D',  'sku' => 'FOX-TV75WOS626D',   'cat' => 'ТВ и аудио', 'price' => 50150.00, 'cost_price' => 43630.00, 'stock' => 2],
            ['name' => 'FOX SMART Телевизор 43" TV43 WOS640E',  'sku' => 'FOX-TV43WOS640E',   'cat' => 'ТВ и аудио', 'price' => 15660.00, 'cost_price' => 13620.00, 'stock' => 5],
            ['name' => 'FOX SMART Телевизор 40" TV40 WHA470E',  'sku' => 'FOX-TV40WHA470E',   'cat' => 'ТВ и аудио', 'price' => 11800.00, 'cost_price' => 10200.00, 'stock' => 5],
            ['name' => 'FOX SMART Телевизор 32" TV32 WHA450C',  'sku' => 'FOX-TV32WHA450C',   'cat' => 'ТВ и аудио', 'price' =>  7990.00, 'cost_price' =>  6790.00, 'stock' => 8],
            ['name' => 'FOX Телевизор 32" TV32 DTV231E',        'sku' => 'FOX-TV32DTV231E',   'cat' => 'ТВ и аудио', 'price' =>  6400.00, 'cost_price' =>  5500.00, 'stock' => 8],
            ['name' => 'STELL ТВ држач SHO 7200',               'sku' => 'STL-SHO7200',       'cat' => 'ТВ и аудио', 'price' =>  1260.00, 'cost_price' =>  1110.00, 'stock' => 20],
            ['name' => 'STELL ТВ држач SHO 4210',               'sku' => 'STL-SHO4210',       'cat' => 'ТВ и аудио', 'price' =>   580.00, 'cost_price' =>   510.00, 'stock' => 20],
            ['name' => 'STELL ТВ држач SHO 4200',               'sku' => 'STL-SHO4200',       'cat' => 'ТВ и аудио', 'price' =>   810.00, 'cost_price' =>   710.00, 'stock' => 20],

            // ── Клима уреди ──────────────────────────────────────
            ['name' => 'FOX Инвертер клима FAC-12INJP62',                    'sku' => 'FOX-FAC12INJP62',      'cat' => 'Клима уреди', 'price' =>  21170.00, 'cost_price' =>  17990.00, 'stock' => 5],
            ['name' => 'AUX Инвертер стоечка клима ASF-H48J4A5/AP',         'sku' => 'AUX-ASFH48J4A5AP',    'cat' => 'Клима уреди', 'price' => 148500.00, 'cost_price' => 129000.00, 'stock' => 1],
            ['name' => 'AUX Инвертер клима F-series ASW-H24F7C4',            'sku' => 'AUX-ASWH24F7C4',      'cat' => 'Клима уреди', 'price' =>  48300.00, 'cost_price' =>  41990.00, 'stock' => 3],
            ['name' => 'AUX Инвертер клима F-series ASW-H18EOC4',            'sku' => 'AUX-ASWH18EOC4',      'cat' => 'Клима уреди', 'price' =>  36800.00, 'cost_price' =>  31990.00, 'stock' => 3],
            ['name' => 'AUX Инвертер клима C-series Black ASW-H18E3A4/CC',   'sku' => 'AUX-ASWH18E3A4CC',   'cat' => 'Клима уреди', 'price' =>  44840.00, 'cost_price' =>  38990.00, 'stock' => 3],
            ['name' => 'AUX Инвертер клима C-series Black ASW-H12C5A4/CC',   'sku' => 'AUX-ASWH12C5A4CC',   'cat' => 'Клима уреди', 'price' =>  31100.00, 'cost_price' =>  26990.00, 'stock' => 4],
            ['name' => 'AUX Инвертер клима C-series ASW-H24G3A4',            'sku' => 'AUX-ASWH24G3A4',      'cat' => 'Клима уреди', 'price' =>  57500.00, 'cost_price' =>  49990.00, 'stock' => 3],
            ['name' => 'AUX Инвертер клима C-series ASW-H18E3A4',            'sku' => 'AUX-ASWH18E3A4',      'cat' => 'Клима уреди', 'price' =>  42540.00, 'cost_price' =>  36990.00, 'stock' => 3],
            ['name' => 'AUX Инвертер клима C-series ASW-H12C5A4',            'sku' => 'AUX-ASWH12C5A4',      'cat' => 'Клима уреди', 'price' =>  28740.00, 'cost_price' =>  24990.00, 'stock' => 4],
            ['name' => 'AUX Инвертер клима C-series ASW-H09C5A4',            'sku' => 'AUX-ASWH09C5A4',      'cat' => 'Клима уреди', 'price' =>  27600.00, 'cost_price' =>  23990.00, 'stock' => 5],
            ['name' => 'TCL Инвертер клима BreezeIN TAC-12CHSD',             'sku' => 'TCL-TAC12CHSD-BREEZE', 'cat' => 'Клима уреди', 'price' =>  32100.00, 'cost_price' =>  27890.00, 'stock' => 5],
            ['name' => 'TCL Инвертер клима BreezeIN TAC-18CHSD',             'sku' => 'TCL-TAC18CHSD-BREEZE', 'cat' => 'Клима уреди', 'price' =>  49700.00, 'cost_price' =>  43190.00, 'stock' => 3],
            ['name' => 'TCL Инвертер клима BreezeIN TAC-24CHSD',             'sku' => 'TCL-TAC24CHSD-BREEZE', 'cat' => 'Клима уреди', 'price' =>  61100.00, 'cost_price' =>  53090.00, 'stock' => 2],
            ['name' => 'TCL Инвертер клима FreshIN 2.0 TAC-12CHSD',          'sku' => 'TCL-TAC12CHSD-FRESH',  'cat' => 'Клима уреди', 'price' =>  42450.00, 'cost_price' =>  36890.00, 'stock' => 4],
            ['name' => 'TCL Инвертер клима T PRO TAC-12CHSD',                'sku' => 'TCL-TAC12CHSD-TPRO',   'cat' => 'Клима уреди', 'price' =>  31040.00, 'cost_price' =>  26990.00, 'stock' => 5],
            ['name' => 'TCL Инвертер клима ELITE TAC-12CHSD',                'sku' => 'TCL-TAC12CHSD-ELITE',  'cat' => 'Клима уреди', 'price' =>  24830.00, 'cost_price' =>  21590.00, 'stock' => 5],
            ['name' => 'TCL Инвертер клима ELITE TAC-18CHSD',                'sku' => 'TCL-TAC18CHSD-ELITE',  'cat' => 'Клима уреди', 'price' =>  38300.00, 'cost_price' =>  33290.00, 'stock' => 3],
            ['name' => 'TCL Инвертер клима ELITE TAC-24CHSD',                'sku' => 'TCL-TAC24CHSD-ELITE',  'cat' => 'Клима уреди', 'price' =>  49700.00, 'cost_price' =>  43190.00, 'stock' => 2],

            // ── Домаќинство ──────────────────────────────────────
            ['name' => 'SENCOR Правосмукалка SVC 8726BK',                 'sku' => 'SEN-SVC8726BK',   'cat' => 'Домаќинство', 'price' => 17800.00, 'cost_price' => 15660.00, 'stock' => 4],
            ['name' => 'SENCOR Правосмукалка SVC 0608BK',                 'sku' => 'SEN-SVC0608BK',   'cat' => 'Домаќинство', 'price' => 12420.00, 'cost_price' => 10930.00, 'stock' => 5],
            ['name' => 'SENCOR Правосмукалка SVC 0600GG',                 'sku' => 'SEN-SVC0600GG',   'cat' => 'Домаќинство', 'price' => 12420.00, 'cost_price' => 10930.00, 'stock' => 5],
            ['name' => 'BOSCH Правосмукалка BBHF 216',                    'sku' => 'BSH-BBHF216',     'cat' => 'Домаќинство', 'price' => 11770.00, 'cost_price' =>  9390.00, 'stock' => 5],
            ['name' => 'Xiaomi Правосмукалка робот Robot Vacuum 5',       'sku' => 'XIA-BHR0834EU',   'cat' => 'Домаќинство', 'price' => 44280.00, 'cost_price' => 38500.00, 'stock' => 3],
            ['name' => 'Xiaomi Robot Vacuum 5 Pro',                       'sku' => 'XIA-BHR07WFEU',   'cat' => 'Домаќинство', 'price' => 50600.00, 'cost_price' => 43990.00, 'stock' => 3],
            ['name' => 'Xiaomi Електричен тротинет 6',                    'sku' => 'XIA-BHR08R2GL',   'cat' => 'Домаќинство', 'price' => 33240.00, 'cost_price' => 28900.00, 'stock' => 5],
            ['name' => 'Xiaomi Електричен тротинет 6 Lite',               'sku' => 'XIA-BHR08R6GL',   'cat' => 'Домаќинство', 'price' => 26440.00, 'cost_price' => 22990.00, 'stock' => 5],
            ['name' => 'AL-KO Бензинска пила BKC 4040',                  'sku' => 'ALK-BKC4040',     'cat' => 'Домаќинство', 'price' => 13980.00, 'cost_price' => 12150.00, 'stock' => 4],
            ['name' => 'AL-KO Бензинска пила BKC 3835',                  'sku' => 'ALK-BKC3835',     'cat' => 'Домаќинство', 'price' => 13220.00, 'cost_price' => 11490.00, 'stock' => 4],
            ['name' => 'AL-KO Електрична пила EKS 2000/40',              'sku' => 'ALK-EKS200040',   'cat' => 'Домаќинство', 'price' =>  8600.00, 'cost_price' =>  7490.00, 'stock' => 5],
            ['name' => 'FOX Одвлажнувач DHF 4025',                       'sku' => 'FOX-DHF4025',     'cat' => 'Домаќинство', 'price' => 15500.00, 'cost_price' => 13500.00, 'stock' => 5],
            ['name' => 'DE LONGHI Одвлажнувач DEX 212SF',                'sku' => 'DEL-DEX212SF',    'cat' => 'Домаќинство', 'price' => 16990.00, 'cost_price' => 14950.00, 'stock' => 4],
            ['name' => 'SENCOR Smart Одвлажнувач SDH 2028WH',            'sku' => 'SEN-SDH2028WH',   'cat' => 'Домаќинство', 'price' => 17500.00, 'cost_price' => 15400.00, 'stock' => 4],
            ['name' => 'SENCOR Smart навлажнувач на воздух SHF 7647WH',  'sku' => 'SEN-SHF7647WH',   'cat' => 'Домаќинство', 'price' =>  7699.00, 'cost_price' =>  6770.00, 'stock' => 8],
            ['name' => 'SENCOR Будилник со термометар SDC 2200',         'sku' => 'SEN-SDC2200',     'cat' => 'Домаќинство', 'price' =>   670.00, 'cost_price' =>   590.00, 'stock' => 20],
            ['name' => 'SENCOR Будилник SDC 120',                        'sku' => 'SEN-SDC120',      'cat' => 'Домаќинство', 'price' =>  1480.00, 'cost_price' =>  1290.00, 'stock' => 15],
            ['name' => 'SENCOR Сет филтри SVX 030 за SVC 3001',         'sku' => 'SEN-SVX030',      'cat' => 'Домаќинство', 'price' =>   460.00, 'cost_price' =>   400.00, 'stock' => 30],
            ['name' => 'SENCOR Сет 5 кеси за SVC 3001',                 'sku' => 'SEN-SVC3001BAGS', 'cat' => 'Домаќинство', 'price' =>   610.00, 'cost_price' =>   550.00, 'stock' => 30],
            ['name' => 'SENCOR HEPA филтер SVX 051HF',                  'sku' => 'SEN-SVX051HF',    'cat' => 'Домаќинство', 'price' =>   420.00, 'cost_price' =>   370.00, 'stock' => 30],

            // ── Нега и убавина ────────────────────────────────────
            ['name' => 'SENCOR Фигаро за коса SHS 8700RS',  'sku' => 'SEN-SHS8700RS', 'cat' => 'Нега и убавина', 'price' => 3410.00, 'cost_price' => 2990.00, 'stock' => 10],
            ['name' => 'SENCOR Фен SHD 6700VT',             'sku' => 'SEN-SHD6700VT', 'cat' => 'Нега и убавина', 'price' => 1460.00, 'cost_price' => 1280.00, 'stock' => 15],
            ['name' => 'SENCOR Smart фитнес вага SBS 9002BK', 'sku' => 'SEN-SBS9002BK', 'cat' => 'Нега и убавина', 'price' => 2790.00, 'cost_price' => 2460.00, 'stock' => 10],
            ['name' => 'SENCOR Фитнес вага SBS 6025WH',     'sku' => 'SEN-SBS6025WH', 'cat' => 'Нега и убавина', 'price' => 1750.00, 'cost_price' => 1540.00, 'stock' => 12],
            ['name' => 'BRAUN Парна станица IS 1512BL',      'sku' => 'BRN-IS1512BL',  'cat' => 'Нега и убавина', 'price' => 9830.00, 'cost_price' => 8650.00, 'stock' =>  6],
            ['name' => 'BRAUN Пегла на пара SI 3053BL',      'sku' => 'BRN-SI3053BL',  'cat' => 'Нега и убавина', 'price' => 3399.00, 'cost_price' => 2990.00, 'stock' => 10],
            ['name' => 'BRAUN Пегла на пара SI 3030PU',      'sku' => 'BRN-SI3030PU',  'cat' => 'Нега и убавина', 'price' => 2490.00, 'cost_price' => 2190.00, 'stock' => 12],
            ['name' => 'BRAUN Пегла на пара SI 1080VI',      'sku' => 'BRN-SI1080VI',  'cat' => 'Нега и убавина', 'price' => 2260.00, 'cost_price' => 1990.00, 'stock' => 12],
            ['name' => 'BRAUN Пегла на пара SI 1040GR',      'sku' => 'BRN-SI1040GR',  'cat' => 'Нега и убавина', 'price' => 1990.00, 'cost_price' => 1750.00, 'stock' => 15],
            ['name' => 'BRAUN Пегла на пара SI 1019RD',      'sku' => 'BRN-SI1019RD',  'cat' => 'Нега и убавина', 'price' => 1750.00, 'cost_price' => 1540.00, 'stock' => 15],
        ];

        $total = 0;
        foreach ($products as $i => $item) {
            $cat = $cats[$item['cat']] ?? null;

            ShopProduct::updateOrCreate(
                ['shop_vendor_id' => $vendor->id, 'sku' => $item['sku']],
                [
                    'shop_category_id' => $cat?->id,
                    'name'             => $item['name'],
                    'slug'             => Str::slug($item['name']),
                    'kind'             => 'product',
                    'price'            => $item['price'],
                    'cost_price'       => $item['cost_price'],
                    'currency'         => 'MKD',
                    'stock'            => $item['stock'],
                    'status'           => 'active',
                    'is_featured'      => false,
                    'sort_order'       => $i + 1,
                ]
            );
            $total++;
        }

        $vendor->update(['products_count' => ShopProduct::where('shop_vendor_id', $vendor->id)->count()]);

        $this->command->info($total . ' Елипсо продукти сидирани (status=draft).');
        $this->command->info('Активирај по потреба: ShopProduct::where("shop_vendor_id", ' . $vendor->id . ')->update(["status" => "active"])');
    }
}
