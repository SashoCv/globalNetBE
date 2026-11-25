<?php

namespace Tests\Feature;

use App\Mail\InvoiceIssuedMail;
use App\Mail\VendorPayablesAdminMail;
use App\Mail\VendorProcurementOrderMail;
use App\Models\AdminNotification;
use App\Models\ShopClinic;
use App\Models\ShopInvoice;
use App\Models\ShopOrder;
use App\Models\ShopOrderCycle;
use App\Models\ShopOrderModel;
use App\Models\ShopProcurementDispatch;
use App\Models\ShopProduct;
use App\Models\ShopVendor;
use App\Models\User;
use App\Services\Shop\MonthlySchedule;
use App\Services\Shop\WeeklyCycleService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Article 7 of the vendor contract: deadlines, what vendors get and when, and
 * that goods leave only once paid for.
 */
class ArticleSevenTest extends TestCase
{
    use RefreshDatabase;

    private ShopProduct $gloves;
    private ShopProduct $paper;
    private ShopClinic $clinic;
    private ShopClinic $other;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['app.shop_admin_email' => 'admin@example.com']);

        foreach (['urgent', 'monthly', 'quarterly'] as $code) {
            ShopOrderModel::updateOrCreate(['code' => $code], ['label' => $code, 'title' => $code, 'is_active' => true]);
        }

        $this->gloves = $this->product('Медика', 'medika@example.com', 'Ракавици', 1000);
        $this->paper = $this->product('Хартија', 'hartija@example.com', 'Хартија', 200);
        $this->clinic = $this->clinic('a');
        $this->other = $this->clinic('b');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Non-working days (paragraph 2) ───────────────────────

    public function test_a_weekly_deadline_on_a_holiday_moves_and_the_next_ones_follow(): void
    {
        // Tuesday 8.12.2026 is a holiday.
        $w = app(WeeklyCycleService::class)->windowFor($this->local('2026-12-04 10:00'));

        $this->assertSame('2026-W50', $w['code']);
        $this->assertSame('2026-12-07 12:00', $this->skopje($w['cutoff_at']));
        $this->assertSame('2026-12-09 10:00', $this->skopje($w['discount_due_at']));
        $this->assertSame('2026-12-09 14:00', $this->skopje($w['payment_due_at']));
        $this->assertSame('2026-12-10', $w['delivery_from']);
        $this->assertSame('2026-12-16', $w['delivery_to']);
    }

    public function test_a_cutoff_on_a_holiday_takes_orders_a_day_longer(): void
    {
        config(['shop.holidays' => ['2026-12-07']]);
        $weekly = app(WeeklyCycleService::class);

        $monday = $weekly->windowFor($this->local('2026-12-07 15:00'));
        $this->assertSame('2026-W50', $monday['code']);
        $this->assertSame('2026-12-08 12:00', $this->skopje($monday['cutoff_at']));
        $this->assertSame('2026-12-09 14:00', $this->skopje($monday['payment_due_at']));
        $this->assertSame('2026-12-10', $monday['delivery_from']);

        $this->assertSame('2026-W51', $weekly->windowFor($this->local('2026-12-08 12:00'))['code']);
    }

    public function test_the_monthly_deadlines(): void
    {
        // January 2027: nothing but the nominal days, apart from weekends.
        $s = MonthlySchedule::forPeriodEnd('2027-01-19');
        $this->assertSame(
            ['2027-01-19', '2027-01-20 15:00', '2027-01-21 12:00', '2027-01-25', '2027-01-26 12:00', '2027-01-26', '2027-02-03'],
            $this->dates($s),
        );

        // December 2026: 19.12. is a Saturday, 26.12. too.
        $s = MonthlySchedule::forPeriodEnd('2026-12-21');
        $this->assertSame(
            ['2026-12-21', '2026-12-22 15:00', '2026-12-23 12:00', '2026-12-25', '2026-12-28 12:00', '2026-12-28', '2027-01-06'],
            $this->dates($s),
        );
    }

    // ── Model 03 and 04: the month ───────────────────────────

    public function test_the_whole_month(): void
    {
        $first = $this->order('2026-12-23 10:00', $this->clinic, 'monthly', [[$this->gloves, 2], [$this->paper, 5]]);
        $last = $this->order('2027-01-19 23:30', $this->other, 'monthly', [[$this->gloves, 3]]);
        $next = $this->order('2027-01-20 00:30', $this->other, 'monthly', [[$this->gloves, 50]]);
        $multi = $this->order('2026-12-24 10:00', $this->other, 'quarterly', [[$this->paper, 1]], 3);

        // Nothing goes to the vendors before the 20th.
        $this->tick('2027-01-19 23:50');
        $this->tick('2027-01-20 08:50');
        Mail::assertNotSent(VendorProcurementOrderMail::class);

        // 20th — one order per vendor, all clinics together, sent once.
        $this->tick('2027-01-20 09:00');
        $this->tick('2027-01-20 09:10');
        Mail::assertSent(VendorProcurementOrderMail::class, 2);
        Mail::assertSent(VendorProcurementOrderMail::class, fn ($m) => $m->hasTo('medika@example.com')
            && $m->kind === VendorProcurementOrderMail::RESERVATION
            && $m->group['total_quantity'] === 5
            && str_contains($m->render(), '21.01.2027 во 12:00'));
        Mail::assertSent(VendorProcurementOrderMail::class, fn ($m) => $m->hasTo('hartija@example.com') && $m->group['total_quantity'] === 6);
        $this->assertSame('reserved', $first->fresh()->status);

        // 21st 12:00 — one invoice per clinic, due on the 25th.
        $this->tick('2027-01-21 11:50');
        $this->assertSame(0, ShopInvoice::count());
        $this->tick('2027-01-21 12:00');
        $this->tick('2027-01-21 12:10');
        $this->assertSame(2, ShopInvoice::count());
        $mine = $first->fresh()->invoice;
        $theirs = $last->fresh()->invoice;
        $this->assertSame('2027-01-25', $mine->due_at->toDateString());
        $this->assertSame($theirs->id, $multi->fresh()->shop_invoice_id);
        $this->assertNull($next->fresh()->shop_invoice_id);
        Mail::assertSent(InvoiceIssuedMail::class, 2);

        // One clinic pays: its order is paid, confirmed and earns its 5%.
        $this->assertEquals(0, $first->fresh()->loyalty_credit_earned);
        $this->actingAsAdmin();
        $this->patchJson("/api/shop-invoices/{$mine->id}/mark-paid")->assertOk();
        $first->refresh();
        $this->assertSame(['paid', 'confirmed'], [$first->payment_status, $first->status]);
        $this->assertEquals(150, $first->loyalty_credit_earned);      // 5% of 3000
        $this->assertEquals(150, $this->clinic->fresh()->wallet_balance);

        // The last working day before the 25th (a Monday) is Friday the 22nd.
        $this->tick('2027-01-22 09:00');
        $this->tick('2027-01-22 09:10');
        Mail::assertSent(InvoiceIssuedMail::class, 3);
        Mail::assertSent(InvoiceIssuedMail::class, fn ($m) => $m->reminder && $m->hasTo('b@example.com'));

        // 26th — what to pay the vendors, for the paid orders only.
        $this->tick('2027-01-26 07:50');
        Mail::assertNotSent(VendorPayablesAdminMail::class);
        $this->tick('2027-01-26 08:00');
        $this->tick('2027-01-26 08:10');
        Mail::assertSent(VendorPayablesAdminMail::class, 1);
        Mail::assertSent(VendorPayablesAdminMail::class, fn ($m) => $m->payables === [
            ['vendor' => 'Медика', 'orders' => 1, 'quantity' => 2, 'total' => 1400.0],
            ['vendor' => 'Хартија', 'orders' => 1, 'quantity' => 5, 'total' => 700.0],
        ] && str_contains($m->render(), '26.01.2027 во 12:00'));
        $this->assertSame(1, AdminNotification::where('type', 'monthly_vendor_payables')->count());
    }

    public function test_a_month_that_is_over_is_not_sent_or_billed_again(): void
    {
        $this->order('2026-12-23 10:00', $this->clinic, 'monthly', [[$this->gloves, 2]]);

        foreach (['2027-01-22 12:10', '2027-01-25 10:00', '2027-02-03 10:00', '2027-02-18 10:00'] as $at) {
            $this->tick($at);
        }

        Mail::assertNotSent(VendorProcurementOrderMail::class);
        $this->assertSame(0, ShopInvoice::count());
    }

    public function test_a_cancelled_invoice_is_not_made_again_by_the_run(): void
    {
        $order = $this->order('2026-12-23 10:00', $this->clinic, 'monthly', [[$this->gloves, 2]]);
        $this->tick('2027-01-21 12:00');
        $this->actingAsAdmin();
        $this->patchJson('/api/shop-invoices/' . $order->fresh()->shop_invoice_id . '/cancel')->assertOk();

        $this->tick('2027-01-21 12:10');

        $this->assertSame(1, ShopInvoice::count());
    }

    public function test_a_month_covered_by_points_is_paid_when_invoiced(): void
    {
        $this->clinic->update(['wallet_balance' => 5000]);
        $order = $this->order('2026-12-23 10:00', $this->clinic, 'monthly', [[$this->gloves, 2]]);

        $this->tick('2027-01-21 12:00');

        $this->assertSame('paid', $order->fresh()->invoice->status);
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_vendors_are_no_longer_mailed_every_monday(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");

        $this->assertStringNotContainsString('shop:weekly-dispatch', $commands);
        $this->assertStringContainsString('shop:monthly-cycle', $commands);
    }

    public function test_the_draft_stays_visible_until_the_invoice_is_issued(): void
    {
        $this->order('2026-12-23 10:00', $this->clinic, 'monthly', [[$this->gloves, 2]]);

        Carbon::setTestNow($this->local('2027-01-20 10:00'));
        Sanctum::actingAs($this->clinic, [], 'sanctum');
        $this->getJson('/api/clinic/invoices/draft')->assertOk()
            ->assertJsonPath('period_to', '2027-01-19')
            ->assertJsonPath('issues_at', '2027-01-21')
            ->assertJsonPath('due_at', '2027-01-25');
    }

    // ── Model 02: the week ───────────────────────────────────

    public function test_vendors_get_the_week_when_it_closes_and_the_admin_gets_what_to_pay(): void
    {
        $paid = $this->order('2026-11-26 10:00', $this->clinic, 'weekly', [[$this->gloves, 2]]);
        $unpaid = $this->order('2026-11-27 10:00', $this->other, 'weekly', [[$this->gloves, 4]]);

        $this->weeklyTick('2026-11-30 12:00');
        $this->weeklyTick('2026-11-30 12:01');
        Mail::assertSent(VendorProcurementOrderMail::class, 1);
        Mail::assertSent(VendorProcurementOrderMail::class, fn ($m) => $m->kind === VendorProcurementOrderMail::RESERVATION
            && $m->group['total_quantity'] === 6
            && str_contains($m->render(), 'вторник, 01.12. во 09:00'));
        $this->assertSame('reserved', $paid->fresh()->status);

        $this->weeklyTick('2026-12-01 10:00');
        $this->actingAsAdmin();
        $this->patchJson("/api/shop-orders/{$paid->id}/mark-paid")->assertOk();

        $this->weeklyTick('2026-12-01 14:01');
        $this->assertSame('cancelled', $unpaid->fresh()->status);
        Mail::assertSent(VendorPayablesAdminMail::class, fn ($m) => $m->payables === [['vendor' => 'Медика', 'orders' => 1, 'quantity' => 2, 'total' => 1400.0]]
            && str_contains($m->deadline, '02.12.2026') && str_contains($m->deadline, '13:00'));

        // What was paid reaches the vendor as a confirmation, not a second order.
        $this->dispatchVendor(['cycle_id' => ShopOrderCycle::firstOrFail()->id, 'status' => 'active'])->assertCreated()->assertJsonPath('orders_marked', 1);
        Mail::assertSent(VendorProcurementOrderMail::class, fn ($m) => $m->kind === VendorProcurementOrderMail::CONFIRMATION && $m->group['total_quantity'] === 2);
    }

    public function test_an_unpaid_weekly_order_cannot_slip_into_delivery(): void
    {
        $order = $this->order('2026-11-26 10:00', $this->clinic, 'weekly', [[$this->gloves, 2]]);
        $this->weeklyTick('2026-11-30 12:00');
        $this->weeklyTick('2026-12-01 10:00');
        $this->actingAsAdmin();

        $this->postJson("/api/shop-orders/{$order->id}/dispatch-to-vendors")->assertStatus(422);
        $this->patchJson("/api/shop-orders/{$order->id}/status", ['status' => 'in_delivery'])->assertStatus(422);
        $this->dispatchVendor(['status' => 'all'])->assertCreated()->assertJsonPath('orders_marked', 0);
        $this->assertSame('reserved', $order->fresh()->status);

        $this->weeklyTick('2026-12-01 14:01');
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_an_open_week_moves_to_the_new_deadlines(): void
    {
        $order = $this->order('2026-12-03 10:00', $this->clinic, 'weekly', [[$this->gloves, 2]]);
        $cycle = $order->cycle;
        $cycle->update([
            'discount_due_at' => $this->local('2026-12-08 08:00'),
            'payment_due_at' => $this->local('2026-12-08 14:00'),
            'delivery_from' => '2026-12-09',
        ]);

        (require database_path('migrations/2026_11_25_100001_move_open_weekly_cycles_to_new_deadlines.php'))->up();

        $cycle->refresh();
        $this->assertSame('2026-12-07 12:00', $this->skopje($cycle->cutoff_at));
        $this->assertSame('2026-12-09 10:00', $this->skopje($cycle->discount_due_at));
        $this->assertSame('2026-12-10', $cycle->delivery_from->toDateString());
        $this->assertSame('2026-12-09 14:00', $this->skopje($order->fresh()->payment_due_at));
    }

    // ── Model 01: urgent ─────────────────────────────────────

    public function test_an_urgent_order_goes_to_its_vendors_once_paid(): void
    {
        $order = $this->order('2026-11-26 10:00', $this->clinic, 'urgent', [[$this->gloves, 1], [$this->paper, 2]]);
        Mail::assertNotSent(VendorProcurementOrderMail::class);
        $this->assertSame('new', $order->status);

        $this->actingAsAdmin();
        $this->postJson("/api/shop-orders/{$order->id}/dispatch-to-vendors")->assertStatus(422);
        Mail::assertNotSent(VendorProcurementOrderMail::class);

        $this->patchJson("/api/shop-orders/{$order->id}/mark-paid")->assertOk();
        $this->patchJson("/api/shop-orders/{$order->id}/mark-paid")->assertOk();

        $this->assertSame('confirmed', $order->fresh()->status);
        Mail::assertSent(VendorProcurementOrderMail::class, 2);
        Mail::assertSent(VendorProcurementOrderMail::class, fn ($m) => $m->hasTo('hartija@example.com') && $m->group['total_quantity'] === 2);
    }

    public function test_the_admin_is_told_when_a_vendor_has_no_email(): void
    {
        ShopVendor::where('name', 'Хартија')->update(['email' => null]);
        $order = $this->order('2026-11-26 10:00', $this->clinic, 'urgent', [[$this->paper, 2]]);

        $this->actingAsAdmin();
        $this->patchJson("/api/shop-orders/{$order->id}/mark-paid")->assertOk();

        $this->assertStringContainsString('Хартија', AdminNotification::where('type', 'vendor_dispatch_failed')->firstOrFail()->body);
        $this->assertFalse(ShopProcurementDispatch::firstOrFail()->emailed);
    }

    public function test_reservations_that_did_not_reach_a_vendor_are_sent_by_hand(): void
    {
        $vendor = ShopVendor::where('name', 'Медика')->firstOrFail();
        $vendor->update(['email' => null]);
        $order = $this->order('2026-12-23 10:00', $this->clinic, 'monthly', [[$this->gloves, 2]]);
        $this->tick('2027-01-20 09:00');
        Mail::assertNotSent(VendorProcurementOrderMail::class);
        $this->assertTrue(AdminNotification::where('type', 'vendor_dispatch_failed')->exists());

        $this->actingAsAdmin();
        $this->dispatchVendor(['status' => 'reserved', 'email' => 'nov@example.com'])->assertCreated()->assertJsonPath('orders_marked', 0);
        Mail::assertSent(VendorProcurementOrderMail::class, fn ($m) => $m->hasTo('nov@example.com') && $m->kind === VendorProcurementOrderMail::RESERVATION);
        $this->assertSame('reserved', $order->fresh()->status);

        // The run's own mail never arrived, so what is paid goes as an order.
        $this->tick('2027-01-21 12:00');
        $this->patchJson('/api/shop-invoices/' . $order->fresh()->shop_invoice_id . '/mark-paid')->assertOk();
        $this->dispatchVendor(['status' => 'paid', 'from' => '2026-12-22', 'to' => '2027-01-19', 'email' => 'nov@example.com'])->assertCreated();
        Mail::assertSent(VendorProcurementOrderMail::class, fn ($m) => $m->kind === VendorProcurementOrderMail::ORDER);
    }

    public function test_a_credit_given_at_ordering_is_not_given_again_on_payment(): void
    {
        $order = $this->order('2026-12-23 10:00', $this->clinic, 'monthly', [[$this->gloves, 2]]);
        $order->update(['loyalty_credit_earned' => 100]);   // before the change
        $this->clinic->update(['wallet_balance' => 100]);

        $this->tick('2027-01-21 12:00');
        $this->actingAsAdmin();
        $this->patchJson('/api/shop-invoices/' . $order->fresh()->shop_invoice_id . '/mark-paid')->assertOk();
        $this->patchJson('/api/shop-invoices/' . $order->fresh()->shop_invoice_id . '/mark-paid')->assertOk();

        $this->assertEquals(100, $this->clinic->fresh()->wallet_balance);
    }

    public function test_the_day_this_goes_live_nothing_old_is_sent(): void
    {
        $this->order('2026-11-10 10:00', $this->clinic, 'monthly', [[$this->gloves, 2]]);
        $multi = $this->order('2026-11-23 10:00', $this->other, 'quarterly', [[$this->gloves, 1]], 3);
        Mail::fake();

        foreach (['2026-11-25 20:00', '2026-11-26 07:00', '2026-11-26 09:00', '2026-11-27 12:00', '2026-11-30 08:00'] as $at) {
            $this->tick($at);
            $this->weeklyTick($at);
            $this->artisan('shop:recurring-orders', ['--at' => $at])->assertSuccessful();
        }

        Mail::assertNothingSent();
        $this->assertSame(0, ShopInvoice::count());
        $this->assertSame(1, $multi->recurringOrder->orders()->count());
    }

    public function test_every_mail_of_the_cycle_renders(): void
    {
        $this->order('2026-12-23 10:00', $this->clinic, 'monthly', [[$this->gloves, 2]]);
        $this->tick('2027-01-20 09:00');
        $this->tick('2027-01-21 12:00');
        $this->tick('2027-01-22 09:00');

        Mail::assertSent(VendorProcurementOrderMail::class, fn ($m) => str_contains($m->render(), 'Збирна нарачка'));
        Mail::assertSent(InvoiceIssuedMail::class, fn ($m) => ! $m->reminder && str_contains($m->render(), '25.01.2027'));
        Mail::assertSent(InvoiceIssuedMail::class, fn ($m) => $m->reminder && str_contains($m->render(), 'сè уште не е платена'));
        Mail::assertSent(\App\Mail\NewOrderClinicMail::class, fn ($m) => str_contains($m->render(), '21.01.2027') && str_contains($m->render(), '25.01.2027'));
    }

    // ── Cancelling ───────────────────────────────────────────

    public function test_a_clinic_cannot_cancel_a_paid_monthly_order(): void
    {
        $order = $this->order('2026-12-23 10:00', $this->clinic, 'monthly', [[$this->gloves, 2]]);
        Sanctum::actingAs($this->clinic, [], 'sanctum');
        $this->getJson('/api/clinic/orders')->assertJsonPath('data.0.can_cancel', true);

        $this->tick('2027-01-21 12:00');
        // Paid before the orders followed their invoice: still reserved.
        ShopInvoice::query()->update(['status' => 'paid']);

        Sanctum::actingAs($this->clinic, [], 'sanctum');
        $this->getJson('/api/clinic/orders')->assertJsonPath('data.0.can_cancel', false);
        $this->patchJson("/api/clinic/orders/{$order->id}/cancel")->assertStatus(422);
        $this->assertSame('reserved', $order->fresh()->status);
    }

    public function test_an_admin_cancel_returns_stock_and_points_and_is_final(): void
    {
        $this->clinic->update(['wallet_balance' => 500]);
        $order = $this->order('2026-12-23 10:00', $this->clinic, 'monthly', [[$this->gloves, 2]]);
        $this->assertSame(98, $this->gloves->fresh()->stock);
        $this->assertEquals(0, $this->clinic->fresh()->wallet_balance);

        $this->actingAsAdmin();
        foreach ([1, 2] as $_) {
            $this->patchJson("/api/shop-orders/{$order->id}/status", ['status' => 'cancelled'])->assertOk();
        }

        $this->assertSame(100, $this->gloves->fresh()->stock);
        $this->assertEquals(500, $this->clinic->fresh()->wallet_balance);
        $this->patchJson("/api/shop-orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(422);
    }

    public function test_a_cancelled_order_is_never_sent_to_a_vendor(): void
    {
        $keep = $this->order('2026-12-23 10:00', $this->clinic, 'monthly', [[$this->gloves, 2]]);
        $gone = $this->order('2026-12-23 11:00', $this->other, 'monthly', [[$this->gloves, 7]]);
        $this->actingAsAdmin();
        $this->patchJson("/api/shop-orders/{$gone->id}/status", ['status' => 'cancelled'])->assertOk();

        $this->dispatchVendor(['status' => 'all'])->assertCreated();

        Mail::assertSent(VendorProcurementOrderMail::class, fn ($m) => $m->group['total_quantity'] === 2);
        $this->assertSame('reserved', $keep->fresh()->status);
    }

    public function test_the_paid_scope_lists_paid_monthly_orders_by_local_day(): void
    {
        $paid = $this->order('2027-01-19 23:30', $this->clinic, 'monthly', [[$this->gloves, 2]]);
        $this->order('2027-01-18 10:00', $this->other, 'monthly', [[$this->gloves, 9]]);
        $this->tick('2027-01-20 09:00');
        $this->tick('2027-01-21 12:00');
        $this->actingAsAdmin();
        $this->patchJson("/api/shop-invoices/{$paid->fresh()->shop_invoice_id}/mark-paid")->assertOk();

        $this->getJson('/api/shop-orders/procurement?status=paid&from=2026-12-22&to=2027-01-19')
            ->assertOk()
            ->assertJsonPath('summary.order_count', 1)
            ->assertJsonPath('vendors.0.total_quantity', 2);

        $this->dispatchVendor(['status' => 'paid', 'from' => '2026-12-22', 'to' => '2027-01-19'])->assertCreated()->assertJsonPath('orders_marked', 1);
        Mail::assertSent(VendorProcurementOrderMail::class, fn ($m) => $m->kind === VendorProcurementOrderMail::CONFIRMATION && $m->group['total_quantity'] === 2);
        $this->assertSame('in_delivery', $paid->fresh()->status);
    }

    // ── Helpers ──────────────────────────────────────────────

    private function order(string $at, ShopClinic $clinic, string $model, array $lines, ?int $months = null): ShopOrder
    {
        Carbon::setTestNow($this->local($at));
        Sanctum::actingAs($clinic, [], 'sanctum');

        $id = $this->postJson('/api/clinic/orders', array_filter([
            'order_model_code' => $model,
            'months' => $months,
            'items' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1]], $lines),
        ]))->assertCreated()->json('id');

        return ShopOrder::findOrFail($id);
    }

    private function dispatchVendor(array $with)
    {
        return $this->postJson('/api/shop-orders/procurement/dispatch', $with + [
            'vendor_id' => $this->gloves->shop_vendor_id,
            'send_email' => true,
        ]);
    }

    private function tick(string $at): void
    {
        $this->artisan('shop:monthly-cycle', ['--at' => $at])->assertSuccessful();
    }

    private function weeklyTick(string $at): void
    {
        $this->artisan('shop:weekly-cycles', ['--at' => $at])->assertSuccessful();
    }

    private function local(string $time): Carbon
    {
        return Carbon::parse($time, 'Europe/Skopje')->utc();
    }

    private function skopje($moment): string
    {
        return $moment->copy()->setTimezone('Europe/Skopje')->format('Y-m-d H:i');
    }

    private function dates(MonthlySchedule $s): array
    {
        return [
            $s->cutoff->toDateString(),
            $s->consolidateAt->format('Y-m-d H:i'),
            $s->proformaAt->format('Y-m-d H:i'),
            $s->paymentDue->toDateString(),
            $s->vendorPayAt->format('Y-m-d H:i'),
            $s->deliveryFrom->toDateString(),
            $s->deliveryTo->toDateString(),
        ];
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::firstOrCreate(['email' => 'admin-user@example.com'], [
            'name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin',
        ]), [], 'sanctum');
    }

    private function clinic(string $slug): ShopClinic
    {
        return ShopClinic::create([
            'name' => 'ПЗУ ' . $slug, 'slug' => 'pzu-' . $slug, 'contact_person' => 'Д-р ' . $slug,
            'email' => $slug . '@example.com', 'status' => 'approved', 'password' => bcrypt('secret'),
        ]);
    }

    private function product(string $vendor, string $email, string $name, float $price): ShopProduct
    {
        return ShopProduct::create([
            'shop_vendor_id' => ShopVendor::create(['name' => $vendor, 'slug' => \Illuminate\Support\Str::slug($vendor), 'email' => $email, 'status' => 'active'])->id,
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'sku' => $name, 'kind' => 'product',
            'price' => $price, 'cost_price' => $price * 0.7, 'stock' => 100, 'status' => 'active',
        ]);
    }
}
