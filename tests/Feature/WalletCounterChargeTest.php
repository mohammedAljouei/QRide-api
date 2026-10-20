<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CounterCharge;
use App\Models\WalletTransaction;
use App\Services\WalletService;

// v2 counter: the cashier deducts from the wallet and the customer confirms on their phone
class WalletCounterChargeTest extends WalletTestCase
{
    private string $customerToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWallet(5);
        config(['services.otp.required' => false]);
        $this->customerToken = $this->postJson('/api/v1/customer/phone', ['phone' => '0512345678', 'menuId' => $this->shop->id])->json('customerToken');
        $customer = WalletService::customerForPhone('0512345678');
        WalletTransaction::create(['wallet_id' => WalletService::walletFor($this->shop->id, $customer->id)->id, 'type' => 'earn', 'amount' => 20, 'status' => 'posted', 'source_type' => 'test', 'source_id' => 1]);
    }

    private function walletCode(): string
    {
        return $this->getJson("/api/v1/shops/{$this->shop->id}/wallet/qr", ['Authorization' => "Bearer {$this->customerToken}"])->json('code');
    }

    private function asStaff(string $method, string $uri, array $data = [], ?string $token = null)
    {
        return $this->json($method, $uri, $data, ['Authorization' => 'Bearer ' . ($token ?? $this->adminToken)]);
    }

    private function asCustomer(string $method, string $uri, array $data = [])
    {
        return $this->json($method, $uri, $data, ['Authorization' => "Bearer {$this->customerToken}"]);
    }

    private function balance(): float
    {
        return $this->asCustomer('GET', "/api/v1/shops/{$this->shop->id}/wallet")->json('balance');
    }

    public function test_cashier_sees_masked_phone_and_balance(): void
    {
        $this->asStaff('GET', '/api/v1/counter/scan?t=' . urlencode($this->walletCode()))
            ->assertOk()->assertJson(['maskedPhone' => '05•••••678', 'balance' => 20, 'minBalance' => 10]);
    }

    public function test_deduction_waits_for_the_customer_then_is_taken(): void
    {
        $chargeId = $this->asStaff('POST', '/api/v1/counter/charges', ['t' => $this->walletCode(), 'amount' => 12])->assertOk()->json('chargeId');
        $this->assertEquals(8, $this->balance()); // held

        $pending = $this->asCustomer('GET', "/api/v1/shops/{$this->shop->id}/counter/pending")->json('charge');
        $this->assertEquals(12, $pending['amount']);

        $this->asCustomer('POST', "/api/v1/counter/charges/$chargeId/respond", ['action' => 'confirm'])->assertOk()->assertJson(['status' => 'confirmed', 'balance' => 8]);
        $this->asStaff('GET', "/api/v1/counter/charges/$chargeId")->assertJson(['status' => 'confirmed', 'balance' => 8]);
    }

    public function test_declined_or_unanswered_gives_the_money_back(): void
    {
        $first = $this->asStaff('POST', '/api/v1/counter/charges', ['t' => $this->walletCode(), 'amount' => 12])->json('chargeId');
        $this->asCustomer('POST', "/api/v1/counter/charges/$first/respond", ['action' => 'decline'])->assertJson(['status' => 'declined']);
        $this->assertEquals(20, $this->balance());

        $second = $this->asStaff('POST', '/api/v1/counter/charges', ['t' => $this->walletCode(), 'amount' => 12])->json('chargeId');
        $this->travel(121)->seconds();
        $this->asStaff('GET', "/api/v1/counter/charges/$second")->assertJson(['status' => 'expired']);
        $this->assertEquals(20, $this->balance());
        $this->asCustomer('POST', "/api/v1/counter/charges/$second/respond", ['action' => 'confirm'])->assertJson(['status' => 'expired']);
        $this->assertEquals(20, $this->balance());
    }

    public function test_one_wallet_qr_one_deduction(): void
    {
        $code = $this->walletCode();
        $this->asStaff('POST', '/api/v1/counter/charges', ['t' => $code, 'amount' => 2])->assertOk();
        $this->asStaff('POST', '/api/v1/counter/charges', ['t' => $code, 'amount' => 2])->assertStatus(422)->assertJson(['message' => 'qr_used']);
    }

    public function test_cannot_deduct_more_than_the_balance_or_below_10(): void
    {
        $this->asStaff('POST', '/api/v1/counter/charges', ['t' => $this->walletCode(), 'amount' => 25])->assertStatus(422)->assertJson(['message' => 'wallet_insufficient']);
        $this->asStaff('POST', '/api/v1/counter/charges', ['t' => $this->walletCode(), 'amount' => 15])->assertOk(); // held: 5 left
        $this->asStaff('POST', '/api/v1/counter/charges', ['t' => $this->walletCode(), 'amount' => 2])->assertStatus(422)->assertJson(['message' => 'wallet_below_minimum']);
    }

    public function test_an_old_wallet_qr_is_refused(): void
    {
        $code = $this->walletCode();
        $this->travel(31)->seconds();
        $this->asStaff('GET', '/api/v1/counter/scan?t=' . urlencode($code))->assertStatus(422)->assertJson(['message' => 'qr_expired']);
    }

    public function test_another_shops_cashier_cannot_use_the_qr(): void
    {
        $other = Admin::create(['name' => 'Other', 'email' => 'other@qride.net', 'password' => 'secret']);
        $otherToken = $other->createToken('authToken', ['admin'])->plainTextToken;
        $this->asStaff('GET', '/api/v1/counter/scan?t=' . urlencode($this->walletCode()), [], $otherToken)->assertStatus(422)->assertJson(['message' => 'qr_other_shop']);
    }

    public function test_customers_cannot_use_the_cashier_endpoints_or_others_charges(): void
    {
        $this->asCustomer('GET', '/api/v1/counter/scan?t=' . urlencode($this->walletCode()))->assertForbidden();
        $chargeId = $this->asStaff('POST', '/api/v1/counter/charges', ['t' => $this->walletCode(), 'amount' => 5])->json('chargeId');
        $stranger = $this->postJson('/api/v1/customer/phone', ['phone' => '0599999999', 'menuId' => $this->shop->id])->json('customerToken');
        $this->json('POST', "/api/v1/counter/charges/$chargeId/respond", ['action' => 'confirm'], ['Authorization' => "Bearer $stranger"])->assertForbidden();
        $this->assertSame('pending', CounterCharge::find($chargeId)->status);
    }

    public function test_with_otp_on_an_unverified_phone_cannot_confirm(): void
    {
        config(['services.otp.required' => true]);
        $token = $this->postJson('/api/v1/customer/phone', ['phone' => '0512345678', 'menuId' => $this->shop->id])->json('customerToken');
        $chargeId = $this->asStaff('POST', '/api/v1/counter/charges', ['t' => $this->walletCode(), 'amount' => 5])->json('chargeId');
        $this->json('POST', "/api/v1/counter/charges/$chargeId/respond", ['action' => 'confirm'], ['Authorization' => "Bearer $token"])->assertStatus(422)->assertJson(['message' => 'wallet_verify_required']);
    }
}
