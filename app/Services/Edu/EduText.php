<?php

namespace App\Services\Edu;

use Illuminate\Support\Str;

/**
 * Text clean-up for the client's Excel sheets: school and trainer names,
 * municipalities, languages, phones and e-mails. Pure functions, no database.
 *
 * Order matters when cleaning a name: squish → quotes → splitBilingual →
 * fixHomoglyphs (per half), so the Latin half of a bilingual name is never
 * "fixed" into Cyrillic.
 */
final class EduText
{
    /** Latin letters that look like Cyrillic ones, as typed into Cyrillic words. */
    private const LAT_TO_CYR = [
        'A' => 'А', 'a' => 'а', 'B' => 'В', 'C' => 'С', 'c' => 'с', 'E' => 'Е', 'e' => 'е',
        'H' => 'Н', 'K' => 'К', 'k' => 'к', 'M' => 'М', 'O' => 'О', 'o' => 'о', 'P' => 'Р',
        'p' => 'р', 'T' => 'Т', 'X' => 'Х', 'x' => 'х', 'y' => 'у', 'J' => 'Ј', 'j' => 'ј',
    ];

    /**
     * School type prefixes ("ООУ", "СУГС", …). Any all-caps Cyrillic first word
     * of 2–8 letters containing "У" is treated as a prefix too, so new
     * abbreviations do not need a code change.
     */
    private const TYPE_PREFIXES = [
        'ООУ', 'ОУ', 'ОМУ', 'ООМУ', 'ООМБУ', 'ПОУ', 'СОУ', 'ССОУ', 'СУГС', 'ОСУ', 'ДМУ', 'ДСУ', 'СМУ',
        'ОСТУ', 'СОТУ', 'СОЕУ', 'СОЗУ', 'ОСМУ', 'СОМУ', 'СЕОУ', 'СОЕПТУ', 'СОЗШУ', 'ОЕМУЦ', 'ОУТУ',
        'СОСУ', 'СМУГС', 'СЕПУГС', 'СГГУГС', 'АСУЦ', 'СЕТУГС', 'ДУФК', 'ДСУЛУД', 'ДМБУЦ', 'ДУЦОР',
        'ДУРДМОВ', 'ДМУЦ', 'ОССУ', 'ОСЕУ',
    ];

    /** Spellings of the general school types (see samePrefixFamily()). */
    private const GENERIC_PREFIXES = [null, 'ОУ', 'ООУ', 'СОУ', 'ОСУ', 'СУГС'];

    private const REGIONS = [
        'вардарски' => 'Вардарски',
        'источен' => 'Источен',
        'југозападен' => 'Југозападен',
        'југоисточен' => 'Југоисточен',
        'пелагониски' => 'Пелагониски',
        'полошки' => 'Полошки',
        'североисточен' => 'Североисточен',
        'скопски' => 'Скопски',
        // Typos seen in the client's sheets.
        'пелагонсиски' => 'Пелагониски',
        'пелагонски' => 'Пелагониски',
    ];

    private const LANGUAGE_TOKENS = [
        'м' => 'mk', 'мк' => 'mk', 'мак' => 'mk', 'македонски' => 'mk', 'm' => 'mk', 'mk' => 'mk', 'mak' => 'mk',
        'а' => 'sq', 'ал' => 'sq', 'алб' => 'sq', 'албански' => 'sq', 'a' => 'sq', 'al' => 'sq', 'alb' => 'sq', 'sq' => 'sq', 'shq' => 'sq',
        'т' => 'tr', 'тр' => 'tr', 'тур' => 'tr', 'турски' => 'tr', 't' => 'tr', 'tr' => 'tr', 'tur' => 'tr',
    ];

    /** Domains that are almost certainly typos; reported, never auto-corrected. */
    private const SUSPICIOUS_DOMAINS = ['gail.com', 'gmal.com', 'gmial.com', 'gmail.co', 'yaho.com', 'yahooo.com', 'hotmial.com', 'hotmal.com'];

