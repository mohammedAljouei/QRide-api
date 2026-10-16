<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\WalletTransaction;
use App\Services\WalletService;

// v2 step 2: verifying a phone and paying part of an order from the wallet
class WalletSpendTest extends WalletTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWallet(5);
    }

    // Puts money in a customer's wallet at the test shop
    private function giveBalance(float $amount, string $phone = '0512345678'): void
    {
        $customer = WalletService::customerForPhone($phone);
        WalletTransaction::create([
            'wallet_id' => WalletService::walletFor($this->shop->id, $customer->id)->id,
            'type' => 'earn', 'amount' => $amount, 'status' => 'posted', 'source_type' => 'test', 'source_id' => random_int(1, 1_000_000),
        ]);
    }

    private function verifiedToken(string $phone = '0512345678'): string
    {
        $requestId = $this->postJson('/api/v1/customer/otp/start', ['phone' => $phone, 'menuId' => $this->shop->id])->assertOk()->json('requestId');
        return $this->postJson('/api/v1/customer/otp/verify', ['requestId' => $requestId, 'code' => '1234'])->assertOk()->json('customerToken');
    }

    private function balance(string $token): float
    {
        return $this->getJson("/api/v1/shops/{$this->shop->id}/wallet", ['Authorization' => "Bearer $token"])->json('balance');
    }

    public function test_verifying_a_phone_gives_a_token_that_can_spend(): void
    {
        $token = $this->verifiedToken();
        $this->getJson("/api/v1/shops/{$this->shop->id}/wallet", ['Authorization' => "Bearer $token"])->assertJson(['canSpend' => true]);
        $this->assertNotNull(Customer::where('phone', '966512345678')->value('phone_verified_at'));
    }

    public function test_order_token_cannot_spend_until_verified(): void
    {
        $token = $this->placeOrder()->json('customerToken');
        $this->getJson("/api/v1/shops/{$this->shop->id}/wallet", ['Authorization' => "Bearer $token"])->assertJson(['canSpend' => false]);
        $this->giveBalance(10);
        $this->placeOrder(46, '0512345678', $token, 5)->assertStatus(422)->assertJson(['message' => 'wallet_verify_required']);
        $this->placeOrder(46, '0512345678', null, 5)->assertStatus(422)->assertJson(['message' => 'wallet_verify_required']);
    }

    public function test_wrong_code_fails_and_five_wrong_codes_kill_the_request(): void
    {
        $requestId = $this->postJson('/api/v1/customer/otp/start', ['phone' => '0512345678', 'menuId' => $this->shop->id])->json('requestId');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/customer/otp/verify', ['requestId' => $requestId, 'code' => '0000'])->assertStatus(422);
        }
        // Even the right code no longer works: a new SMS is needed
        $this->postJson('/api/v1/customer/otp/verify', ['requestId' => $requestId, 'code' => '1234'])->assertStatus(422);
    }

    public function test_otp_needs_a_valid_phone_and_a_wallet_shop(): void
    {
        $this->postJson('/api/v1/customer/otp/start', ['phone' => '12345', 'menuId' => $this->shop->id])->assertStatus(422);
        $this->shop->forceFill(['wallet_enabled' => false])->save();
        $this->postJson('/api/v1/customer/otp/start', ['phone' => '0512345678', 'menuId' => $this->shop->id])->assertNotFound();
    }

    public function test_fake_sms_is_refused_on_the_live_server(): void
    {
        $this->app['env'] = 'production';
        $this->postJson('/api/v1/customer/otp/start', ['phone' => '0512345678', 'menuId' => $this->shop->id])->assertStatus(503);
    }

    public function test_paying_from_the_wallet_holds_then_settles_with_cashback_on_the_rest(): void
    {
        $this->giveBalance(10);
        $token = $this->verifiedToken();

        $orderId = $this->placeOrder(46, '0512345678', $token, 5)->assertOk()->json('orderId');
        $this->assertEquals(5, Order::find($orderId)->wallet_amount);
        $this->assertEquals(5, $this->balance($token)); // 10 minus the 5 on hold

        $this->setStatus($orderId, 'DONE')->assertOk();
        // 10 - 5 spent + 5% cashback on the 41 paid in money (2.05)
        $this->assertEquals(7.05, $this->balance($token));
        $this->assertEquals(2.05, Order::find($orderId)->cashback_amount);
    }

    public function test_rejected_or_timed_out_order_gives_the_money_back(): void
    {
        $this->giveBalance(10);
        $token = $this->verifiedToken();

        $first = $this->placeOrder(46, '0512345678', $token, 10)->json('orderId');
        $this->assertEquals(0, $this->balance($token));
        $this->setStatus($first, 'REJECTED');
        $this->assertEquals(10, $this->balance($token));

        $second = $this->placeOrder(46, '0512345678', $token, 10)->json('orderId');
        $this->setStatus($second, 'TIMEOUT');
        $this->assertEquals(10, $this->balance($token));
        $this->assertSame(0, WalletTransaction::where('type', 'earn')->where('source_type', 'order')->count());
    }

    public function test_cannot_spend_more_than_the_balance(): void
    {
        $this->giveBalance(10);
        $token = $this->verifiedToken();
        $before = Order::count();

        $this->placeOrder(46, '0512345678', $token, 10.01)->assertStatus(422)->assertJson(['message' => 'wallet_insufficient']);
        $this->assertSame($before, Order::count()); // the order wasn't created
    }

    public function test_the_same_money_cannot_be_spent_twice(): void
    {
        $this->giveBalance(30);
        $token = $this->verifiedToken();

        $this->placeOrder(46, '0512345678', $token, 15)->assertOk();
        $this->placeOrder(46, '0512345678', $token, 16)->assertStatus(422)->assertJson(['message' => 'wallet_insufficient']);
        $this->assertEquals(15, $this->balance($token));
    }

    public function test_wallet_unlocks_at_10_riyals(): void
    {
        $token = $this->verifiedToken();
        $this->giveBalance(9.99);
        $this->getJson("/api/v1/shops/{$this->shop->id}/wallet", ['Authorization' => "Bearer $token"])->assertJson(['minBalance' => 10]);
        $this->placeOrder(46, '0512345678', $token, 5)->assertStatus(422)->assertJson(['message' => 'wallet_below_minimum']);

        $this->giveBalance(0.01); // exactly 10 now
        $this->placeOrder(46, '0512345678', $token, 5)->assertOk();
    }

    public function test_after_spending_below_10_the_wallet_locks_again(): void
    {
        $this->giveBalance(12);
        $token = $this->verifiedToken();
        $this->placeOrder(46, '0512345678', $token, 5)->assertOk(); // 7 left
        $this->placeOrder(46, '0512345678', $token, 5)->assertStatus(422)->assertJson(['message' => 'wallet_below_minimum']);
    }

    public function test_minimum_is_set_per_shop(): void
    {
        $this->shop->forceFill(['wallet_min_balance' => 20])->save();
        $this->giveBalance(15);
        $token = $this->verifiedToken();
        $this->placeOrder(46, '0512345678', $token, 5)->assertStatus(422)->assertJson(['message' => 'wallet_below_minimum']);
    }

    public function test_cannot_pay_more_than_the_order_from_the_wallet(): void
    {
        $this->giveBalance(100);
        $token = $this->verifiedToken();
        // the order is worth 46 on the server, even if the app claims 60
        $this->placeOrder(60, '0512345678', $token, 50)->assertStatus(422)->assertJson(['message' => 'wallet_amount_invalid']);
        $this->placeOrder(60, '0512345678', $token, 46)->assertOk();
    }

    public function test_a_fully_wallet_paid_order_earns_no_cashback(): void
    {
        $this->giveBalance(46);
        $token = $this->verifiedToken();
        $orderId = $this->placeOrder(46, '0512345678', $token, 46)->json('orderId');
        $this->setStatus($orderId, 'DONE');
        $this->assertEquals(0, $this->balance($token));
        $this->assertNull(Order::find($orderId)->cashback_amount);
    }

    public function test_spending_needs_the_shop_to_have_the_wallet_on(): void
    {
        $this->giveBalance(10);
        $token = $this->verifiedToken();
        $this->shop->forceFill(['wallet_enabled' => false])->save();
        $this->placeOrder(46, '0512345678', $token, 5)->assertStatus(422)->assertJson(['message' => 'wallet_verify_required']);
    }
}
