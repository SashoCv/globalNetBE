<?php

/**
 * Обуки (teacher training programs).
 */
return [
    // Fuzzy matching (normalised Levenshtein on the ASCII key); below this a
    // row needs a manual choice.
    'school_match_threshold' => (float) env('EDU_SCHOOL_MATCH_THRESHOLD', 0.85),
    'trainer_match_threshold' => (float) env('EDU_TRAINER_MATCH_THRESHOLD', 0.85),

    // Uploaded workbooks and their previews (local disk, storage/app/private).
    'imports_disk' => 'local',
    'imports_dir' => 'edu-imports',

    // Master-data workbook import.
    'import' => [
        // Language of a school whose language cell is empty (client decision:
        // Macedonian). Applied to new schools and to schools whose language
        // is still unknown, never over a language set in the app. null keeps
        // such schools "unknown" ([]).
        'school_default_language' => env('EDU_SCHOOL_DEFAULT_LANGUAGE', 'mk'),
    ],

    // Language a group is formed in, per participant language: Turkish
    // participants join Macedonian groups by default (shown as "(1+1т)").
    'default_language_map' => ['mk' => 'mk', 'sq' => 'sq', 'tr' => 'mk'],

    // Multi-day trainings skip Saturdays and Sundays.
    'skip_weekends' => true,

    // Session times when neither the request nor the program gives them (П12).
    'default_session_times' => ['08:30', '13:30'],

    // Longest period GET /edu-schedule returns at once, in days.
    'schedule_max_range_days' => 400,

    // Trainer matching (plan §5.3; open questions П4, П5, П13). Each rule is
    // either hard (the trainer is not eligible; assigning needs "force") or
    // soft (the trainer stays eligible with the penalty below).
    //   hub:      'strict' | 'suggest'  — the group's centre is in the trainer's matrix
    //   topic:    'strict' | 'suggest'  — the trainer is on the program topic's list
    //   language: 'strict' | 'suggest'  — the group's language is one of the trainer's
    //   conflict: 'block'  | 'warn'     — another group of the trainer overlaps in time
    // A trainer with no matrix row that applies falls back to the centre of
    // their home municipality plus the centres of its region.
    'trainer_rules' => [
        'hub' => env('EDU_TRAINER_HUB_RULE', 'strict'),
        'topic' => env('EDU_TRAINER_TOPIC_RULE', 'strict'),
        'language' => env('EDU_TRAINER_LANGUAGE_RULE', 'strict'),
        'conflict' => env('EDU_TRAINER_CONFLICT_RULE', 'block'),
        'fallback_region_hubs' => true,
    ],

    // Candidate score: higher is better; ties go alphabetically.
    'trainer_scores' => [
        'home_hub' => 3,           // the trainer lives in the group's centre area
        'home_region' => 2,        // ... or in the same region
        'per_group' => -1,         // each group the trainer already has in the program
        'language_unknown' => -2,  // the trainer's languages are not known
        // Penalties for problems that a 'suggest' / 'warn' rule makes soft.
        'no_hub' => -3,
        'not_in_topic' => -3,
        'language_mismatch' => -5,
        'conflict' => -10,
    ],

    // Regions whose undersized groups may be merged (suggestions only).
    'zone_adjacency' => [
        ['Пелагониски', 'Југозападен'],
        ['Вардарски', 'Скопски'],
        ['Источен', 'Југоисточен'],
        ['Вардарски', 'Источен'],
        ['Вардарски', 'Југоисточен'],
        ['Полошки', 'Скопски'],
        ['Полошки', 'Југозападен'],
        ['Североисточен', 'Скопски'],
        ['Североисточен', 'Источен'],
        ['Пелагониски', 'Вардарски'],
    ],

    // Training centres that sit next to each other, by municipality name.
    // Auto-grouping prefers putting neighbours into the same group.
    'hub_adjacency' => [
        ['Велес', 'Свети Николе'],
        ['Свети Николе', 'Штип'],
        ['Велес', 'Неготино'],
        ['Кавадарци', 'Неготино'],
        ['Штип', 'Кочани'],
        ['Кочани', 'Делчево'],
        ['Штип', 'Струмица'],
        ['Струмица', 'Валандово'],
        ['Валандово', 'Гевгелија'],
        ['Тетово', 'Гостивар'],
        ['Гостивар', 'Кичево'],
        ['Кичево', 'Охрид'],
        ['Охрид', 'Струга'],
        ['Струга', 'Дебар'],
        ['Битола', 'Прилеп'],
        ['Битола', 'Охрид'],
        ['Град Скопје', 'Куманово'],
        ['Град Скопје', 'Тетово'],
        ['Град Скопје', 'Велес'],
    ],

    // Auto-grouping: how many participants a group may exceed group_size_max
    // by before another group is formed (the API's max_overflow default).
    'auto_group' => [
        'max_overflow' => 0,
    ],

    // Participant / per-school count imports: column headers are recognised
    // by these fixed synonyms (compared lower-case, letters and digits only).
    // Short ones (up to 3 letters) must be a whole word of the header, longer
    // ones may appear anywhere in it. Checked in this order; the first column
    // per field wins, every other column goes to the participant's "extra".
    'import_headers' => [
        'email' => ['e mail', 'email', 'емаил', 'е маил', 'е пошта', 'електронска пошта', 'пошта', 'mail', 'e posta', 'posta elektronike'],
        'phone' => ['телефон', 'тел', 'tel', 'telefon', 'phone', 'мобилен', 'celular'],
        'school' => ['училишт', 'школо', 'shkoll', 'school'],
        'position' => ['позиција', 'работно место', 'функција', 'звање', 'pozita', 'pozicioni', 'position'],
        'municipality' => ['општина', 'komuna', 'место', 'град', 'qyteti', 'vendi', 'municipality', 'city'],
        'language' => ['јазик', 'gjuha', 'language'],
        'full_name' => ['име и презиме', 'име презиме', 'презиме и име', 'ime i prezime', 'emri dhe mbiemri', 'emri mbiemri', 'full name', 'name', 'учесник'],
        'last_name' => ['презиме', 'mbiemri', 'surname', 'last name'],
        'first_name' => ['име', 'emri', 'first name'],
        'number' => ['ред бр', 'р бр', 'рб', 'бр', 'реден број', 'nr', 'no', 'nr rend'],
    ],

    // Per-school counts: these headers must match exactly.
    'count_headers' => [
        'count_mk' => ['м', 'мк', 'мак', 'македонски', 'mk'],
        'count_sq' => ['а', 'ал', 'алб', 'албански', 'sq', 'shqip'],
        'count_tr' => ['т', 'тр', 'тур', 'турски', 'tr'],
        'count_total' => ['вк', 'вкупно', 'вкупен број', 'број', 'број на учесници', 'total', 'gjithsej'],
    ],

    // "Динамика" Excel export: column headers per format, in column order.
    // Worded as in the client's sample sheets so they can be compared column
    // by column (П14); fix the wording here, not in code. ":staff" is
    // replaced by EduProgram::STAFF_PLURAL of the program's staff_type
    // ("Присутни стручни соработници од училиште"); ":school" / ":school_sq"
    // by dynamics_school_level for the program's school_level.
    'dynamics_headers' => [
        // ПРИМЕР ДИНАМИКА: one row per group.
        'standard' => [
            'number' => 'Ред. Бр',
            'location' => 'Место каде се реализира обуката',
            'schools' => 'Присутни :staff од училиште',
            'city' => 'Град',
            'participants' => 'Број на учесници',
            'language' => 'Јазик',
            'trainer' => 'Обучувач',
            'dates' => 'Дата на реализација на обука',
            'time' => 'Време на реализација на обука',
        ],
        // ДИНАМИКА КАРИЕРНИ: one row per host school, bilingual headers.
        'host' => [
            'number' => 'Ред. бр. / Nr. rend.',
            'region' => 'Регион',
            'city' => 'Град',
            'municipality' => 'Општина / Komuna',
            'host_school' => 'Име на :school училиште / Emri i :school_sq',
            'address' => 'Адреса на која се наоѓа училиштето и место / Adresa ku gjendet shkolla, vendi',
            'phone' => 'Телефон/ факс / Telefoni/faksi',
            'director' => 'Контакт Директор',
            'email' => 'Електронска пошта (e-mail) на училиштето / Posta elektronike (e - maili) shkollës',
            'email_schools_mk' => 'Електронска пошта (e-mail) на училиштето на schools.mk / Posta elektronike (e - maili) shkollës',
            'schools' => 'Училиште од кое доаѓаат',
            'language' => 'Јазик',
            'participants' => 'Број на учесници',
            'comment' => 'Коментар',
        ],
    ],

    // "Динамика": the school words in the headers, per program school_level.
    'dynamics_school_level' => [
        'primary' => ['mk' => 'основното', 'sq' => 'shkollës fillore'],
        'secondary' => ['mk' => 'средното', 'sq' => 'shkollës së mesme'],
    ],

    // "Динамика": how the schools column is joined, e.g.
    // "ООУ „Панко Брашнаров“-Велес (3), ООУ „Гоце Делчев“-Велес (1+1т)".
    'dynamics_separators' => [
        'school' => ', ',
        'municipality' => '-',
        'language_count' => '+',
    ],

    // Group documents (PDF and Word, step 11): every printed label, per
    // language. Albanian (sq) groups print each one as "mk / sq", e.g.
    // "Обучувач / Trajner" (decision 4); ?bilingual=0|1 overrides that.
    // ":n" is replaced by the group / day number. "mk_sq" holds the few
    // labels whose bilingual form is not simply "mk / sq". Used through
    // App\Support\Edu\GroupDocumentLabels.
    'document_labels' => [
        'separator' => ' / ',
        'mk' => [
            'group' => 'Група :n',
            'groups' => 'Групи',
            'topic' => 'Тема',
            'place' => 'Место',
            'city' => 'Град',
            'language' => 'Јазик',
            'trainer' => 'Обучувач',
            'when' => 'Датум и време',
            'no' => 'Ред. бр.',
            'name' => 'Име и презиме',
            'school' => 'Училиште',
            'municipality' => 'Општина',
            'contact' => 'E-mail / Телефон',
            'signature' => 'Потпис',
            'day' => ':n. ден',
            'count' => 'Број на учесници',
            'total' => 'Вкупно',
            'total_participants' => 'Вкупно учесници',
            'participants_list' => 'Листа на учесници',
            'no_participants' => 'Нема учесници во групата.',
            'no_schools' => 'Нема училишта во групата.',
            'no_groups' => 'Нема групи.',
            'printed' => 'Отпечатено',
            'page' => 'Страна',
            'staff' => [
                'teachers' => 'Наставници',
                'career_counselors' => 'Кариерни советници',
                'professional_associates' => 'Стручни соработници',
            ],
            'languages' => ['mk' => 'Македонски', 'sq' => 'Албански', 'tr' => 'Турски'],
        ],
        'sq' => [
            'group' => 'Grupi :n',
            'groups' => 'Grupet',
            'topic' => 'Tema',
            'place' => 'Vendi',
            'city' => 'Qyteti',
            'language' => 'Gjuha',
            'trainer' => 'Trajner',
            'when' => 'Data dhe ora',
            'no' => 'Nr. rend.',
            'name' => 'Emri dhe mbiemri',
            'school' => 'Shkolla',
            'municipality' => 'Komuna',
            'contact' => 'E-mail / Telefoni',
            'signature' => 'Nënshkrimi',
            'day' => 'Dita :n',
            'count' => 'Numri i pjesëmarrësve',
            'total' => 'Gjithsej',
            'total_participants' => 'Gjithsej pjesëmarrës',
            'participants_list' => 'Lista e pjesëmarrësve',
            'no_participants' => 'Nuk ka pjesëmarrës në grup.',
            'no_schools' => 'Nuk ka shkolla në grup.',
            'no_groups' => 'Nuk ka grupe.',
            'printed' => 'Shtypur më',
            'page' => 'Faqja',
            'staff' => [
                'teachers' => 'Mësimdhënës',
                'career_counselors' => 'Këshilltarë për karrierë',
                'professional_associates' => 'Bashkëpunëtorë profesionalë',
            ],
            'languages' => ['mk' => 'Maqedonisht', 'sq' => 'Shqip', 'tr' => 'Turqisht'],
        ],
        'mk_sq' => [
            'contact' => 'E-mail / Телефон / Telefoni',
        ],
    ],
];
