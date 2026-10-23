<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\WalletTransaction;
use App\Services\WalletService;

// An order nobody accepts within 3 minutes is timed out by the server, whatever the customer's phone does
class OrderTimeoutTest extends WalletTestCase
{
    private function orderStatus(int $id)
    {
        return $this->getJson("/api/v1/orders/status/$id")->assertOk();
    }

    public function test_the_status_counts_down_then_times_out(): void
    {
        $id = $this->placeOrder()->json('orderId');
        $this->orderStatus($id)->assertJson(['status' => 'NEW', 'secondsLeft' => 180]);

        $this->travel(100)->seconds();
        $this->orderStatus($id)->assertJson(['status' => 'NEW', 'secondsLeft' => 80]);

        $this->travel(81)->seconds();
        $this->orderStatus($id)->assertJson(['status' => 'TIMEOUT'])->assertJsonMissingPath('secondsLeft');
        $this->assertSame('TIMEOUT', Order::find($id)->status);
    }

    public function test_an_accepted_order_never_times_out(): void
    {
        $id = $this->placeOrder()->json('orderId');
        $this->setStatus($id, 'ACCEPTED');
        $this->travel(10)->minutes();
        $this->orderStatus($id)->assertJson(['status' => 'ACCEPTED'])->assertJsonMissingPath('secondsLeft');
    }

    public function test_a_timed_out_order_gives_the_wallet_amount_back(): void
    {
        $this->enableWallet(5);
        $requestId = $this->postJson('/api/v1/customer/otp/start', ['phone' => '0512345678', 'menuId' => $this->shop->id])->json('requestId');
        $token = $this->postJson('/api/v1/customer/otp/verify', ['requestId' => $requestId, 'code' => '1234'])->json('customerToken');
        WalletTransaction::create([
            'wallet_id' => WalletService::walletFor($this->shop->id, WalletService::customerForPhone('0512345678')->id)->id,
            'type' => 'earn', 'amount' => 30, 'status' => 'posted', 'source_type' => 'test', 'source_id' => 1,
        ]);
        $id = $this->placeOrder(46, '0512345678', $token, 20)->assertOk()->json('orderId');
        $this->assertEquals(10, $this->balance($token)); // 20 held

        $this->travel(181)->seconds();
        $this->orderStatus($id)->assertJson(['status' => 'TIMEOUT']);
        $this->assertEquals(30, $this->balance($token));
        $this->orderStatus($id); // asked again: released only once
        $this->assertEquals(30, $this->balance($token));
    }

    private function balance(string $token): float
    {
        return $this->getJson("/api/v1/shops/{$this->shop->id}/wallet", ['Authorization' => "Bearer $token"])->json('balance');
    }

    public function test_the_staff_list_times_out_orders_the_customer_left(): void
    {
        $id = $this->placeOrder()->json('orderId');
        $this->travel(4)->minutes();
        $this->postJson("/api/v1/orders/filter/{$this->shop->id}", ['NEW' => true], ['Authorization' => "Bearer {$this->adminToken}"])->assertOk();
        $this->assertSame('TIMEOUT', Order::find($id)->status);
    }

    public function test_the_old_timeout_call_still_works(): void
    {
        $id = $this->placeOrder()->json('orderId');
        $this->postJson("/api/v1/orders/validateTimeout/$id")->assertJson(['message' => 'Order has not been inactive long enough to timeout.']);
        $this->travel(181)->seconds();
        $this->postJson("/api/v1/orders/validateTimeout/$id")->assertJson(['message' => 'Order status updated to TIMEOUT due to inactivity.']);
        $this->postJson("/api/v1/orders/validateTimeout/$id")->assertStatus(422);
    }
}
