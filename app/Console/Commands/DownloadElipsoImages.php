<?php

namespace App\Console\Commands;

use App\Models\ShopProduct;
use App\Models\ShopVendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DownloadElipsoImages extends Command
{
    protected $signature   = 'elipso:download-images
                                {--skip-existing : Skip products that already have an image}
                                {--dry-run : Show what would be downloaded without saving}
                                {--limit=0 : Limit number of products to process (0 = all)}';
    protected $description = 'Download product images from elipso.mk and link them to ShopProduct records';

    private string $baseUrl    = 'https://elipso.mk';
    private string $searchPath = '/?s={query}&post_type=product';
    private string $storageDisk = 'public';
    private string $storageDir  = 'shop-products';

    public function handle(): int
    {
        $vendor = ShopVendor::where('slug', 'elipso')->first();

        if (! $vendor) {
            $this->error('Vendor Елипсо not found. Run ElipsoSeeder first.');
            return self::FAILURE;
        }

        $query = ShopProduct::where('shop_vendor_id', $vendor->id);

        if ($this->option('skip-existing')) {
            $query->whereNull('image');
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $products = $query->orderBy('id')->get();
        $dryRun   = $this->option('dry-run');

        $this->info("Processing {$products->count()} Елипсо products...");
        $this->newLine();

        $ok   = 0;
        $fail = 0;

        foreach ($products as $product) {
            $this->line("► <comment>{$product->name}</comment>");

            try {
                $productUrl = $this->findProductUrl($product->name, $product->sku);

                if (! $productUrl) {
                    $this->warn("  ✗ Product URL not found on elipso.mk");
                    $fail++;
                    $this->sleep(400);
                    continue;
                }

                $this->line("  URL: {$productUrl}");

                $imageUrl = $this->extractOgImage($productUrl);

                if (! $imageUrl) {
                    $this->warn("  ✗ og:image not found on product page");
                    $fail++;
                    $this->sleep(400);
                    continue;
                }

                $this->line("  IMG: {$imageUrl}");

                if ($dryRun) {
                    $this->info("  [dry-run] Would save image.");
                    $ok++;
                    $this->sleep(200);
                    continue;
                }

                $localPath = $this->downloadImage($imageUrl, $product->sku);

                if (! $localPath) {
                    $this->warn("  ✗ Download failed");
                    $fail++;
                    $this->sleep(400);
                    continue;
                }

                $product->update(['image' => $localPath]);
                $this->info("  ✓ Saved → {$localPath}");
                $ok++;

            } catch (\Throwable $e) {
                $this->warn("  ✗ Error: {$e->getMessage()}");
                $fail++;
            }

            // Polite crawl delay 0.6–1 s
            $this->sleep(rand(600, 1000));
        }

        $this->newLine();
        $this->info("Done. OK: {$ok}  Failed: {$fail}");

        return self::SUCCESS;
    }

    // ── Step 1: Search elipso.mk and return the first product URL ─────────

    private function findProductUrl(string $name, string $sku): ?string
    {
        // Extract model code from SKU (everything after the first dash)
        $modelCode = preg_replace('/^[A-Z]+-/', '', $sku);
        // Try to insert spaces/dots to make it more readable (e.g. EC9885M → EC 9885 M)
        $searchTerm = $this->modelCodeToSearchTerm($modelCode, $name);

        $url = $this->baseUrl . str_replace('{query}', urlencode($searchTerm), $this->searchPath);

        $html = $this->fetch($url);
        if (! $html) {
            return null;
        }

        // WooCommerce product links are under /kupi/ on elipso.mk
        if (preg_match('~href="(https://elipso\.mk/kupi/[^"]+)"~', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    // ── Step 2: Fetch product page and extract og:image ───────────────────

    private function extractOgImage(string $productUrl): ?string
    {
        $html = $this->fetch($productUrl);
        if (! $html) {
            return null;
        }

        // og:image is the full-size main product image
        if (preg_match('~<meta[^>]+property=["\']og:image["\'][^>]+content=["\'](https://elipso\.mk/wp-content/uploads/[^"\']+)["\']~i', $html, $m)) {
            return $m[1];
        }

        // Fallback: any wp-content upload image
        if (preg_match('~content=["\'](https://elipso\.mk/wp-content/uploads/\d{4}/\d{2}/[^"\']+\.(jpg|jpeg|png|webp))["\'"]~i', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    // ── Step 3: Download image and save to public storage ─────────────────

    private function downloadImage(string $imageUrl, string $sku): ?string
    {
        $ext       = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
        $filename  = 'elipso_' . Str::lower(preg_replace('/[^a-zA-Z0-9\-]/', '_', $sku)) . '.' . $ext;
        $localPath = $this->storageDir . '/' . $filename;

        $imageData = $this->fetch($imageUrl, true);
        if (! $imageData) {
            return null;
        }

        Storage::disk($this->storageDisk)->put($localPath, $imageData);

        return $localPath;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function fetch(string $url, bool $binary = false): string|false
    {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 15,
                'header'  => implode("\r\n", [
                    'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept: text/html,application/xhtml+xml,*/*;q=0.9',
                    'Accept-Language: mk,en;q=0.8',
                    'Connection: keep-alive',
                ]),
                'follow_location' => 1,
                'max_redirects'   => 5,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);

        return ($binary || $result !== false) ? $result : false;
    }

    private function modelCodeToSearchTerm(string $modelCode, string $name): string
    {
        // If the product name ends with something that looks like a model code
        // (alphanumeric + dashes/dots), use that directly
        if (preg_match('/\b([A-Z0-9][\w\-\.]{3,})\s*$/u', $name, $m)) {
            return $m[1];
        }

        // Otherwise use the SKU model part with spaces inserted before digit groups
        return preg_replace('/([A-Z]+)(\d)/', '$1 $2', $modelCode);
    }

    private function sleep(int $ms): void
    {
        usleep($ms * 1000);
    }
}
