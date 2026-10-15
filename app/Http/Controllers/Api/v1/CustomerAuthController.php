<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\OtpService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// v2 wallet: a customer verifies their phone once per device before they can spend their balance
class CustomerAuthController extends Controller
{
    public function startOtp(Request $request)
    {
        $request->validate(['phone' => 'required|string', 'menuId' => 'required|integer']);

        if (!WalletService::enabledFor($request->menuId)) {
            return response()->json(['message' => 'Wallet is not available for this shop'], 404);
        }
        $phone = WalletService::normalizePhone($request->phone);
        if (!$phone) {
            return response()->json(['message' => 'invalid_phone'], 422);
        }

        try {
            return response()->json(['requestId' => OtpService::start($phone)]);
        } catch (\Throwable $e) {
            Log::error('OTP start failed: ' . $e->getMessage());
            return response()->json(['message' => 'otp_failed'], 503);
        }
    }

    public function verifyOtp(Request $request)
    {
        $request->validate(['requestId' => 'required|string', 'code' => 'required|string']);

        $phone = OtpService::check($request->requestId, $request->code);
        if (!$phone) {
            return response()->json(['message' => 'wrong_code'], 422);
        }

        $customer = Customer::firstOrCreate(['phone' => $phone]);
        $customer->phone_verified_at = now();
        $customer->save();

        // 'spend' is only ever given after a verified code
        return response()->json(['customerToken' => $customer->createToken('wallet', ['customer', 'spend'])->plainTextToken]);
    }
}
