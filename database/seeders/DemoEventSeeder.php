<?php

namespace Database\Seeders;

use App\Models\EventAttendance;
use App\Models\EventAttendee;
use App\Models\EventSession;
use Illuminate\Database\Seeder;

class DemoEventSeeder extends Seeder
{
    private const EVENT_ID = 4;
    private const ATTENDEE_COUNT = 150;

    private array $firstNamesMale = [
        'Александар', 'Марко', 'Стефан', 'Никола', 'Давид', 'Филип', 'Иван', 'Димитар',
        'Горан', 'Дарко', 'Бојан', 'Владимир', 'Петар', 'Дејан', 'Зоран', 'Методи',
        'Кирил', 'Тони', 'Борче', 'Ристо', 'Драган', 'Игор', 'Васко', 'Ненад',
        'Сашо', 'Благоја', 'Љупчо', 'Томе', 'Златко', 'Миле',
    ];

    private array $firstNamesFemale = [
        'Ана', 'Марија', 'Елена', 'Ивана', 'Сара', 'Тамара', 'Јована', 'Наташа',
        'Катерина', 'Маја', 'Билјана', 'Весна', 'Драгана', 'Сузана', 'Оливера', 'Снежана',
        'Ангела', 'Милена', 'Виолета', 'Ленче', 'Данијела', 'Емилија', 'Славица', 'Гордана',
        'Јулија', 'Магдалена', 'Радица', 'Лидија', 'Светлана', 'Валентина',
    ];

    private array $lastNamesMale = [
        'Стојановски', 'Димитровски', 'Петровски', 'Николовски', 'Ивановски', 'Трајковски',
        'Јовановски', 'Андоновски', 'Георгиевски', 'Костовски', 'Миловски', 'Тодоровски',
        'Атанасовски', 'Спасовски', 'Илиевски', 'Ристовски', 'Павловски', 'Велковски',
        'Христовски', 'Здравковски', 'Мирчевски', 'Јанковски', 'Стефановски', 'Василевски',
    ];

    private array $lastNamesFemale = [
        'Стојановска', 'Димитровска', 'Петровска', 'Николовска', 'Ивановска', 'Трајковска',
        'Јовановска', 'Андоновска', 'Георгиевска', 'Костовска', 'Миловска', 'Тодоровска',
        'Атанасовска', 'Спасовска', 'Илиевска', 'Ристовска', 'Павловска', 'Велковска',
        'Христовска', 'Здравковска', 'Мирчевска', 'Јанковска', 'Стефановска', 'Василевска',
    ];

    private array $cities = [
        'Скопје', 'Битола', 'Куманово', 'Прилеп', 'Тетово', 'Охрид', 'Велес',
        'Штип', 'Струмица', 'Кавадарци', 'Гостивар', 'Кочани', 'Кичево', 'Струга',
        'Радовиш', 'Гевгелија', 'Дебар', 'Свети Николе', 'Неготино', 'Берово',
    ];

    public function run(): void
    {
        $sessions = EventSession::where('event_id', self::EVENT_ID)->get();

        if ($sessions->isEmpty()) {
            $this->command->error('No sessions found for event #' . self::EVENT_ID);
            return;
        }

        $this->command->info("Seeding event #" . self::EVENT_ID . " with " . self::ATTENDEE_COUNT . " attendees across {$sessions->count()} sessions...");

        $attendees = [];

        // Create attendees
        for ($i = 0; $i < self::ATTENDEE_COUNT; $i++) {
            $isFemale = rand(0, 1) === 1;
            $firstName = $isFemale
                ? $this->firstNamesFemale[array_rand($this->firstNamesFemale)]
                : $this->firstNamesMale[array_rand($this->firstNamesMale)];
            $lastName = $isFemale
                ? $this->lastNamesFemale[array_rand($this->lastNamesFemale)]
                : $this->lastNamesMale[array_rand($this->lastNamesMale)];

            $attendee = EventAttendee::create([
                'first_name'     => $firstName,
                'last_name'      => $lastName,
                'email'          => "demo.{$i}." . time() % 100000 . '@seed.test',
                'city'           => $this->cities[array_rand($this->cities)],
                'license_number' => rand(0, 3) === 0 ? 'ЛК-' . rand(1000, 9999) : null,
            ]);

            $attendees[] = $attendee;
        }

        $this->command->info("Created " . count($attendees) . " attendees.");

        // Assign each attendee to 1–6 random sessions
        $totalCheckins = 0;

        foreach ($attendees as $attendee) {
            $numSessions = rand(1, 6);
            $picked = $sessions->random(min($numSessions, $sessions->count()));

            foreach ($picked as $session) {
                // Random check-in time within the event's date range
                $daysOffset = rand(0, 180);
                $checkedInAt = now()->subDays(rand(0, $daysOffset))->setTime(rand(8, 18), rand(0, 59));

                EventAttendance::create([
                    'event_session_id'  => $session->id,
                    'event_attendee_id' => $attendee->id,
                    'checked_in_at'     => $checkedInAt,
                    'phone'             => '07' . rand(0, 9) . rand(100, 999) . rand(100, 999),
                ]);

                $totalCheckins++;
            }
        }

        $this->command->info("Created {$totalCheckins} check-in records. Done!");
    }
}
