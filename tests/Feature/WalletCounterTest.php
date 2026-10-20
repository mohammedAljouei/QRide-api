<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\CounterToken;

// v2 counter: device wallets for first-time customers and the 30-second wallet QR code
class WalletCounterTest extends WalletTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWallet(5);
    }

    private function deviceToken(): string
    {
        return $this->postJson('/api/v1/customer/device', ['menuId' => $this->shop->id])->assertOk()->json('customerToken');
    }

    public function test_first_time_customer_gets_a_wallet_without_a_phone(): void
    {
        $token = $this->deviceToken();
        $this->assertNull(Customer::first()->phone);
        $this->getJson("/api/v1/shops/{$this->shop->id}/wallet", ['Authorization' => "Bearer $token"])
            ->assertJson(['balance' => 0, 'canSpend' => false]);
    }

    public function test_wallet_qr_code_names_the_customer_and_the_shop(): void
    {
        $token = $this->deviceToken();
        $res = $this->getJson("/api/v1/shops/{$this->shop->id}/wallet/qr", ['Authorization' => "Bearer $token"])
            ->assertOk()->assertJson(['expiresIn' => 30]);
        $this->assertSame(['customer_id' => Customer::first()->id, 'menu_id' => $this->shop->id], CounterToken::parse($res->json('code')));
    }

    public function test_a_changed_code_is_refused(): void
    {
        $code = CounterToken::make(5, $this->shop->id);
        [$c, $m, $e, $nonce, $sig] = explode('.', $code);
        $this->assertNull(CounterToken::parse("6.$m.$e.$nonce.$sig"));          // someone else's wallet
        $this->assertNull(CounterToken::parse("$c.$m." . ($e + 999) . ".$nonce.$sig")); // longer life
        $this->assertNotSame($code, CounterToken::make(5, $this->shop->id));     // every code is unique
        $this->assertNull(CounterToken::parse('garbage'));
    }

    public function test_an_old_screenshot_stops_working_after_30_seconds(): void
    {
        $code = CounterToken::make(5, $this->shop->id);
        $this->assertNotNull(CounterToken::parse($code));
        $this->travel(31)->seconds();
        $this->assertNull(CounterToken::parse($code));
    }

    public function test_qr_and_device_wallet_need_a_wallet_shop_and_a_customer(): void
    {
        $token = $this->deviceToken();
        $this->getJson("/api/v1/shops/{$this->shop->id}/wallet/qr")->assertUnauthorized();
        $this->getJson("/api/v1/shops/{$this->shop->id}/wallet/qr", ['Authorization' => "Bearer {$this->adminToken}"])->assertForbidden();
        $this->shop->forceFill(['wallet_enabled' => false])->save();
        $this->getJson("/api/v1/shops/{$this->shop->id}/wallet/qr", ['Authorization' => "Bearer $token"])->assertNotFound();
        $this->postJson('/api/v1/customer/device', ['menuId' => $this->shop->id])->assertNotFound();
    }
}
