<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\WalletService;

// v2 step 2.5: the cashback wheel. The server draws the percent; the app only reveals it.
class WalletOddsTest extends WalletTestCase
{
    private const ODDS = ['1' => 0, '2' => 0, '3' => 0, '4' => 15, '5' => 85];

    private function enableWheel(array $odds = self::ODDS): void
    {
        $this->shop->forceFill(['wallet_enabled' => true, 'cashback_percent' => 5, 'cashback_odds' => $odds])->save();
    }

    public function test_app_gets_the_wheel_but_never_the_chances(): void
    {
        $this->enableWheel();
        $res = $this->getJson("/api/v1/menu/{$this->shop->id}/shop-info")
            ->assertJson(['walletEnabled' => true, 'cashbackPercent' => 5, 'cashbackWheel' => [1, 2, 3, 4, 5]]);
        $this->assertStringNotContainsString('85', $res->getContent());
        $this->assertStringNotContainsString('15', $res->getContent());
    }

    public function test_zero_chance_percents_are_never_drawn_and_85_15_holds(): void
    {
        $this->enableWheel();
        $counts = [];
        for ($i = 0; $i < 2000; $i++) {
            $p = (string) WalletService::drawPercent($this->shop->fresh());
            $counts[$p] = ($counts[$p] ?? 0) + 1;
        }
        $this->assertEquals([4, 5], collect(array_keys($counts))->sort()->values()->all());
        $this->assertEqualsWithDelta(0.85, $counts['5'] / 2000, 0.04);
    }

    public function test_order_keeps_its_drawn_percent_and_earns_with_it(): void
    {
        $this->enableWheel(['1' => 0, '4' => 1]); // always 4
        $orderId = $this->placeOrder()->json('orderId');
        $this->getJson("/api/v1/orders/status/$orderId")->assertJson(['status' => 'NEW', 'cashbackPercent' => 4]);

        // changing the shop's chances later doesn't change an order already placed
        $this->enableWheel(['5' => 1]);
        $this->setStatus($orderId, 'DONE');
        $this->assertEquals(1.84, Order::find($orderId)->cashback_amount); // 4% of 46
    }

    public function test_shop_without_chances_keeps_its_fixed_percent(): void
    {
        $this->enableWallet(5);
        $this->getJson("/api/v1/menu/{$this->shop->id}/shop-info")->assertJson(['cashbackPercent' => 5, 'cashbackWheel' => null]);
        $orderId = $this->placeOrder()->json('orderId');
        $this->getJson("/api/v1/orders/status/$orderId")->assertJson(['cashbackPercent' => 5]);
    }

    public function test_all_zero_chances_fall_back_to_the_fixed_percent(): void
    {
        $this->enableWheel(['1' => 0, '5' => 0]);
        $this->assertSame(5.0, WalletService::drawPercent($this->shop->fresh()));
    }

    public function test_wallet_paid_order_also_gets_a_draw(): void
    {
        $this->enableWheel(['3' => 0, '5' => 1]);
        $customer = WalletService::customerForPhone('0512345678');
        \App\Models\WalletTransaction::create(['wallet_id' => WalletService::walletFor($this->shop->id, $customer->id)->id, 'type' => 'earn', 'amount' => 10, 'status' => 'posted', 'source_type' => 'test', 'source_id' => 1]);
        $rid = $this->postJson('/api/v1/customer/otp/start', ['phone' => '0512345678', 'menuId' => $this->shop->id])->json('requestId');
        $token = $this->postJson('/api/v1/customer/otp/verify', ['requestId' => $rid, 'code' => '1234'])->json('customerToken');

        $orderId = $this->placeOrder(46, '0512345678', $token, 6)->assertOk()->json('orderId');
        $this->setStatus($orderId, 'DONE');
        $this->assertEquals(2, Order::find($orderId)->cashback_amount); // 5% of the 40 paid in money
    }
}
