<?php

namespace Database\Seeders;

use App\Models\EventSession;
use App\Models\Presentation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates a sample presentation tied to an event session, using
 * Global Net ADV's brand content from globalnetadv.mk.
 *
 * Usage:
 *   php artisan db:seed --class=SamplePresentationSeeder
 *
 *   # Target a specific session:
 *   EVENT_ID=4 SESSION_ID=39 php artisan db:seed --class=SamplePresentationSeeder
 *
 * If SESSION_ID is not provided (or doesn't exist) the seeder uses the
 * latest session in the DB.
 */
class SamplePresentationSeeder extends Seeder
{
    public function run(): void
    {
        $sessionId = (int) (env('SESSION_ID') ?: 0);
        $eventId   = (int) (env('EVENT_ID') ?: 0);

        $session = null;
        if ($sessionId) {
            $session = EventSession::with('event')->find($sessionId);
            if ($session && $eventId && $session->event_id !== $eventId) {
                $this->command->warn("Session {$sessionId} belongs to event {$session->event_id}, not {$eventId}. Continuing with the actual session.");
            }
        }
        if (!$session) {
            $session = EventSession::with('event')->latest('id')->first();
        }
        if (!$session) {
            $this->command->error('No event sessions found. Create at least one event + session before seeding presentations.');
            return;
        }

        $this->command->info("Creating sample presentation for session {$session->id} ({$session->name}) of event '{$session->event?->name}'.");

        $title = 'Global Net ADV — Маркетинг што носи резултати';
        $subtitle = '30+ години искуство во настани, промоции и развој на бренд';

        $content = <<<MD
## За нас

**Global Net ADV** е една од најискусните маркетинг и адвертајзинг агенции
во Република Северна Македонија. Веќе **повеќе од 30 години** градиме
кампањи, организираме настани и помагаме на брендови да го најдат
своето место на пазарот — со креативност, прецизност и резултати.

Нашиот пристап е едноставен: го разбираме вашиот бизнис, го дефинираме
вистинскиот публикум и реализираме решенија кои го мерат успехот не во
импресии, туку во влијание.

---

## Нашите сили

| 30+ | 500+ | 50+ | 100+ |
|:---:|:---:|:---:|:---:|
| **години искуство** | **реализирани проекти** | **обучени анкетари** | **задоволни клиенти** |

---

## Што нудиме

### 🎤 Организација на настани
Сеопфатна и професионална услуга — од почетен концепт до целосна
реализација. Конференции, симпозиуми, корпоративни настани, лансирања
на производи и тимбилдинзи. Се грижиме за секој детал, така што вие да
се фокусирате на вашата порака.

### 🎓 Обуки
Високо квалитетни програми за обуки дизајнирани да го подобрат
знаењето и професионалните компетенции на вашиот тим. Од soft skills
до техничка експертиза, со предавачи од земјата и регионот.

### 📢 Промотивни активности
Професионална поддршка за презентација на производи, зголемување на
продажба и зајакнување на пазарното присуство. Полски промоции, BTL
кампањи, sampling, активации на штанд.

### 📊 Анкетирање и истражување
Точни, сигурни и релевантни податоци за информирани бизнис одлуки.
Квантитативни и квалитативни истражувања, тестирање на производи,
mystery shopping и анализа на конкуренција.

### ✨ Креирање и развој на бренд
Креативен процес кој вклучува дефинирање и позиционирање на брендот
на пазарот. Стратегија, визуелен идентитет, копи и комуникациски
концепт — сè по мерка на вашиот бизнис.

---

## Зошто Global Net ADV

- **Долгогодишно искуство** — 30+ години работа во маркетинг и адвертајзинг.
- **Локална експертиза** — добро го познаваме македонскиот пазар и потрошувач.
- **Прилагодени решенија** — секој проект се изработува на нула, по ваша мерка.
- **Одговорен пристап** — едноставна комуникација, јасни рокови, мерливи KPI.
- **Дискретност и доверба** — соработуваме со компании кои бараат сериозен партнер.

---

## Како работиме

1. **Слушаме** — ги разбираме вашите цели, ограничувања и публикум.
2. **Предлагаме** — конкретно решение со јасен концепт, динамика и буџет.
3. **Реализираме** — целосно водиме од планирање до изведба, со вас на секој чекор.
4. **Мериме** — извештаи, статистика и фидбек за следните одлуки.

---

## Контакт

📍 ул. Багдадска 36а 2/8, 1000 Скопје
📞 02 322 41 41 · 071 317 377
✉️ globalnetadv@globalnetadv.mk
🌐 [globalnetadv.mk](https://globalnetadv.mk)

> *Нашите проекти се не само спроведени — туку и запомнети.*
MD;

        $heroImage = 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?auto=format&fit=crop&w=1600&q=80';

        $gallery = [
            'https://images.unsplash.com/photo-1505373877841-8d25f7d46678?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1591115765373-5207764f72e7?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1492684223066-81342ee5ff30?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1559223607-a43c990c692c?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1511795409834-ef04bbd61622?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1556761175-b413da4baf72?auto=format&fit=crop&w=1200&q=80',
        ];

        $existing = Presentation::where('event_session_id', $session->id)
            ->where('title', $title)
            ->first();

        $payload = [
            'event_session_id' => $session->id,
            'title' => $title,
            'subtitle' => $subtitle,
            'hero_image' => $heroImage,
            'content' => $content,
            'gallery' => $gallery,
            'cta_text' => 'Контактирајте нè',
            'cta_url' => 'https://globalnetadv.mk/contact',
            'is_active' => true,
            'sort_order' => 0,
        ];

        if ($existing) {
            $existing->update($payload);
            $p = $existing;
            $this->command->info("Updated existing presentation #{$p->id}.");
        } else {
            $payload['qr_token'] = (string) Str::uuid();
            $p = Presentation::create($payload);
            $this->command->info("Created presentation #{$p->id}.");
        }

        $this->command->info("Public URL: /presentation/{$p->qr_token}");
        $this->command->info("Admin URL:  /admin/att-presentations?event={$session->event_id}&session={$session->id}");
    }
}