    /** Macedonian Cyrillic → plain Latin, for the ASCII matching key. */
    private const TRANSLIT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'ѓ' => 'g', 'е' => 'e', 'ж' => 'z',
        'з' => 'z', 'ѕ' => 'dz', 'и' => 'i', 'ј' => 'j', 'к' => 'k', 'л' => 'l', 'љ' => 'lj', 'м' => 'm',
        'н' => 'n', 'њ' => 'nj', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'ќ' => 'k',
        'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'c', 'џ' => 'dz', 'ш' => 's',
    ];

    // ── 1. whitespace ────────────────────────────────────────────────────

    /** Trims and collapses all whitespace, non-breaking spaces included. */
    public static function squish(?string $s): string
    {
        if ($s === null) {
            return '';
        }
        $s = preg_replace('~[\s\x{00A0}\x{2007}\x{202F}\x{200B}\x{FEFF}]+~u', ' ', $s) ?? $s;

        return trim($s);
    }

    // ── 2. quotes ────────────────────────────────────────────────────────

    /**
     * Normalises every quote style (,,X'' "X" „X” „X„ …) to „X“ and closes a
     * quote left open. Each "/"-separated piece is balanced on its own, so the
     * Macedonian and Albanian halves of a name keep their own quotes.
     */
    public static function quotes(string $s): string
    {
        $parts = preg_split('~(/)~u', $s, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$s];
        foreach ($parts as $i => $part) {
            if ($part !== '/') {
                $parts[$i] = self::balanceQuotes($part);
            }
        }

        return implode('', $parts);
    }

    private static function balanceQuotes(string $s): string
    {
        if (!preg_match('~[„“”"\']|,,~u', $s)) {
            return $s;
        }

        $s = str_replace([',,', "''", '‟', '”'], ["\x01", "\x02", "\x02", "\x02"], $s);
        $chars = mb_str_split($s);
        $n = count($chars);
        $out = [];
        $open = false;

        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            $prev = $i > 0 ? $chars[$i - 1] : ' ';
            $next = $i + 1 < $n ? $chars[$i + 1] : ' ';
            $prevIsText = !preg_match('~\s~u', $prev);
            $nextIsText = (bool) preg_match('~[\p{L}\p{N}]~u', $next);

            $type = null;
            if ($c === "\x01") {
                $type = 'open';
            } elseif ($c === "\x02") {
                $type = 'close';
            } elseif ($c === '„' || $c === '"') {
                $type = $open && $prevIsText ? 'close' : 'open';
            } elseif ($c === '“') {
                // Macedonian closing quote, unless it plainly opens a word.
                $type = !$open && !$prevIsText && $nextIsText ? 'open' : 'close';
            } elseif ($c === "'" && $open && preg_match('~\p{L}~u', $prev) && !$nextIsText) {
                $type = 'close';
            }

            if ($type === 'open') {
                if ($open) {
                    $out[] = '“';
                }
                if ($out && preg_match('~[\p{L}\p{N}]~u', end($out))) {
                    $out[] = ' ';
                }
                $out[] = '„';
                $open = true;
                // Drop spaces right after the opening quote.
                while ($i + 1 < $n && $chars[$i + 1] === ' ') {
                    $i++;
                }
            } elseif ($type === 'close') {
                while ($out && end($out) === ' ') {
                    array_pop($out);
                }
                $out[] = '“';
                $open = false;
                if ($nextIsText) {
                    $out[] = ' ';
                }
            } else {
                $out[] = $c;
            }
        }

        if ($open) {
            $trailing = '';
            while ($out && end($out) === ' ') {
                array_pop($out);
                $trailing = ' ';
            }
            $out[] = '“' . $trailing;
        }

        return implode('', $out);
    }

    // ── 3. bilingual names ───────────────────────────────────────────────

    /**
     * 'Тетово/ Tetovë' → ['Тетово', 'Tetovë']. Splits on "/" only when the left
     * side is mostly Cyrillic and the right side mostly Latin, so 'бр. 12/3'
     * stays whole. Without a slash it also splits where an all-Latin tail of
     * two or more words starts ("… „Димитар Влахов“ SHMQSH „Dimitar Vllahov“").
     *
     * @return array{0: string, 1: string|null}
     */
    public static function splitBilingual(string $s, bool $withoutSlash = true): array
    {
        $s = self::squish($s);
        // A slash between digits ("бр. 12/3") never separates the halves.
        $pieces = preg_split('~(?<!\d)\s*/\s*|\s*/\s*(?!\d)~u', $s) ?: [$s];

        for ($i = 1; $i < count($pieces); $i++) {
            $left = implode('/', array_slice($pieces, 0, $i));
            $right = implode('/', array_slice($pieces, $i));
            if (self::script($left) === 'cyrillic' && self::script($right) === 'latin') {
                return [self::squish($left), self::squish($right)];
            }
        }

        if ($withoutSlash && count($pieces) === 1) {
            $words = explode(' ', $s);
            for ($k = 1; $k <= count($words) - 2; $k++) {
                $right = array_slice($words, $k);
                $allLatin = !array_filter($right, fn ($w) => preg_match('~\p{Cyrillic}~u', $w));
                if ($allLatin && preg_match('~\p{Latin}{2,}~u', $right[0])) {
                    $left = implode(' ', array_slice($words, 0, $k));
                    if (self::script($left) === 'cyrillic' && self::script(implode(' ', $right)) === 'latin') {
                        return [$left, implode(' ', $right)];
                    }
                }
            }
        }

        return [$s, null];
    }

    /** 'cyrillic' / 'latin' when at least 60% of the letters are in that script. */
    public static function script(string $s): ?string
    {
        $cyr = preg_match_all('~\p{Cyrillic}~u', $s);
        $lat = preg_match_all('~\p{Latin}~u', $s);
        $total = $cyr + $lat;
        if ($total === 0) {
            return null;
        }
        if ($cyr / $total >= 0.6) {
            return 'cyrillic';
        }
        if ($lat / $total >= 0.6) {
            return 'latin';
        }

        return null;
    }

    // ── 4. homoglyphs ────────────────────────────────────────────────────

    /**
     * Fixes words that mix scripts ('OОУ', 'Kaменица', 'kontаkt'). Only mixed
     * words change; the word goes to the script of its letters that cannot be
     * a look-alike, or to the script of the whole text on a tie.
     */
    public static function fixHomoglyphs(string $s): string
    {
        if (!preg_match('~\p{Cyrillic}~u', $s) || !preg_match('~\p{Latin}~u', $s)) {
            return $s;
        }

        $cyrToLat = array_flip(self::LAT_TO_CYR);
        $context = self::distinctiveScript($s) ?? 'cyrillic';

        return preg_replace_callback('~\p{L}+~u', function ($m) use ($cyrToLat, $context) {
            $word = $m[0];
            if (!preg_match('~\p{Cyrillic}~u', $word) || !preg_match('~\p{Latin}~u', $word)) {
                return $word;
            }
            $target = self::distinctiveScript($word) ?? $context;

            return $target === 'cyrillic'
                ? strtr($word, self::LAT_TO_CYR)
                : strtr($word, $cyrToLat);
        }, $s) ?? $s;
    }

    private static function distinctiveScript(string $s): ?string
    {
        $cyrLookAlikes = array_values(self::LAT_TO_CYR);
        $latLookAlikes = array_keys(self::LAT_TO_CYR);
        $cyr = 0;
        $lat = 0;
        foreach (mb_str_split($s) as $ch) {
            if (preg_match('~\p{Cyrillic}~u', $ch) && !in_array($ch, $cyrLookAlikes, true)) {
                $cyr++;
            } elseif (preg_match('~\p{Latin}~u', $ch) && !in_array($ch, $latLookAlikes, true)) {
                $lat++;
            }
        }
        if ($cyr === $lat) {
            return null;
        }

        return $cyr > $lat ? 'cyrillic' : 'latin';
    }

    /** squish + quotes + homoglyphs: the clean display form of a cell. */
    public static function clean(?string $s): string
    {
        $s = self::squish($s);
        if ($s === '') {
            return '';
        }
        $s = self::squish(self::quotes($s));
        // A dangling "/" left after an empty Albanian half.
        $s = self::squish(preg_replace('~\s*/\s*$~u', '', $s) ?? $s);

        return self::fixHomoglyphs($s);
    }

    // ── 5. matching keys ─────────────────────────────────────────────────

    /** The school type prefix ("ООУ", "СУГС", …) of a name, if any. */
    public static function typePrefix(?string $s): ?string
    {
        $s = self::fixHomoglyphs(self::squish($s));
        if (preg_match('~^((?:\p{Lu}\.){2,5})~u', $s, $m)) {
            return str_replace('.', '', $m[1]);
        }
        if (preg_match('~^(\p{Lu}{2,8})(?=[\s„“"\',]|$)~u', $s, $m)) {
            $word = $m[1];
            $isCyrillic = !preg_match('~\P{Cyrillic}~u', $word);
            if ($isCyrillic && (in_array($word, self::TYPE_PREFIXES, true) || str_contains($word, 'У'))) {
                return $word;
            }
        }

        return null;
    }

    /**
     * The matching key of a school name: lower case, no quotes, punctuation or
     * type prefix, and without a trailing name of its own municipality. The
     * village ("с.Јабланица") stays in the key.
     */
    public static function key(?string $s, ?string $municipality = null): string
    {
        $s = self::fixHomoglyphs(self::squish($s));
        if ($s === '') {
            return '';
        }
        if ($prefix = self::typePrefix($s)) {
            $s = preg_replace('~^(?:(?:\p{Lu}\.){2,5}|\p{Lu}{2,8})~u', '', $s, 1) ?? $s;
        }
        $s = self::words($s);

        if ($municipality !== null && $municipality !== '') {
            $m = self::words(self::fixHomoglyphs($municipality));
            if ($m !== '' && $s !== $m && str_ends_with($s, ' ' . $m)) {
                $s = rtrim(mb_substr($s, 0, mb_strlen($s) - mb_strlen($m) - 1));
            }
        }

        return $s;
    }

    /**
     * The key with the type prefix kept ("сому гостивар"), for schools of one
     * municipality whose names differ only by prefix.
     */
    public static function keyWithPrefix(?string $s, ?string $municipality = null): string
    {
        $prefix = self::typePrefix($s);
        $key = self::key($s, $municipality);

        return $prefix ? trim(mb_strtolower($prefix) . ' ' . $key) : $key;
    }

    /**
     * Whether two type prefixes name the same kind of school. The general
     * ones are spelled several ways for one school (ОУ / ООУ, СОУ / ОСУ /
     * СУГС); specialised ones (СОМУ, ОСТУ, СЕОУ, …) are different schools.
     */
    public static function samePrefixFamily(?string $a, ?string $b): bool
    {
        $family = fn (?string $p) => in_array($p, self::GENERIC_PREFIXES, true) ? 'general' : (string) $p;

        return $family($a) === $family($b);
    }

    /** The key with the village ("с.X") left out; only a fallback when matching. */
    public static function keyWithoutSettlement(?string $s, ?string $municipality = null): string
    {
        $s = self::squish($s);
        $s = preg_replace('~(?<!\p{L})(?:с\.|с(?=\s)|село(?=\s)|населба(?=\s))\s*[^,(]*~u', ' ', $s) ?? $s;

        return self::key($s, $municipality);
    }

    /** The village of a school name: 'ООУ „X“ с.Грушино' → 'Грушино'. */
    public static function settlement(?string $s): ?string
    {
        $s = self::fixHomoglyphs(self::squish($s));
        // Only after the quoted name, so a "с" inside the name is not a village.
        $pos = mb_strrpos($s, '“');
        $tail = $pos === false ? $s : mb_substr($s, $pos + 1);
        if (preg_match('~(?<!\p{L})(?:с\.|с(?=\s)|село(?=\s)|населба(?=\s))\s*([\p{L}][\p{L}\s\-]*?)\s*(?:,|\(|$)~u', $tail, $m)) {
            return self::squish($m[1]) ?: null;
        }

        return null;
    }

    /** Lower-case letters and digits separated by single spaces. */
    public static function words(?string $s): string
    {
        $s = mb_strtolower((string) $s);
        $s = preg_replace('~[^\p{L}\p{N}]+~u', ' ', $s) ?? $s;

        return trim($s);
    }

    /** The key of a person's name (trainers, participants). */
    public static function personKey(?string $s): string
    {
        return self::words(self::fixHomoglyphs(self::squish($s)));
    }

    // ── 6. ASCII key ─────────────────────────────────────────────────────

    /**
     * ASCII transliteration for fuzzy matching (levenshtein counts bytes) and
     * for matching a Latin spelling to a Cyrillic one ("Sara Osmani").
     */
    public static function translitKey(?string $s): string
    {
        $s = self::words(self::fixHomoglyphs((string) $s));
        $s = strtr($s, self::TRANSLIT);
        $s = strtolower(Str::ascii($s));
        // Albanian / Macedonian Latin digraphs to the single letters used above.
        $s = str_replace(['dzh', 'xh', 'sh', 'zh', 'ch', 'gj', 'kj', 'ç'], ['dz', 'dz', 's', 'z', 'c', 'g', 'k', 'c'], $s);
        $s = preg_replace('~[^a-z0-9]+~', ' ', $s) ?? $s;

        return trim($s);
    }

    /** 1 − normalised Levenshtein distance of two ASCII keys. */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $max = max(strlen($a), strlen($b));

        return 1 - levenshtein($a, $b) / $max;
    }

    // ── 7. languages ─────────────────────────────────────────────────────

    /**
     * 'М' → ['mk'], 'мк/алб' → ['mk','sq'], 'М/Т/А' → ['mk','sq','tr'];
     * empty → [] (unknown, never assumed Macedonian).
     *
     * @return list<string>
     */
    public static function language(?string $s): array
    {
        $s = mb_strtolower(self::fixHomoglyphs(self::squish($s)));
        if ($s === '') {
            return [];
        }
        $found = [];
        foreach (preg_split('~[\s/,;+\-.]+~u', $s) ?: [] as $token) {
            if (isset(self::LANGUAGE_TOKENS[$token])) {
                $found[] = self::LANGUAGE_TOKENS[$token];
            }
        }

        return \App\Support\Edu\EduLanguage::normalize($found);
    }

    // ── 8. phones ────────────────────────────────────────────────────────

    /**
     * Splits a phone cell into numbers (digits only). A "d." / "д." prefix marks
     * the director's phone. 8-digit numbers get the leading 0 back; anything
     * shorter than 6 digits is dropped.
     *
     * @return array{phones: list<string>, director: list<string>}
     */
    public static function phones(?string $s): array
    {
        $result = ['phones' => [], 'director' => []];
        $s = str_replace(["\u{00A0}", "\r"], [' ', "\n"], (string) $s);

        foreach (preg_split('~ {2,}|\t|;|\n|,~u', $s) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $bucket = 'phones';
            if (preg_match('~^(?:d|д|dir|дир)\.\s*~iu', $chunk, $m)) {
                $bucket = 'director';
                $chunk = substr($chunk, strlen($m[0]));
            }
            foreach (self::phoneNumbers($chunk) as $number) {
                if (!in_array($number, $result[$bucket], true)) {
                    $result[$bucket][] = $number;
                }
            }
        }

        return $result;
    }

    /** @return list<string> */
    private static function phoneNumbers(string $chunk): array
    {
        $groups = [];
        foreach (preg_split('~[\s/]+~u', $chunk) ?: [] as $token) {
            $digits = preg_replace('~\D+~', '', $token) ?? '';
            if ($digits !== '') {
                $groups[] = $digits;
            }
        }

        // Join "02 3111 222" / "033/471074" back into one number.
        $numbers = [];
        $acc = '';
        foreach ($groups as $g) {
            $len = strlen($acc);
            $canGrow = $acc === ''
                || ($len < 8 && $len + strlen($g) <= 9)
                || ($len === 8 && $acc[0] === '0' && strlen($g) === 1);
            if ($canGrow) {
                $acc .= $g;
            } else {
                $numbers[] = $acc;
                $acc = $g;
            }
        }
        if ($acc !== '') {
            $numbers[] = $acc;
        }

        $out = [];
        foreach ($numbers as $n) {
            if (strlen($n) < 6) {
                continue;
            }
            if (strlen($n) === 8 && $n[0] !== '0') {
                $n = '0' . $n;
            }
            $out[] = $n;
        }

        return $out;
    }

    // ── 9. e-mails ───────────────────────────────────────────────────────

    /**
     * Splits an e-mail cell, fixes look-alike letters and 'yahoo,com', and
     * keeps only valid, lower-case, unique addresses. Suspicious domains
     * ('gail.com') are reported, not corrected.
     *
     * @return array{emails: list<string>, invalid: list<string>, warnings: list<string>}
     */
    public static function emails(?string $s): array
    {
        $result = ['emails' => [], 'invalid' => [], 'warnings' => []];
        $s = self::fixHomoglyphs(self::squish($s));
        if ($s === '') {
            return $result;
        }
        $s = preg_replace('~(@[\w.\-]+),(com|net|org|mk)(?![\w.@])~u', '$1.$2', $s) ?? $s;

        foreach (preg_split('~[,;\s]+~u', $s) ?: [] as $token) {
            $token = mb_strtolower(trim($token, " .:<>()[]\"'"));
            if ($token === '') {
                continue;
            }
            if (filter_var($token, FILTER_VALIDATE_EMAIL) === false) {
                $result['invalid'][] = $token;
                continue;
            }
            if (in_array($token, $result['emails'], true)) {
                continue;
            }
            $result['emails'][] = $token;
            $domain = substr($token, strrpos($token, '@') + 1);
            if (in_array($domain, self::SUSPICIOUS_DOMAINS, true)) {
                $result['warnings'][] = "Можна грешка во доменот: {$token}";
            }
        }

        return $result;
    }

    // ── 10. regions ──────────────────────────────────────────────────────

    /** 'Пелагонсиски регион' → 'Пелагониски'; the word "регион" is dropped. */
    public static function region(?string $s): ?string
    {
        $words = array_diff(explode(' ', self::words(self::fixHomoglyphs(self::squish($s)))), ['регион', 'region', '']);
        $k = implode(' ', $words);
        if ($k === '') {
            return null;
        }

        return self::REGIONS[$k] ?? mb_convert_case(mb_substr($k, 0, 1), MB_CASE_UPPER) . mb_substr($k, 1);
    }
}
