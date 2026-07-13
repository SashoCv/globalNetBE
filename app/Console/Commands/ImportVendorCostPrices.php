<?php

namespace App\Console\Commands;

use App\Models\ShopProduct;
use App\Models\ShopVendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Imports "цена кон нас" (our cost) from a distributor's wholesale price list
 * (xls/xlsx/csv) and matches each row to an existing ShopProduct by looking
 * for the row's model code as a substring of the product name.
 *
 * The model code is extracted from the model-code column by walking its
 * whitespace-separated tokens and stopping at the first token that looks
 * like a descriptor rather than part of the code: a token with any Cyrillic
 * character, any lowercase Latin letter, or a known noise word (SERIJA,
 * SER, NOVO*, VARIO, ODDEL, SIDE, BY, ...). A trailing "/" on a token also
 * ends the code. Real model codes in these price lists are always pure
 * uppercase Latin+digits (e.g. "WAN 24066BY", "BBZ 41 FK").
 *
 * For each row, the longest prefix of the extracted code tokens that matches
 * exactly one product name (case-insensitive substring) is used. Rows where
 * no prefix matches exactly one product are reported as "ambiguous" (matched
 * more than one product) or "unmatched" (matched none) instead of guessing.
 */
class ImportVendorCostPrices extends Command
{
    protected $signature = 'shop:import-cost-prices
                                {file? : Path to the price list, or a preset key (see --list-presets)}
                                {--list-presets : List the known preset price lists and exit}
                                {--vendor= : Vendor slug (overrides the preset default)}
                                {--sheet= : Sheet index (0-based) to read (overrides the preset default)}
                                {--start-row= : First 0-based row to read (overrides the preset default)}
                                {--model-col= : 0-based column index holding the model code (overrides the preset default)}
                                {--price-col= : 0-based column index holding the wholesale/cost price (overrides the preset default)}
                                {--desc-col= : 0-based column index holding the description, report only (overrides the preset default)}
                                {--apply : Actually write cost_price updates (default is dry-run)}';

    protected $description = 'Match a distributor wholesale price list to existing products by model code and update cost_price';

    private const NOISE_WORDS = [
        'SERIJA', 'SER', 'SERIA', 'NOVO*', 'NOVO', 'НОВО*', 'НОВО',
        'VARIO', 'ODDEL', 'ODDEL,', 'SIDE', 'BY', 'GARANCIJA',
    ];

    /**
     * Known price lists bundled with the project under
     * storage/app/private/vendor-pricelists/, with the column layout that
     * matches them. `{file}` may be one of these keys instead of a path.
     */
    private const PRESETS = [
        'elipso-rimeko' => [
            'path' => 'vendor-pricelists/elipso-rimeko-2026-07-03.xls',
            'vendor' => 'elipso',
            'sheet' => 0,
            'start-row' => 6,
            'model-col' => 0,
            'price-col' => 2,
            'desc-col' => 1,
        ],
    ];

    public function handle(): int
    {
        if ($this->option('list-presets')) {
            foreach (self::PRESETS as $key => $preset) {
                $this->line("<comment>{$key}</comment>  →  {$preset['path']}  (vendor: {$preset['vendor']})");
            }
            return self::SUCCESS;
        }

        $fileArg = $this->argument('file');
        if (! $fileArg) {
            $this->error('Pass a file path or a preset key. See --list-presets.');
            return self::FAILURE;
        }

        $preset = self::PRESETS[$fileArg] ?? null;
        $path = $preset ? storage_path('app/private/' . $preset['path']) : $fileArg;

        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $vendorSlug = $this->option('vendor') ?: ($preset['vendor'] ?? 'elipso');
        $vendor = ShopVendor::where('slug', $vendorSlug)->first();
        if (! $vendor) {
            $this->error("Vendor with slug \"{$vendorSlug}\" not found.");
            return self::FAILURE;
        }

        $opt = fn (string $key, int $default) => $this->option($key) !== null
            ? (int) $this->option($key)
            : (int) ($preset[$key] ?? $default);

        $sheetIdx = $opt('sheet', 0);
        $modelCol = $opt('model-col', 0);
        $priceCol = $opt('price-col', 2);
        $descCol  = $opt('desc-col', 1);
        $startRow = $opt('start-row', 1);
        $apply    = (bool) $this->option('apply');

        $this->info('Loading ' . basename($path) . ' ...');
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheet($sheetIdx);
        $rows = $sheet->toArray(null, true, true, false);

        $products = ShopProduct::where('shop_vendor_id', $vendor->id)->get(['id', 'name', 'cost_price']);
        $this->info("Matching against {$products->count()} products for vendor \"{$vendor->name}\".");

        $matched = [];
        $ambiguous = [];
        $unmatched = [];

        foreach ($rows as $i => $row) {
            if ($i < $startRow) {
                continue;
            }

            $rawModel = trim((string) ($row[$modelCol] ?? ''));
            $rawPrice = $row[$priceCol] ?? null;
            $desc = trim((string) ($row[$descCol] ?? ''));

            $codeTokens = $this->extractCodeTokens($rawModel);
            $price = $this->parsePrice($rawPrice);

            if (empty($codeTokens) || $price === null) {
                continue;
            }

            $hit = $this->findMatch($codeTokens, $products);

            if ($hit === null) {
                $fullCode = implode(' ', $codeTokens);
                $fullHits = $this->productsContaining($fullCode, $products);
                if (count($fullHits) > 1) {
                    $ambiguous[] = ['model' => $rawModel, 'code' => $fullCode, 'price' => $price, 'desc' => $desc, 'candidates' => $fullHits];
                } else {
                    $unmatched[] = ['model' => $rawModel, 'code' => $fullCode, 'price' => $price, 'desc' => $desc];
                }
                continue;
            }

            [$code, $product] = $hit;
            $matched[] = ['model' => $rawModel, 'code' => $code, 'price' => $price, 'product' => $product];
        }

        $this->newLine();
        $this->info('Matched:   ' . count($matched));
        $this->warn('Ambiguous: ' . count($ambiguous) . ' (multiple candidate products — not touched)');
        $this->warn('Unmatched: ' . count($unmatched) . ' (no candidate product found)');

        if ($apply) {
            $updated = 0;
            foreach ($matched as $m) {
                $m['product']->update(['cost_price' => $m['price']]);
                $updated++;
            }
            $this->info("Applied: updated cost_price on {$updated} products.");
        } else {
            $this->comment('Dry-run (no changes written). Re-run with --apply to update cost_price.');
        }

        $this->writeReports($matched, $ambiguous, $unmatched);

        return self::SUCCESS;
    }

