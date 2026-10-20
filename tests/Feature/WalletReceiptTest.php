<?php

namespace Tests\Feature;

use App\Models\ReceiptClaim;
use App\Services\ZatcaQr;

// v2 counter: earning by scanning the ZATCA QR on the receipt
class WalletReceiptTest extends WalletTestCase
{
    private const VAT = '300000000000003';
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWallet(5);
        $this->shop->forceFill(['vat_number' => self::VAT])->save();
        config(['services.otp.required' => false]);
        $this->token = $this->postJson('/api/v1/customer/phone', ['phone' => '0512345678', 'menuId' => $this->shop->id])->json('customerToken');
    }

    private function receipt(string $total = '57.50', ?string $time = null, string $vat = self::VAT): string
    {
        return ZatcaQr::make('Cafe', $vat, $time ?? now()->subMinutes(5)->toIso8601String(), $total, '7.50');
    }

    private function claim(string $qr, ?string $token = null)
    {
        return $this->postJson("/api/v1/shops/{$this->shop->id}/receipts", ['qr' => $qr], ['Authorization' => 'Bearer ' . ($token ?? $this->token)]);
    }

    public function test_reads_a_zatca_qr(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-17T20:00:00Z'));
        $r = ZatcaQr::parse(ZatcaQr::make('كافيه', '300000000000003', '2026-10-17T19:41:02Z', '5.75', '0.75'));
        $this->assertSame('300000000000003', $r['vat_number']);
        $this->assertSame(5.75, $r['total']);
        $this->assertSame('2026-10-17 19:41:02', $r['invoice_at']->utc()->format('Y-m-d H:i:s'));
        $this->assertNull(ZatcaQr::parse('not a receipt'));
    }

    public function test_a_receipt_adds_cashback_straight_away(): void
    {
        $this->claim($this->receipt('57.50'))->assertOk()->assertJson(['total' => 57.5, 'cashbackPercent' => 5, 'cashbackAmount' => 2.88, 'balance' => 2.88]);
    }

    public function test_the_same_receipt_only_once(): void
    {
        $qr = $this->receipt();
        $this->claim($qr)->assertOk();
        $this->claim($qr)->assertStatus(422)->assertJson(['message' => 'receipt_already_claimed']);
    }

    public function test_receipt_from_another_shop_is_refused(): void
    {
        $this->claim($this->receipt('20', null, '399999999999993'))->assertStatus(422)->assertJson(['message' => 'receipt_other_shop']);
    }

    public function test_shop_without_vat_number_refuses_receipts(): void
    {
        $this->shop->forceFill(['vat_number' => null])->save();
        $this->claim($this->receipt())->assertStatus(422)->assertJson(['message' => 'receipt_other_shop']);
    }

    public function test_over_1000_is_refused_and_exactly_1000_is_fine(): void
    {
        $this->claim($this->receipt('1000.01'))->assertStatus(422)->assertJson(['message' => 'receipt_too_large']);
        $this->claim($this->receipt('1000.00'))->assertOk();
    }

    public function test_older_than_3_hours_is_refused(): void
    {
        $this->claim($this->receipt('20', now()->subHours(3)->subMinute()->toIso8601String()))->assertStatus(422)->assertJson(['message' => 'receipt_too_old']);
        $this->claim($this->receipt('20', now()->subHours(2)->subMinutes(59)->toIso8601String()))->assertOk();
    }

    public function test_saudi_local_time_without_a_zone_is_understood(): void
    {
        $local = now('Asia/Riyadh')->subMinutes(10)->format('Y-m-d\TH:i:s');
        $this->claim($this->receipt('20', $local))->assertOk();
    }

    // A real Foodics receipt (Sugo pasta, 2026-10-17): printed 21:34 Saudi time but written as "21:34Z"
    public function test_foodics_receipt_with_saudi_time_marked_utc_is_accepted(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-17 21:46', 'Asia/Riyadh'));
        $this->shop->forceFill(['vat_number' => '311143435200003'])->save();
        $qr = ZatcaQr::make('Sugo pasta', '311143435200003', '2026-10-17T21:34Z', '1.00', '0.13');
        $this->assertSame('2026-10-17 21:34', ZatcaQr::parse($qr)['invoice_at']->tz('Asia/Riyadh')->format('Y-m-d H:i'));
        $this->claim($qr)->assertOk()->assertJson(['total' => 1, 'cashbackAmount' => 0.05]);
    }

    public function test_test_mode_lets_the_same_old_receipt_be_claimed_again(): void
    {
        config(['services.receipts.test_mode' => true]);
        $qr = $this->receipt('20', now()->subDays(3)->toIso8601String());
        $this->claim($qr)->assertOk();
        $this->claim($qr)->assertOk();
    }

    public function test_test_mode_has_no_daily_limit(): void
    {
        config(['services.receipts.test_mode' => true]);
        for ($i = 0; $i < 7; $i++) {
            $this->claim($this->receipt('12.00'))->assertOk();
        }
    }

    public function test_test_mode_matches_an_old_receipt_to_a_car_order_placed_now(): void
    {
        config(['services.receipts.test_mode' => true]);
        $this->carOrder();
        $old = $this->receipt('46.00', now()->subDays(2)->toIso8601String()); // e.g. the real receipt from earlier
        $this->claim($old)->assertStatus(422)->assertJson(['message' => 'receipt_car_order']);
    }

    public function test_test_mode_is_ignored_on_the_live_server(): void
    {
        config(['services.receipts.test_mode' => true]);
        $this->app['env'] = 'production';
        $qr = $this->receipt();
        $this->claim($qr)->assertOk();
        $this->claim($qr)->assertStatus(422)->assertJson(['message' => 'receipt_already_claimed']);
    }

    // The car order: 2 lattes with oat milk = 46, placed by the same phone as the receipt scanner
    private function carOrder(string $status = 'NEW'): int
    {
        $id = $this->placeOrder(46, '0512345678')->assertOk()->json('orderId');
        if ($status !== 'NEW') {
            $this->setStatus($id, $status);
        }
        return $id;
    }

    public function test_a_car_orders_receipt_earns_nothing(): void
    {
        $this->carOrder('DONE');
        $this->claim($this->receipt('46.00', now()->addMinutes(2)->toIso8601String()))->assertStatus(422)->assertJson(['message' => 'receipt_car_order']);
        // scanning it again still says it's a car order's receipt
        $this->claim($this->receipt('46.00', now()->addMinutes(2)->toIso8601String()))->assertStatus(422)->assertJson(['message' => 'receipt_car_order']);
    }

    public function test_only_one_receipt_is_matched_to_each_car_order(): void
    {
        $this->carOrder();
        $this->claim($this->receipt('46.00', now()->addMinutes(1)->toIso8601String()))->assertJson(['message' => 'receipt_car_order']);
        $this->claim($this->receipt('46.00', now()->addMinutes(3)->toIso8601String()))->assertOk(); // a second, genuine purchase
    }

    public function test_a_different_total_or_time_is_a_normal_receipt(): void
    {
        $this->carOrder();
        $this->claim($this->receipt('45.00', now()->toIso8601String()))->assertOk();                       // different total
        $this->claim($this->receipt('46.00', now()->subMinutes(11)->toIso8601String()))->assertOk();      // printed before the order
    }

    public function test_a_receipt_printed_more_than_an_hour_after_the_car_order_is_normal(): void
    {
        $this->carOrder();
        $this->travel(61)->minutes();
        $this->claim($this->receipt('46.00', now()->toIso8601String()))->assertOk();
    }

    public function test_even_a_rejected_car_order_blocks_its_receipt(): void
    {
        $this->carOrder('REJECTED'); // matched by customer, any car order counts
        $this->claim($this->receipt('46.00', now()->addMinutes(2)->toIso8601String()))->assertStatus(422)->assertJson(['message' => 'receipt_car_order']);
    }

    public function test_another_customers_car_order_does_not_block_the_receipt(): void
    {
        $this->placeOrder(46, '0599999999')->assertOk(); // matched by customer, as agreed
        $this->claim($this->receipt('46.00', now()->addMinutes(2)->toIso8601String()))->assertOk();
    }

    public function test_at_most_5_receipts_a_day(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->claim($this->receipt("1$i.00", now()->subMinutes($i)->toIso8601String()))->assertOk();
        }
        $this->claim($this->receipt('99.00'))->assertStatus(422)->assertJson(['message' => 'receipt_daily_limit']);
        $this->assertSame(5, ReceiptClaim::count());
    }

    public function test_a_phone_is_needed_first(): void
    {
        $device = $this->postJson('/api/v1/customer/device', ['menuId' => $this->shop->id])->json('customerToken');
        $this->claim($this->receipt(), $device)->assertStatus(422)->assertJson(['message' => 'phone_required']);
    }

    public function test_unreadable_codes_are_refused(): void
    {
        $this->claim('hello')->assertStatus(422)->assertJson(['message' => 'receipt_unreadable']);
        // still in the future even read as Saudi local time
        $this->claim($this->receipt('20', now()->addHours(4)->toIso8601String()))->assertStatus(422)->assertJson(['message' => 'receipt_unreadable']);
    }

    public function test_receipt_cashback_uses_the_wheel(): void
    {
        $this->shop->forceFill(['cashback_odds' => ['1' => 0, '4' => 1]])->save(); // always 4
        $this->claim($this->receipt('50.00'))->assertOk()->assertJson(['cashbackPercent' => 4, 'cashbackAmount' => 2]);
    }
}
