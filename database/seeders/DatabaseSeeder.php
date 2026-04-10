<?php

namespace Database\Seeders;

use App\Models\GalleryEvent;
use App\Models\GalleryImage;
use App\Models\HcClinic;
use App\Models\HcHospital;
use App\Models\Service;
use App\Models\ServiceBullet;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // ── Admin User ──────────────────────────────────────────
        User::create([
            'name' => 'Admin',
            'email' => 'admin@globalnetadv.mk',
            'password' => bcrypt('admin123'),
        ]);

        // ── Settings ────────────────────────────────────────────
        $this->seedSettings();

        // ── Services & Bullets ──────────────────────────────────
        $this->seedServices();

        // ── HC Clinics ──────────────────────────────────────────
        $this->seedClinics();

        // ── HC Hospitals ────────────────────────────────────────
        $this->seedHospitals();

        // ── Gallery Events & Images ─────────────────────────────
        $this->seedGallery();
    }

    private function seedSettings(): void
    {
        $settings = [
            // GlobalNet settings
            ['key' => 'site_name', 'value' => 'GlobalNetADV', 'group' => 'globalnet'],
            ['key' => 'site_description', 'value' => 'Агенција за маркетинг и адвертајзинг', 'group' => 'globalnet'],
            ['key' => 'phone_1', 'value' => '02 322 41 4', 'group' => 'globalnet'],
            ['key' => 'phone_2', 'value' => '071 317 377', 'group' => 'globalnet'],
            ['key' => 'email', 'value' => 'globalnetadv@globalnetadv.mk', 'group' => 'globalnet'],
            ['key' => 'address', 'value' => 'ул. Багдадска 36a 2/8, Скопје', 'group' => 'globalnet'],
            ['key' => 'facebook', 'value' => '', 'group' => 'globalnet'],
            ['key' => 'instagram', 'value' => '', 'group' => 'globalnet'],
            ['key' => 'linkedin', 'value' => '', 'group' => 'globalnet'],
            ['key' => 'hero_title', 'value' => 'Повеќе од 20 години креираме впечатливи настани, успешни промоции и препознатливи брендови.', 'group' => 'globalnet'],
            ['key' => 'hero_subtitle', 'value' => 'Од организација на конференции и корпоративни настани, преку промотивни активности и истражување на пазарот, до креативен развој на бренд – нудиме решенија кои носат резултати.', 'group' => 'globalnet'],
            ['key' => 'about_text', 'value' => 'Global Net ADV е агенција за маркетинг и адвертајзинг со седиште во Скопје. Со повеќе од 20 години искуство, ние нудиме комплетни решенија за организација на настани, промотивни активности, обуки, истражување на пазарот и креирање на бренд.', 'group' => 'globalnet'],
            ['key' => 'stat_years', 'value' => '20+', 'group' => 'globalnet'],
            ['key' => 'stat_projects', 'value' => '500+', 'group' => 'globalnet'],
            ['key' => 'stat_surveyors', 'value' => '50+', 'group' => 'globalnet'],
            ['key' => 'stat_clients', 'value' => '100+', 'group' => 'globalnet'],

            // Healthcare settings
            ['key' => 'hc_site_name', 'value' => 'GNA Healthcare', 'group' => 'healthcare'],
            ['key' => 'hc_phone', 'value' => '070/220-070', 'group' => 'healthcare'],
            ['key' => 'hc_email', 'value' => 'healthcare@globalnetadv.mk', 'group' => 'healthcare'],
            ['key' => 'hc_facebook', 'value' => 'https://facebook.com/gnahealthcare', 'group' => 'healthcare'],
            ['key' => 'hc_hero_title', 'value' => 'Лекување во универзитетски и приватни болници во Турција', 'group' => 'healthcare'],
            ['key' => 'hc_hero_subtitle', 'value' => 'Решение за сите здравствени проблеми, навремена дијагностика и висок процент на успех во лекувањето. Побарај нe и добиј бесплатно второ мислење!', 'group' => 'healthcare'],
            ['key' => 'hc_about_text', 'value' => 'GNA Healthcare е дел од GlobalNetADV групацијата, посветена на обезбедување пристап до светски класа здравствена заштита во Турција за граѓаните на Северна Македонија.', 'group' => 'healthcare'],
        ];

        foreach ($settings as $setting) {
            Setting::create($setting);
        }
    }

    private function seedServices(): void
    {
        $services = [
            [
                'name' => 'Организација на настани',
                'color' => '#1a56db',
                'description' => 'Нудиме сеопфатна и професионална услуга за организирање на настани, од почетен концепт до комплетна реализација. Без разлика дали се работи за конференции, конгреси, семинари, работилници, тим билдинг или корпоративни и промотивни настани, ние обезбедуваме целосна логистичка, техничка и креативна поддршка.',
                'sort_order' => 0,
                'bullets' => [
                    'Концептуално планирање – Дефинирање на целите на настанот, избор на формат (конференции, конгреси, семинари, работилници, тим билдинг, корпоративни/промотивни настани)',
                    'Избор и координација на локација – Идентификување на соодветни локации, управување со резервации, простор и инфраструктура',
                    'Техничка и аудиовизуелна поддршка – Озвучување, микрофони, проекција, ЛЕД екрани, осветлување и техничко лице',
                    'Брендирање и визуелен идентитет – Банери, веб дизајн на настанот, печатени материјали, ИД беџови',
                    'Регистрација на учесници – Онлајн/физичка регистрација, листи на учесници, поддршка',
                    'Координација на говорници и спонзори – Комуникација, логистика, програмско усогласување',
                    'Кетеринг – Кафе паузи, коктели, работни ручеци, специјални барања',
                    'Протокол и оперативно водење – Следење на програмата, почитување на временска рамка',
                    'Финансиска и административна поддршка – Буџетирање, координација со добавувачи, фактурирање',
                    'Пост-настан поддршка – Детални извештаи, анализа на посетеност, евалуација',
                ],
            ],
            [
                'name' => 'Обуки',
                'color' => '#0891b2',
                'description' => 'Обезбедуваме високо квалитетни и професионално осмислени програми за обуки кои го подобруваат знаењето, вештините и професионалните компетенции. Програмите се развиени според актуелните стандарди и современите професионални потреби.',
                'sort_order' => 1,
                'bullets' => [
                    'Стручни и тематски обуки од различни области',
                    'Искусни инструктори со докажано професионално искуство',
                    'Структуриран и систематски образовен пристап',
                    'Обуки достапни во живо, онлајн или хибриден формат',
                    'Континуирана професионална едукација',
                    'Корпоративни и институционални обуки',
                    'Менаџмент, организација и деловна комуникација',
                    'Специјализирани програми и работилници',
                ],
            ],
            [
                'name' => 'Промотивни активности',
                'color' => '#7c3aed',
                'description' => 'Обезбедуваме сеопфатна промотивна и маркетинг поддршка низ целата територија на Северна Македонија. Располагаме со мрежа на координатори за ефикасна реализација во сите градови.',
                'sort_order' => 2,
                'bullets' => [
                    'Ангажирање на промотери, хостеси, модели и агенти за промоции',
                    'Дизајн и имплементација на кампањи за нови и постоечки производи',
                    'Организација на дегустации и презентации во малопродажни објекти',
                    'Дистрибуција на промотивни материјали (летоци, брошури, каталози, мостри)',
                    'Директен маркетинг кон дефинирани сегменти на потрошувачи',
                    'Поддршка на саеми и деловни настани',
                    'Логистичка координација вклучувајќи персонал, транспорт и материјали',
                    'Регионално и национално планирање за одржливо пазарно присуство',
                ],
            ],
            [
                'name' => 'Анкетирање и истражување',
                'color' => '#059669',
                'description' => 'Нудиме сеопфатни услуги за анкетирање и истражување кои вклучуваат собирање, обработка и теренска анализа на податоци со современи методологии. Нашиот тим го сочинуваат повеќе од 50 обучени анкетари и истражувачи.',
                'sort_order' => 3,
                'bullets' => [
                    'Теренско истражување и анкетни активности, собирање и обработка на податоци',
                    'Истражување на конкуренцијата и проценка на пазарот',
                    'Мониторинг на организациски структури и евалуација на продажна мрежа',
                    'Мистериозно купување за контрола на продажен персонал',
                    'Истражување на продажни канали и развој на нови пазари',
                    'Мерење на задоволство на клиенти и известување',
                    'Мониторинг на пазарот за спречување на фалсификување и нелегална продажба',
                ],
            ],
            [
                'name' => 'Креирање и развој на бренд',
                'color' => '#d97706',
                'description' => 'Креирањето на бренд е креативен процес кој вклучува многу важни чекори за дефинирање и позиционирање на брендови на пазарот.',
                'sort_order' => 4,
                'bullets' => [
                    'Дефинирање на визија и мисија – Утврдување на целите на брендот и вредноста за клиентите',
                    'Идентификација на целна публика – Разбирање на идеалните купувачи, нивните потреби и карактеристики',
                    'Анализа на пазарот и конкуренцијата – Евалуација на позиционирањето и можности за диференцијација',
                    'Креирање на уникатна вредност – Определување на она што го прави брендот различен',
                    'Развој на визуелен идентитет – Дизајн на лого, колор шеми, фонтови и конзистентна графика',
                    'Комуникациски глас и тон – Воспоставување на конзистентна порака на брендот',
                    'Позиционирање на бренд – Одлучување како клиентите треба да го перципираат брендот',
                    'Маркетинг стратегија – Избор на канали за градење свесност и ангажирање на публиката',
                ],
            ],
        ];

        foreach ($services as $serviceData) {
            $bullets = $serviceData['bullets'];
            unset($serviceData['bullets']);

            $service = Service::create($serviceData);

            foreach ($bullets as $i => $bulletText) {
                ServiceBullet::create([
                    'service_id' => $service->id,
                    'text' => $bulletText,
                    'sort_order' => $i,
                ]);
            }
        }
    }

    private function seedClinics(): void
    {
        $clinics = [
            ['name' => 'Поликлиника Медика', 'city' => 'Скопје', 'specialties' => 'Интерна медицина, Кардиологија', 'phone' => '02 312 1234', 'address' => 'ул. Водњанска 15'],
            ['name' => 'ПЗУ Неуромедика', 'city' => 'Скопје', 'specialties' => 'Неврологија, Неврохирургија', 'phone' => '02 322 5678', 'address' => 'бул. Партизански Одреди 42'],
            ['name' => 'Авицена Лабораторија', 'city' => 'Скопје', 'specialties' => 'Радиологија, Дијагностика', 'phone' => '02 311 9876', 'address' => 'ул. Кеј 13 Ноември 2'],
            ['name' => 'ПЗУ Ремедика', 'city' => 'Битола', 'specialties' => 'Ортопедија, Хирургија', 'phone' => '047 234 567', 'address' => 'ул. 1ви Мај 33'],
            ['name' => 'Центар за Онкологија', 'city' => 'Битола', 'specialties' => 'Онкологија', 'phone' => '047 222 111', 'address' => 'ул. Партизанска 18'],
            ['name' => 'ПЗУ ВитаМед', 'city' => 'Прилеп', 'specialties' => 'Урологија, Педијатрија', 'phone' => '048 412 345', 'address' => 'ул. Маршал Тито 88'],
            ['name' => 'МедиЦентар Тетово', 'city' => 'Тетово', 'specialties' => 'Интерна медицина, Радиологија', 'phone' => '044 334 567', 'address' => 'ул. Илинденска 22'],
            ['name' => 'ПЗУ Куманово Клиник', 'city' => 'Куманово', 'specialties' => 'Кардиологија, Хирургија', 'phone' => '031 421 678', 'address' => 'ул. 11 Октомври 5'],
            ['name' => 'Охрид МедГруп', 'city' => 'Охрид', 'specialties' => 'Педијатрија, Ин Витро', 'phone' => '046 251 234', 'address' => 'ул. Св. Климент 14'],
        ];

        foreach ($clinics as $clinic) {
            HcClinic::create($clinic);
        }
    }

    private function seedHospitals(): void
    {
        $hospitals = [
            [
                'name' => 'Медикал Парк',
                'city' => 'Истанбул',
                'description' => 'Една од најголемите приватни здравствени групации во Турција',
                'specialties' => 'Сите видови на трансплантации, Онкологија, Неврохирургија, Ортопедија',
                'active' => true,
            ],
            [
                'name' => 'Мемориал Болница',
                'city' => 'Истанбул',
                'description' => 'Меѓународно акредитирана болница со врвна технологија',
                'specialties' => 'Онкологија, Кардиологија, Неврологија, Радиологија',
                'active' => true,
            ],
            [
                'name' => 'Аџибадем',
                'city' => 'Истанбул/Анкара',
                'description' => 'Лидер во здравствена нега во регионот',
                'specialties' => 'Урологија, Нефрологија, Педијатрија, Хирургија',
                'active' => true,
            ],
            [
                'name' => 'Лив Болница',
                'city' => 'Истанбул',
                'description' => 'Специјализирана за комплексни хируршки интервенции',
                'specialties' => 'Ортопедија, Неврохирургија, Ин Витро оплодување',
                'active' => true,
            ],
        ];

        foreach ($hospitals as $hospital) {
            HcHospital::create($hospital);
        }
    }

    private function seedGallery(): void
    {
        // Event gallery - sample events
        $event1 = GalleryEvent::create([
            'name' => 'Конференција за дигитален маркетинг 2025',
            'category' => 'events',
            'date' => '2025-03-15',
            'location' => 'Скопје',
            'featured' => true,
            'show_on_home' => true,
        ]);

        for ($i = 1; $i <= 6; $i++) {
            GalleryImage::create([
                'gallery_event_id' => $event1->id,
                'path' => "https://globalnetadv.mk/wp-content/uploads/2025/05/image{$i}.webp",
                'is_cover' => $i === 1,
                'original_name' => "image{$i}.webp",
            ]);
        }

        $event2 = GalleryEvent::create([
            'name' => 'Корпоративен тим билдинг',
            'category' => 'events',
            'date' => '2025-04-20',
            'location' => 'Охрид',
            'featured' => false,
            'show_on_home' => true,
        ]);

        for ($i = 7; $i <= 12; $i++) {
            GalleryImage::create([
                'gallery_event_id' => $event2->id,
                'path' => "https://globalnetadv.mk/wp-content/uploads/2025/05/image{$i}.webp",
                'is_cover' => $i === 7,
                'original_name' => "image{$i}.webp",
            ]);
        }

        $event3 = GalleryEvent::create([
            'name' => 'Семинар за бизнис развој',
            'category' => 'events',
            'date' => '2025-05-10',
            'location' => 'Скопје',
            'featured' => false,
            'show_on_home' => false,
        ]);

        for ($i = 13; $i <= 20; $i++) {
            GalleryImage::create([
                'gallery_event_id' => $event3->id,
                'path' => "https://globalnetadv.mk/wp-content/uploads/2025/05/image{$i}.webp",
                'is_cover' => $i === 13,
                'original_name' => "image{$i}.webp",
            ]);
        }

        // Promotion gallery
        $promo1 = GalleryEvent::create([
            'name' => 'Промоција на нов производ',
            'category' => 'promotions',
            'date' => '2025-02-28',
            'location' => 'Скопје',
            'featured' => true,
            'show_on_home' => true,
        ]);

        for ($i = 1; $i <= 10; $i++) {
            GalleryImage::create([
                'gallery_event_id' => $promo1->id,
                'path' => "https://globalnetadv.mk/wp-content/uploads/2025/06/image{$i}.webp",
                'is_cover' => $i === 1,
                'original_name' => "image{$i}.webp",
            ]);
        }

        $promo2 = GalleryEvent::create([
            'name' => 'Дегустација и промоција во малопродажба',
            'category' => 'promotions',
            'date' => '2025-04-05',
            'location' => 'Битола',
            'featured' => false,
            'show_on_home' => false,
        ]);

        for ($i = 11; $i <= 20; $i++) {
            GalleryImage::create([
                'gallery_event_id' => $promo2->id,
                'path' => "https://globalnetadv.mk/wp-content/uploads/2025/06/image{$i}.webp",
                'is_cover' => $i === 11,
                'original_name' => "image{$i}.webp",
            ]);
        }
    }
}
