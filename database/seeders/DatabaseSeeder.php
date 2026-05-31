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

        // ── Service detail references (events/training/clients) ──
        $this->call(ServiceDetailsSeeder::class);

        // ── HC Clinics ──────────────────────────────────────────
        $this->seedClinics();

        // ── HC Hospitals ────────────────────────────────────────
        $this->seedHospitals();

        // ── Gallery Events & Images ─────────────────────────────
        // Real images live under storage/app/public/gallery — GallerySeeder
        // scans that folder, so fresh installs match the live gallery.
        $this->call(GallerySeeder::class);
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
            ['key' => 'stat_years', 'value' => '30+', 'group' => 'globalnet'],
            ['key' => 'stat_years_label', 'value' => 'Години искуство', 'group' => 'globalnet'],
            ['key' => 'stat_projects', 'value' => '500+', 'group' => 'globalnet'],
            ['key' => 'stat_projects_label', 'value' => 'Реализирани проекти', 'group' => 'globalnet'],
            ['key' => 'stat_surveyors', 'value' => '50+', 'group' => 'globalnet'],
            ['key' => 'stat_surveyors_label', 'value' => 'Обучени анкетари', 'group' => 'globalnet'],
            ['key' => 'stat_clients', 'value' => '100+', 'group' => 'globalnet'],
            ['key' => 'stat_clients_label', 'value' => 'Задоволни клиенти', 'group' => 'globalnet'],

            // GlobalNet — extra contact + page copy (managed from the "Содржина" editor)
            ['key' => 'email_2', 'value' => 'globalneta@gmail.com', 'group' => 'globalnet'],
            ['key' => 'working_hours', 'value' => 'Пон - Пет: 09:00 - 17:00', 'group' => 'globalnet'],
            ['key' => 'home_services_label', 'value' => 'ШТО РАБОТИМЕ', 'group' => 'globalnet'],
            ['key' => 'home_services_heading', 'value' => 'Комплетни маркетинг решенија', 'group' => 'globalnet'],
            ['key' => 'home_services_subtitle', 'value' => 'Од концепт до реализација, нудиме сеопфатни услуги прилагодени на вашите потреби.', 'group' => 'globalnet'],
            ['key' => 'home_about_label', 'value' => 'ЗА НАС', 'group' => 'globalnet'],
            ['key' => 'home_about_heading', 'value' => 'Вашиот партнер за маркетинг решенија', 'group' => 'globalnet'],
            ['key' => 'about_text_2', 'value' => 'Нашиот тим го сочинуваат повеќе од 50 обучени професионалци кои овозможуваат брза и прецизна реализација на проекти на целата територија на Северна Македонија.', 'group' => 'globalnet'],
            ['key' => 'home_cta_title', 'value' => 'Контактирајте нè и заедно ќе креираме искуства кои оставаат впечаток.', 'group' => 'globalnet'],
            ['key' => 'home_cta_subtitle', 'value' => 'Подгответе го вашиот бизнис за успех со нашите професионални маркетинг решенија.', 'group' => 'globalnet'],
            ['key' => 'home_gallery_heading', 'value' => 'Од нашата галерија', 'group' => 'globalnet'],
            ['key' => 'services_hero_title', 'value' => 'Што Работиме', 'group' => 'globalnet'],
            ['key' => 'services_hero_subtitle', 'value' => 'Од организација на конференции и корпоративни настани, преку промотивни активности и истражување на пазарот, до креативен развој на бренд.', 'group' => 'globalnet'],
            ['key' => 'services_cta_title', 'value' => 'Подгответе го вашиот бизнис за успех', 'group' => 'globalnet'],
            ['key' => 'contact_hero_title', 'value' => 'Контакт', 'group' => 'globalnet'],
            ['key' => 'contact_hero_subtitle', 'value' => 'Контактирајте нè и заедно ќе креираме искуства кои оставаат впечаток', 'group' => 'globalnet'],
            ['key' => 'contact_form_name_label', 'value' => 'Име и Презиме', 'group' => 'globalnet'],
            ['key' => 'contact_form_name_placeholder', 'value' => 'Вашето име и презиме', 'group' => 'globalnet'],
            ['key' => 'contact_form_email_label', 'value' => 'Е-пошта', 'group' => 'globalnet'],
            ['key' => 'contact_form_email_placeholder', 'value' => 'vashata@eposhta.mk', 'group' => 'globalnet'],
            ['key' => 'contact_form_phone_label', 'value' => 'Телефон', 'group' => 'globalnet'],
            ['key' => 'contact_form_phone_placeholder', 'value' => '07X XXX XXX', 'group' => 'globalnet'],
            ['key' => 'contact_form_subject_label', 'value' => 'Тема', 'group' => 'globalnet'],
            ['key' => 'contact_form_subject_placeholder', 'value' => 'Тема на пораката', 'group' => 'globalnet'],
            ['key' => 'contact_form_message_label', 'value' => 'Порака', 'group' => 'globalnet'],
            ['key' => 'contact_form_message_placeholder', 'value' => 'Напишете ја вашата порака...', 'group' => 'globalnet'],
            ['key' => 'contact_form_submit_label', 'value' => 'Испратете порака', 'group' => 'globalnet'],
            ['key' => 'footer_description', 'value' => 'Агенција за маркетинг и адвертајзинг со повеќе од 30 години искуство во креирање на впечатливи настани, успешни промоции и препознатливи брендови.', 'group' => 'globalnet'],

            // SEO + Contact map (managed from "Подесувања")
            ['key' => 'seo_description', 'value' => 'Global Net ADV - Агенција за маркетинг и адвертајзинг во Скопје. Организација на настани, обуки, промотивни активности, истражување на пазарот и развој на бренд.', 'group' => 'globalnet'],
            ['key' => 'seo_og_image', 'value' => '', 'group' => 'globalnet'],
            ['key' => 'contact_map_embed', 'value' => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2965.5!2d21.4308!3d41.9973!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x135415b4a4c5c5c5%3A0x1234567890abcdef!2sBagdadska+36a%2C+Skopje!5e0!3m2!1sen!2smk!4v1700000000000!5m2!1sen!2smk', 'group' => 'globalnet'],

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
            ['name' => 'ПЗУ Поликлиника Медика', 'city' => 'Скопје', 'specialties' => 'Интерна медицина, Кардиологија', 'phone' => '02 312 1234', 'address' => 'ул. Водњанска 15'],
            ['name' => 'ПЗУ Неуромедика', 'city' => 'Скопје', 'specialties' => 'Неврологија, Неврохирургија', 'phone' => '02 322 5678', 'address' => 'бул. Партизански Одреди 42'],
            ['name' => 'ПЗУ Авицена Лабораторија', 'city' => 'Скопје', 'specialties' => 'Радиологија, Дијагностика', 'phone' => '02 311 9876', 'address' => 'ул. Кеј 13 Ноември 2'],
            ['name' => 'ПЗУ Ремедика', 'city' => 'Битола', 'specialties' => 'Ортопедија, Хирургија', 'phone' => '047 234 567', 'address' => 'ул. 1ви Мај 33'],
            ['name' => 'ПЗУ Центар за Онкологија', 'city' => 'Битола', 'specialties' => 'Онкологија', 'phone' => '047 222 111', 'address' => 'ул. Партизанска 18'],
            ['name' => 'ПЗУ ВитаМед', 'city' => 'Прилеп', 'specialties' => 'Урологија, Педијатрија', 'phone' => '048 412 345', 'address' => 'ул. Маршал Тито 88'],
            ['name' => 'ПЗУ МедиЦентар Тетово', 'city' => 'Тетово', 'specialties' => 'Интерна медицина, Радиологија', 'phone' => '044 334 567', 'address' => 'ул. Илинденска 22'],
            ['name' => 'ПЗУ Куманово Клиник', 'city' => 'Куманово', 'specialties' => 'Кардиологија, Хирургија', 'phone' => '031 421 678', 'address' => 'ул. 11 Октомври 5'],
            ['name' => 'ПЗУ Охрид МедГруп', 'city' => 'Охрид', 'specialties' => 'Педијатрија, Ин Витро', 'phone' => '046 251 234', 'address' => 'ул. Св. Климент 14'],
            ['name' => 'ПЗУ Sanomak', 'city' => 'Велес', 'specialties' => '', 'phone' => '', 'address' => ''],
            ['name' => 'ПЗУ dr Svetle', 'city' => 'Тетово', 'specialties' => '', 'phone' => '', 'address' => ''],
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

}
