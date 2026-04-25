<?php

namespace Database\Seeders;

use App\Models\Evaluation;
use App\Models\EvaluationAnswer;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationResponse;
use App\Models\EventSession;
use App\Models\Presentation;
use Illuminate\Database\Seeder;

class DemoEvalsPresSeeder extends Seeder
{
    private array $maleNames = ['Александар','Марко','Стефан','Никола','Давид','Филип','Иван','Димитар','Горан','Дарко','Бојан','Владимир','Петар','Дејан'];
    private array $femaleNames = ['Ана','Марија','Елена','Ивана','Сара','Тамара','Јована','Наташа','Катерина','Маја','Билјана','Весна','Драгана'];
    private array $maleSurnames = ['Стојановски','Димитровски','Петровски','Николовски','Ивановски','Трајковски','Јовановски','Андоновски'];
    private array $femaleSurnames = ['Стојановска','Димитровска','Петровска','Николовска','Ивановска','Трајковска','Јовановска'];
    private array $cities = ['Скопје','Битола','Куманово','Прилеп','Тетово','Охрид','Велес','Штип'];

    public function run(): void
    {
        $sessions = EventSession::with('event')->get();
        if ($sessions->isEmpty()) {
            $this->command->error('Нема сесии. Создај настан и сесии прво.');
            return;
        }

        $this->command->info("Сидирам евалуации и презентации за {$sessions->count()} сесии...");

        foreach ($sessions as $i => $session) {
            $this->seedEvaluationsForSession($session, $i);
            $this->seedPresentationsForSession($session, $i);
        }

        $this->command->info('Готово!');
    }

