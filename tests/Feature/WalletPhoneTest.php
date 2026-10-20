<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\WalletTransaction;
use App\Services\WalletService;

// v2: adding a phone from the wallet card; with OTP switched off the phone counts as verified
class WalletPhoneTest extends WalletTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWallet(5);
        config(['services.otp.required' => false]);
    }

    private function addPhone(string $phone, ?string $token = null)
    {
        return $this->postJson('/api/v1/customer/phone', ['phone' => $phone, 'menuId' => $this->shop->id], $token ? ['Authorization' => "Bearer $token"] : []);
    }

    private function wallet(string $token)
    {
        return $this->getJson("/api/v1/shops/{$this->shop->id}/wallet", ['Authorization' => "Bearer $token"]);
    }

    public function test_with_otp_off_an_entered_phone_can_spend_right_away(): void
    {
        $token = $this->addPhone('0512345678')->assertOk()->json('customerToken');
        $this->wallet($token)->assertJson(['hasPhone' => true, 'canSpend' => true]);
        $this->assertNotNull(Customer::where('phone', '966512345678')->value('phone_verified_at'));
    }

    public function test_a_known_phone_gets_its_existing_balance(): void
    {
        $customer = WalletService::customerForPhone('0512345678');
        WalletTransaction::create(['wallet_id' => WalletService::walletFor($this->shop->id, $customer->id)->id, 'type' => 'earn', 'amount' => 12, 'status' => 'posted', 'source_type' => 'test', 'source_id' => 1]);
        $token = $this->addPhone('+966 51 234 5678')->json('customerToken');
        $this->wallet($token)->assertJson(['balance' => 12]);
        $this->assertSame(1, Customer::count());
    }

    public function test_a_device_wallet_without_phone_keeps_its_wallet_when_the_phone_is_added(): void
    {
        $device = $this->postJson('/api/v1/customer/device', ['menuId' => $this->shop->id])->json('customerToken');
        $this->wallet($device)->assertJson(['hasPhone' => false]);
        $deviceCustomer = Customer::first();

        $token = $this->addPhone('0512345678', $device)->json('customerToken');
        $this->assertSame($deviceCustomer->id, Customer::where('phone', '966512345678')->value('id'));
        $this->wallet($token)->assertJson(['hasPhone' => true]);
    }

    public function test_car_order_login_can_spend_when_otp_is_off(): void
    {
        $token = $this->placeOrder()->json('customerToken');
        $this->wallet($token)->assertJson(['canSpend' => true]);
    }

    public function test_with_otp_on_an_entered_phone_still_needs_the_sms_code_to_spend(): void
    {
        config(['services.otp.required' => true]);
        $token = $this->addPhone('0512345678')->json('customerToken');
        $this->wallet($token)->assertJson(['hasPhone' => true, 'canSpend' => false]);
        $this->assertNull(Customer::first()->phone_verified_at);
    }

    public function test_invalid_phone_and_wallet_off_shop_are_refused(): void
    {
        $this->addPhone('12345')->assertStatus(422)->assertJson(['message' => 'invalid_phone']);
        $this->shop->forceFill(['wallet_enabled' => false])->save();
        $this->addPhone('0512345678')->assertNotFound();
    }
}
