<?php

namespace Tests\Unit\Edu;

use App\Services\Edu\EduText;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EduTextTest extends TestCase
{
    public static function homoglyphs(): array
    {
        return [
            'Latin O in ОOУ' => ['ОOУ „Блаже“', 'ООУ „Блаже“'],
            'Latin O first' => ['OОУ „Даме“', 'ООУ „Даме“'],
            'two Latin O' => ['OOУ', 'ООУ'],
            'Latin a in Каменица' => ['Македонска Kaменица', 'Македонска Каменица'],
            'Latin a in Кавадарци' => ['Кaвадарци', 'Кавадарци'],
            'Latin e in Тетово' => ['Teтово', 'Тетово'],
            'Cyrillic а in a Latin e-mail' => ['kontаkt@test.mk', 'kontakt@test.mk'],
            'pure Latin untouched' => ['SHMEJQSH Arseni Jovkov', 'SHMEJQSH Arseni Jovkov'],
        ];
    }

    #[DataProvider('homoglyphs')]
    public function test_homoglyphs_are_fixed_per_word(string $in, string $out): void
    {
        $this->assertSame($out, EduText::fixHomoglyphs($in));
    }

    public static function quotes(): array
    {
        return [
            ',,X“' => [',,Тест“', '„Тест“'],
            ',,X\'\'' => ["SHMQSH ,,Dimitar Vllahov''", 'SHMQSH „Dimitar Vllahov“'],
            'straight' => ['СЕТУГС „Михајло Пупин"', 'СЕТУГС „Михајло Пупин“'],
            'low quote closing' => ['СОУ „28 Ноември„ (ЦСНО „Здравко“)', 'СОУ „28 Ноември“ (ЦСНО „Здравко“)'],
            'unclosed before slash' => ['СГГУГС „Здравко Цветковски/SHMNQSH „Zdravko“', 'СГГУГС „Здравко Цветковски“/SHMNQSH „Zdravko“'],
            'spaces inside' => ['„ Напредна обука “', '„Напредна обука“'],
            'missing space' => ['ООУ„7 Марси“', 'ООУ „7 Марси“'],
        ];
    }

    #[DataProvider('quotes')]
    public function test_quotes_are_normalised(string $in, string $out): void
    {
        $this->assertSame($out, EduText::squish(EduText::quotes($in)));
    }

    public function test_extra_spaces_and_nbsp_are_squished(): void
    {
        $this->assertSame('Радовиш Goce', EduText::squish("  Радовиш\u{00A0}\u{00A0} Goce  "));
    }

    public function test_bilingual_names_are_split_only_cyrillic_slash_latin(): void
    {
        $this->assertSame(['Тетово', 'Tetovë'], EduText::splitBilingual('Тетово/ Tetovë'));
        $this->assertSame(['бр. 12/3', null], EduText::splitBilingual('бр. 12/3'));
        $this->assertSame(
            ['СЕПУГС „Арсени Јовков“', 'SHMEJQSH Arseni Jovkov'],
            EduText::splitBilingual(EduText::clean('СEПУГС  „Арсени Јовков“/ SHMEJQSH Arseni Jovkov')),
        );
        // No slash: an all-Latin tail of two or more words.
        $this->assertSame(
            ['СУГС „Димитар Влахов“', 'SHMQSH „Dimitar Vllahov“'],
            EduText::splitBilingual(EduText::clean("СУГС „Димитар Влахов“               SHMQSH ,,Dimitar Vllahov''")),
        );
    }

    public function test_the_latin_half_is_left_untouched(): void
    {
        [, $sq] = EduText::splitBilingual(EduText::clean('СEПУГС „Арсени Јовков“/ SHMEJQSH Arseni Jovkov'));
        $this->assertSame('SHMEJQSH Arseni Jovkov', $sq);
    }

    public static function languages(): array
    {
        return [
            'М' => ['М', ['mk']],
            'МК' => ['МК', ['mk']],
            'алб' => ['алб', ['sq']],
            'АЛ' => ['АЛ', ['sq']],
            'тр' => ['тр', ['tr']],
            'мк/а' => ['мк/а', ['mk', 'sq']],
            'мк/алб' => ['мк/алб', ['mk', 'sq']],
            'М/Т/А' => ['М/Т/А', ['mk', 'sq', 'tr']],
            'М/А/Т' => ['М/А/Т', ['mk', 'sq', 'tr']],
            'empty is unknown' => ['', []],
            'null is unknown' => [null, []],
        ];
    }

    #[DataProvider('languages')]
    public function test_languages(?string $in, array $out): void
    {
        $this->assertSame($out, EduText::language($in));
    }

    public static function phones(): array
    {
        return [
            'director prefix' => ['d. 070 123 456   02 3111 222', ['023111222'], ['070123456']],
            '8 digits get the 0' => ['75235637', ['075235637'], []],
            'area code slash' => ['033/471074', ['033471074'], []],
            'two numbers' => ['043/619631 076241747', ['043619631', '076241747'], []],
            'wide gap' => ['071-905-850          070-393-554', ['071905850', '070393554'], []],
            'too short' => ['12345', [], []],
            'only a slash' => ['/', [], []],
        ];
    }

    #[DataProvider('phones')]
    public function test_phones(string $in, array $phones, array $director): void
    {
        $this->assertSame(['phones' => $phones, 'director' => $director], EduText::phones($in));
    }

    public function test_emails(): void
    {
        $this->assertSame(['test@yahoo.com'], EduText::emails('test@yahoo,com')['emails']);
        $this->assertSame(['kontakt@test.mk'], EduText::emails('kontаkt@test.mk')['emails']);
        $this->assertSame(['a@test.mk', 'b@test.mk'], EduText::emails('A@test.mk b@test.mk; a@test.mk,')['emails']);
        $this->assertSame(['nope@'], EduText::emails('nope@')['invalid']);

        // A likely typo is reported, not corrected.
        $typo = EduText::emails('ime.test@gail.com');
        $this->assertSame(['ime.test@gail.com'], $typo['emails']);
        $this->assertCount(1, $typo['warnings']);
    }

    public function test_the_key_keeps_the_village(): void
    {
        $jablanica = EduText::key('ООУ „Гоце Делчев“ с.Јабланица', 'Струга');
        $podgorci = EduText::key('ООУ „Гоце Делчев“ с. Подгорци', 'Струга');

        $this->assertSame('гоце делчев с јабланица', $jablanica);
        $this->assertNotSame($jablanica, $podgorci);
        $this->assertSame('гоце делчев', EduText::keyWithoutSettlement('ООУ „Гоце Делчев“ с.Јабланица', 'Струга'));
        $this->assertSame('Јабланица', EduText::settlement('ООУ „Гоце Делчев“ с.Јабланица'));
    }

    public function test_the_key_drops_prefix_quotes_and_own_municipality(): void
    {
        $this->assertSame('наум наумовски борче', EduText::key('СОУ „Наум Наумовски-Борче“Крушево', 'Крушево'));
        $this->assertSame(EduText::key('ООУ „Блаже Конески“'), EduText::key('OОУ „Блаже Конески“'));
        $this->assertSame(EduText::key('ОУ „Блаже Конески“'), EduText::key('ООУ „Блаже Конески“'));
        $this->assertSame('сому гостивар', EduText::keyWithPrefix('СОМУ „Гостивар“', 'Гостивар'));
    }

    public function test_translit_key_matches_latin_and_cyrillic_spellings(): void
    {
        $this->assertSame(EduText::translitKey('Сара Османи'), EduText::translitKey('Sara Osmani'));
        $this->assertSame(EduText::translitKey('Лумтурије Мехмеди Синани'), EduText::translitKey('Lumturije Mehmedi Sinani'));
    }

    public function test_regions(): void
    {
        $this->assertSame('Пелагониски', EduText::region('Пелагонсиски регион'));
        $this->assertSame('Скопски', EduText::region(' Скопски регион '));
        $this->assertNull(EduText::region(''));
    }
}