    /** @param ShopProduct[]|\Illuminate\Support\Collection $products */
    private function findMatch(array $codeTokens, $products): ?array
    {
        for ($len = count($codeTokens); $len >= 1; $len--) {
            $candidate = implode(' ', array_slice($codeTokens, 0, $len));
            $hits = $this->productsContaining($candidate, $products);
            if (count($hits) === 1) {
                return [$candidate, $hits[0]];
            }
        }
        return null;
    }

    /** @return ShopProduct[] */
    private function productsContaining(string $needle, $products): array
    {
        $needle = mb_strtoupper($needle, 'UTF-8');
        return $products->filter(
            fn (ShopProduct $p) => str_contains(mb_strtoupper($p->name, 'UTF-8'), $needle)
        )->values()->all();
    }

    /** @return string[] */
    private function extractCodeTokens(string $raw): array
    {
        $raw = str_replace("\xc2\xa0", ' ', $raw); // non-breaking space
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $tokens = preg_split('/\s+/', $raw);
        $code = [];

        foreach ($tokens as $tok) {
            $stopAfter = false;
            if (str_ends_with($tok, '/')) {
                $tok = substr($tok, 0, -1);
                $stopAfter = true;
            }
            $tok = rtrim($tok, ',;');

            if ($tok === '') {
                break;
            }
            if (in_array(mb_strtoupper($tok, 'UTF-8'), self::NOISE_WORDS, true)) {
                break;
            }
            if (preg_match('/\p{Cyrillic}/u', $tok)) {
                break;
            }
            if ($tok !== mb_strtoupper($tok, 'UTF-8')) {
                break; // contains a lowercase letter -> descriptor, not code
            }

            $code[] = $tok;
            if ($stopAfter) {
                break;
            }
        }

        return $code;
    }

    private function parsePrice(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && preg_match('/([\d.,]+)/', $v, $m)) {
            $num = str_replace(',', '', $m[1]);
            return is_numeric($num) ? (float) $num : null;
        }
        return null;
    }

    private function writeReports(array $matched, array $ambiguous, array $unmatched): void
    {
        $dir = 'imports/cost-prices-' . now()->format('Y-m-d_His');
        Storage::disk('local')->makeDirectory($dir);

        $this->writeCsv("{$dir}/matched.csv", ['model', 'matched_code', 'price', 'product_id', 'product_name'],
            array_map(fn ($m) => [$m['model'], $m['code'], $m['price'], $m['product']->id, $m['product']->name], $matched));

        $this->writeCsv("{$dir}/ambiguous.csv", ['model', 'code', 'price', 'description', 'candidate_products'],
            array_map(fn ($a) => [
                $a['model'], $a['code'], $a['price'], $a['desc'],
                implode(' | ', array_map(fn ($p) => "#{$p->id} {$p->name}", $a['candidates'])),
            ], $ambiguous));

        $this->writeCsv("{$dir}/unmatched.csv", ['model', 'code', 'price', 'description'],
            array_map(fn ($u) => [$u['model'], $u['code'], $u['price'], $u['desc']], $unmatched));

        $this->newLine();
        $this->info('Reports written to storage/app/private/' . $dir . '/ (matched.csv, ambiguous.csv, unmatched.csv)');
    }

    private function writeCsv(string $path, array $header, array $rows): void
    {
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, $header);
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        Storage::disk('local')->put($path, stream_get_contents($fh));
        fclose($fh);
    }
}
