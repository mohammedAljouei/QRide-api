<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;

// v2: one-time codes through Twilio Verify (requests faked; no SMS is sent)
class WalletTwilioTest extends WalletTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWallet(5);
        config(['services.otp.driver' => 'twilio', 'services.twilio.sid' => 'ACtest', 'services.twilio.token' => 'secret', 'services.twilio.verify_sid' => 'VAtest']);
    }

    public function test_sends_with_twilio_and_checks_the_code_for_the_same_phone(): void
    {
        Http::fake([
            'verify.twilio.com/v2/Services/VAtest/Verifications' => Http::response(['sid' => 'VE123', 'status' => 'pending']),
            'verify.twilio.com/v2/Services/VAtest/VerificationCheck' => fn ($request) => Http::response([
                'status' => $request['Code'] === '482913' && $request['To'] === '+966512345678' ? 'approved' : 'pending',
            ]),
        ]);

        $requestId = $this->postJson('/api/v1/customer/otp/start', ['phone' => '0512345678', 'menuId' => $this->shop->id])->assertOk()->json('requestId');
        $this->postJson('/api/v1/customer/otp/verify', ['requestId' => $requestId, 'code' => '111111'])->assertStatus(422);
        $this->postJson('/api/v1/customer/otp/verify', ['requestId' => $requestId, 'code' => '482913'])->assertOk()->assertJsonStructure(['customerToken']);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/Verifications') && $r['To'] === '+966512345678' && $r['Channel'] === 'sms'
            && $r->hasHeader('Authorization', 'Basic ' . base64_encode('ACtest:secret')));
    }

    public function test_twilio_down_gives_a_clear_error(): void
    {
        Http::fake(['verify.twilio.com/*' => Http::response(['message' => 'down'], 500)]);
        $this->postJson('/api/v1/customer/otp/start', ['phone' => '0512345678', 'menuId' => $this->shop->id])->assertStatus(503)->assertJson(['message' => 'otp_failed']);
    }
}
