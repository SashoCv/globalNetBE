<?php

namespace App\Console\Commands;

use App\Models\ShopClinic;
use App\Models\ShopOrder;
use App\Models\ShopVendor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;

class PreviewEmailsPdf extends Command
{
    protected $signature = 'mail:preview-pdf {--out=storage/app/public/email-previews.pdf}';

    protected $description = 'Render every clinic/order/vendor notification email into one combined PDF, for design review.';

    public function handle(): int
    {
        $clinic = ShopClinic::latest()->first();
        $productOrder = ShopOrder::with(['clinic', 'items', 'orderModel'])
            ->whereHas('items', fn ($q) => $q->where('kind', 'product'))
            ->latest()->first();
        $serviceOrder = ShopOrder::with(['clinic', 'items', 'orderModel'])
            ->whereHas('items', fn ($q) => $q->where('kind', 'service'))
            ->whereDoesntHave('items', fn ($q) => $q->where('kind', '!=', 'service'))
            ->latest()->first();
        $vendor = ShopVendor::latest()->first();

        if (!$clinic || !$productOrder || !$serviceOrder || !$vendor) {
            $this->error('Недостасуваат примероци (ординација / нарачка-производ / нарачка-услуга / добавувач) во базата за да се генерира преглед.');
            return 1;
        }

        $sections = [
            ['label' => '1. Регистрација на ординација — до администратор', 'view' => 'emails.clinic.new-registration-admin', 'data' => ['clinic' => $clinic]],
            ['label' => '2. Регистрација на ординација — до ординацијата', 'view' => 'emails.clinic.registered', 'data' => ['clinic' => $clinic]],
            ['label' => '3. Нова нарачка (производи) — до администратор', 'view' => 'emails.order.new-order-admin', 'data' => ['order' => $productOrder]],
            ['label' => '4. Нова нарачка (производи) — до ординацијата', 'view' => 'emails.order.new-order-clinic', 'data' => ['order' => $productOrder]],
            ['label' => '5. Барање за услуга — до администратор', 'view' => 'emails.order.new-order-admin', 'data' => ['order' => $serviceOrder]],
            ['label' => '6. Барање за услуга — до ординацијата', 'view' => 'emails.order.new-order-clinic', 'data' => ['order' => $serviceOrder]],
            ['label' => '7. Апликација за добавувач — до администратор', 'view' => 'emails.vendor.new-application-admin', 'data' => ['vendor' => $vendor]],
        ];

        $bodyParts = [];
        foreach ($sections as $i => $section) {
            $html = view($section['view'], $section['data'])->render();
            preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $m);
            $inner = $m[1] ?? $html;

            $pageBreak = $i > 0 ? 'page-break-before: always;' : '';
            $bodyParts[] = "
                <div style=\"{$pageBreak} padding-bottom: 12px;\">
                    <div style=\"font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#94a3b8; margin-bottom:10px;\">
                        {$section['label']}
                    </div>
                    {$inner}
                </div>
            ";
        }

        $finalHtml = '<!DOCTYPE html><html><head><meta charset="utf-8">
            <style>* { font-family: "DejaVu Sans", sans-serif !important; }</style>
            </head><body style="margin:0;">'
            . implode('', $bodyParts)
            . '</body></html>';

        $outPath = base_path($this->option('out'));
        @mkdir(dirname($outPath), 0755, true);

        Pdf::loadHTML($finalHtml)->setPaper('a4')->save($outPath);

        $this->info("Готово: {$outPath}");
        return 0;
    }
}