    private function seedEvaluationsForSession(EventSession $session, int $idx): void
    {
        // Create 2 evaluations per session
        $eval1 = Evaluation::create([
            'event_session_id' => $session->id,
            'title' => 'Задоволство од ' . $session->name,
            'description' => 'Ве молиме да ја оцените оваа сесија. Вашите одговори ни помагаат да ги подобруваме идните настани.',
            'anonymity_mode' => 'both',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $q1 = EvaluationQuestion::create([
            'evaluation_id' => $eval1->id,
            'question_text' => 'Како би ја оцениле презентацијата?',
            'type' => 'rating',
            'options' => ['scale' => 5],
            'required' => true,
            'sort_order' => 0,
        ]);

        $q2 = EvaluationQuestion::create([
            'evaluation_id' => $eval1->id,
            'question_text' => 'Што ви се допадна најмногу?',
            'type' => 'radio',
            'options' => ['Содржината', 'Презентерот', 'Локацијата', 'Кетерингот', 'Networking'],
            'required' => true,
            'sort_order' => 1,
        ]);

        $q3 = EvaluationQuestion::create([
            'evaluation_id' => $eval1->id,
            'question_text' => 'За кои теми би сакале повеќе обуки?',
            'type' => 'checkbox',
            'options' => ['Маркетинг', 'Менаџмент', 'Финансии', 'Технологија', 'Лидерство', 'Продажба'],
            'required' => false,
            'sort_order' => 2,
        ]);

        $q4 = EvaluationQuestion::create([
            'evaluation_id' => $eval1->id,
            'question_text' => 'Имате ли коментари или сугестии?',
            'type' => 'textarea',
            'options' => null,
            'required' => false,
            'sort_order' => 3,
        ]);

        // Generate 25-60 random responses
        $numResponses = rand(25, 60);
        $sampleComments = [
            'Одличен настан, бевме многу задоволни!',
            'Презентерот беше многу професионален.',
            'Сакам повеќе вакви настани во иднина.',
            'Содржината беше релевантна и корисна.',
            'Локацијата беше одлично избрана.',
            'Networking-от беше топ.',
            'Малку повеќе време за прашања би било добро.',
            'Кетерингот можеше да биде подобар.',
            'Темите беа интересни и ажурирани.',
            'Препорачувам на сите колеги.',
        ];

        for ($r = 0; $r < $numResponses; $r++) {
            $isAnon = rand(0, 1) === 1;
            $isFemale = rand(0, 1) === 1;
            $response = EvaluationResponse::create([
                'evaluation_id' => $eval1->id,
                'first_name' => $isAnon ? null : ($isFemale ? $this->femaleNames[array_rand($this->femaleNames)] : $this->maleNames[array_rand($this->maleNames)]),
                'last_name' => $isAnon ? null : ($isFemale ? $this->femaleSurnames[array_rand($this->femaleSurnames)] : $this->maleSurnames[array_rand($this->maleSurnames)]),
                'email' => $isAnon ? null : 'demo' . $r . '_' . $idx . '@test.local',
                'phone' => $isAnon ? null : '07' . rand(0, 9) . str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT),
                'is_anonymous' => $isAnon,
                'submitted_at' => now()->subDays(rand(0, 30))->subHours(rand(0, 23)),
            ]);

            // Rating answer (skewed toward 4-5)
            $ratingDist = [1, 2, 3, 3, 4, 4, 4, 5, 5, 5];
            EvaluationAnswer::create([
                'evaluation_response_id' => $response->id,
                'evaluation_question_id' => $q1->id,
                'answer_value' => (string) $ratingDist[array_rand($ratingDist)],
            ]);

            // Radio
            $radioOptions = ['Содржината', 'Презентерот', 'Локацијата', 'Кетерингот', 'Networking'];
            EvaluationAnswer::create([
                'evaluation_response_id' => $response->id,
                'evaluation_question_id' => $q2->id,
                'answer_value' => $radioOptions[array_rand($radioOptions)],
            ]);

            // Checkbox (1-3 options)
            $checkboxOptions = ['Маркетинг', 'Менаџмент', 'Финансии', 'Технологија', 'Лидерство', 'Продажба'];
            shuffle($checkboxOptions);
            $picked = array_slice($checkboxOptions, 0, rand(1, 3));
            EvaluationAnswer::create([
                'evaluation_response_id' => $response->id,
                'evaluation_question_id' => $q3->id,
                'answer_value' => json_encode(array_values($picked)),
            ]);

            // Textarea (50% chance of comment)
            if (rand(0, 1) === 1) {
                EvaluationAnswer::create([
                    'evaluation_response_id' => $response->id,
                    'evaluation_question_id' => $q4->id,
                    'answer_value' => $sampleComments[array_rand($sampleComments)],
                ]);
            }
        }

        // Second eval — empty (no questions, no responses) for "fresh" state
        if ($idx % 3 === 0) {
            Evaluation::create([
                'event_session_id' => $session->id,
                'title' => 'Брза анкета',
                'description' => null,
                'anonymity_mode' => 'identified',
                'is_active' => false,
                'sort_order' => 1,
            ]);
        }
    }

    private function seedPresentationsForSession(EventSession $session, int $idx): void
    {
        $samples = [
            [
                'title' => 'GNA Healthcare',
                'subtitle' => 'Лекување во универзитетски и приватни болници во Турција',
                'content' => "# За GNA Healthcare\n\nGNA Healthcare е дел од GlobalNetADV групацијата. Овозможуваме пристап до **светски класа** здравствена заштита во Турција за граѓаните на Северна Македонија.\n\n## Што нудиме\n\n- Лекување во универзитетски и приватни болници\n- Бесплатно второ мислење\n- Координација на патот, престој и логистика\n- Тим од стручни консултанти\n\n## Зошто Турција?\n\nТурција има едни од најмодерните болници во Европа со стапка на успех на хируршки интервенции над **96%**. Цените се до 3 пати пониски отколку во западноевропските клиники.\n\n> \"Решение за сите здравствени проблеми, навремена дијагностика и висок процент на успех во лекувањето.\"",
                'cta_text' => 'Закажи бесплатна консултација',
                'cta_url' => 'https://panacea.mk',
                'hero_image' => 'https://images.unsplash.com/photo-1538108149393-fbbd81895907?w=1600&q=80',
                'gallery' => [
                    'https://images.unsplash.com/photo-1551190822-a9333d879b1f?w=800&q=80',
                    'https://images.unsplash.com/photo-1666214280557-f1b5022eb634?w=800&q=80',
                    'https://images.unsplash.com/photo-1579684385127-1ef15d508118?w=800&q=80',
                    'https://images.unsplash.com/photo-1584982751601-97dcc096659c?w=800&q=80',
                    'https://images.unsplash.com/photo-1631815588090-d4bfec5b1ccb?w=800&q=80',
                    'https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?w=800&q=80',
                ],
            ],
            [
                'title' => 'CrossMatchNet',
                'subtitle' => 'Платформа за поврзување на бизниси',
                'content' => "# CrossMatchNet\n\nИновативна **B2B платформа** која ги поврзува компаниите од Северна Македонија со партнери од регионот и Европа.\n\n## Главни функции\n\n- Профил на компанија со целосни деловни информации\n- Алгоритам за интелигентно match-увување на партнери\n- Безбедна комуникација и документација\n- Аналитика и извештаи\n\n## За кого е?\n\n1. Извозници кои бараат нови пазари\n2. Производители кои бараат добавувачи\n3. Дистрибутери кои бараат брендови\n4. Стартапи кои бараат инвеститори",
                'cta_text' => 'Регистрирај ја твојата фирма',
                'cta_url' => 'https://crossmatchnet.com',
                'hero_image' => 'https://images.unsplash.com/photo-1556761175-5973dc0f32e7?w=1600&q=80',
                'gallery' => [
                    'https://images.unsplash.com/photo-1521737711867-e3b97375f902?w=800&q=80',
                    'https://images.unsplash.com/photo-1552664730-d307ca884978?w=800&q=80',
                    'https://images.unsplash.com/photo-1542744173-8e7e53415bb0?w=800&q=80',
                    'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?w=800&q=80',
                ],
            ],
            [
                'title' => 'GNA E-Shop',
                'subtitle' => 'Онлајн продавница за маркетинг материјали',
                'content' => "# GNA E-Shop\n\nКомплетна онлајн платформа за нарачка на **промотивни и маркетинг материјали**.\n\n## Категории\n\n- Печатени материјали (флаери, брошури, каталози)\n- Промотивни облеки (маици, капи, јакни)\n- Гифт сетови за корпоративни клиенти\n- Банери и роли-апи\n- Дигитален дизајн\n\n## Зошто GNA E-Shop?\n\n**Брза изработка.** Просечно време на испорака: 5-7 работни дена.\n\n**Квалитет.** Работиме само со проверени добавувачи и материјали.\n\n**Cena.** Конкурентни цени за веле-производство.",
                'cta_text' => 'Разгледај каталог',
                'cta_url' => 'https://shop.globalnetadv.mk',
                'hero_image' => 'https://images.unsplash.com/photo-1483985988355-763728e1935b?w=1600&q=80',
                'gallery' => [
                    'https://images.unsplash.com/photo-1556742049-0cfed4f6a45d?w=800&q=80',
                    'https://images.unsplash.com/photo-1607082348824-0a96f2a4b9da?w=800&q=80',
                    'https://images.unsplash.com/photo-1622618991746-fe6004db3a47?w=800&q=80',
                    'https://images.unsplash.com/photo-1542038784456-1ea8e935640e?w=800&q=80',
                    'https://images.unsplash.com/photo-1605000797499-95a51c5269ae?w=800&q=80',
                ],
            ],
            [
                'title' => 'PANACEA.mk',
                'subtitle' => 'Online здравствена платформа',
                'content' => "# PANACEA.mk\n\nДигитална платформа за здравствени услуги. Поврзете се со лекари, ординации и болници во Македонија и регионот.\n\n## Карактеристики\n\n- Онлајн закажување термини\n- Дигитална документација\n- Втор стручен совет\n- Проценка на трошоци\n\n## Партнери\n\nСоработуваме со **повеќе од 50 ординации** во цела Македонија и водечки болници во Истанбул и Анкара.",
                'cta_text' => 'Закажи термин',
                'cta_url' => 'https://panacea.mk',
                'hero_image' => 'https://images.unsplash.com/photo-1576091160550-2173dba999ef?w=1600&q=80',
                'gallery' => [
                    'https://images.unsplash.com/photo-1559757148-5c350d0d3c56?w=800&q=80',
                    'https://images.unsplash.com/photo-1505751172876-fa1923c5c528?w=800&q=80',
                    'https://images.unsplash.com/photo-1612531822916-aff03d04bd2a?w=800&q=80',
                    'https://images.unsplash.com/photo-1666214277657-2cd6e1f3a8e3?w=800&q=80',
                ],
            ],
            [
                'title' => 'Global Net ADV',
                'subtitle' => '30+ години искуство во маркетинг и адвертајзинг',
                'content' => "# За Global Net ADV\n\nАгенција за маркетинг и адвертајзинг со седиште во Скопје. Со повеќе од **30 години искуство**, нудиме комплетни решенија за:\n\n- Организација на настани (конференции, конгреси)\n- Промотивни активности\n- Стручни обуки\n- Истражување на пазарот\n- Развој и позиционирање на бренд\n\n## Бројки\n\n- **500+** реализирани проекти\n- **100+** задоволни клиенти\n- **50+** обучени анкетари и промотери\n- **30+** години искуство\n\n## Тим\n\nНашиот тим го сочинуваат професионалци со долгогодишно искуство во маркетинг, менаџмент и логистика на настани.",
                'cta_text' => 'Контактирај нè',
                'cta_url' => 'https://globalnetadv.mk/contact',
                'hero_image' => 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=1600&q=80',
                'gallery' => [
                    'https://images.unsplash.com/photo-1505373877841-8d25f7d46678?w=800&q=80',
                    'https://images.unsplash.com/photo-1511795409834-ef04bbd61622?w=800&q=80',
                    'https://images.unsplash.com/photo-1559223607-a43f990c692c?w=800&q=80',
                    'https://images.unsplash.com/photo-1591115765373-5207764f72e4?w=800&q=80',
                    'https://images.unsplash.com/photo-1556761175-b413da4baf72?w=800&q=80',
                    'https://images.unsplash.com/photo-1517245386807-bb43f82c33c4?w=800&q=80',
                ],
            ],
        ];

        $sample = $samples[$idx % count($samples)];

        Presentation::create([
            'event_session_id' => $session->id,
            'title' => $sample['title'],
            'subtitle' => $sample['subtitle'],
            'content' => $sample['content'],
            'cta_text' => $sample['cta_text'],
            'cta_url' => $sample['cta_url'],
            'hero_image' => $sample['hero_image'] ?? null,
            'gallery' => $sample['gallery'] ?? null,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        // Some sessions get an extra inactive presentation as draft
        if ($idx % 4 === 0) {
            Presentation::create([
                'event_session_id' => $session->id,
                'title' => $sample['title'] . ' (draft)',
                'subtitle' => 'Работна верзија',
                'content' => "# Во подготовка\n\nОвој материјал е во подготовка.",
                'is_active' => false,
                'sort_order' => 1,
            ]);
        }
    }
}
