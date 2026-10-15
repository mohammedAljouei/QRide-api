<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// v2 wallet: one-time SMS codes. The phone each code was sent to is remembered on the server,
// so a code can only ever verify the number it was sent to.
class OtpService
{
    private const TTL_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;

    // Sends a code to a normalised phone (9665XXXXXXXX) and returns the request id the app sends back
    public static function start(string $phone): string
    {
        if (self::driver() === 'log') {
            $requestId = 'test-' . Str::random(24);
            Log::info("OTP (log driver) for $phone: " . config('services.otp.test_code'));
        } else {
            $client = new \Vonage\Client(new \Vonage\Client\Credentials\Basic(config('services.vonage.key'), config('services.vonage.secret')));
            $response = $client->verify()->start(new \Vonage\Verify\Request($phone, config('services.vonage.brand')));
            $requestId = $response->getRequestId();
        }

        Cache::put(self::key($requestId), $phone, now()->addMinutes(self::TTL_MINUTES));
        return $requestId;
    }

    // Returns the phone the code was sent to if the code is right, otherwise null. A request id works once.
    public static function check(string $requestId, string $code): ?string
    {
        $phone = Cache::get(self::key($requestId));
        if (!$phone) {
            return null;
        }

        if (self::driver() === 'log') {
            $ok = hash_equals((string) config('services.otp.test_code'), $code);
        } else {
            try {
                $client = new \Vonage\Client(new \Vonage\Client\Credentials\Basic(config('services.vonage.key'), config('services.vonage.secret')));
                $client->verify()->check($requestId, $code); // throws unless the code is right
                $ok = true;
            } catch (\Throwable $e) {
                $ok = false; // wrong code, expired or too many attempts
            }
        }

        if ($ok) {
            Cache::forget(self::key($requestId));
            return $phone;
        }

        // Too many wrong codes: this request is dead, the customer has to ask for a new SMS
        Cache::add(self::key($requestId) . ':attempts', 0, now()->addMinutes(self::TTL_MINUTES));
        if (Cache::increment(self::key($requestId) . ':attempts') >= self::MAX_ATTEMPTS) {
            Cache::forget(self::key($requestId));
        }
        return null;
    }

    private static function driver(): string
    {
        $driver = config('services.otp.driver');
        // The fake driver accepts a fixed code, so it must never run on the live server
        if ($driver === 'log' && app()->environment('production')) {
            throw new \RuntimeException('OTP_DRIVER=log is not allowed in production');
        }
        return $driver;
    }

    private static function key(string $requestId): string
    {
        return 'customer-otp:' . $requestId;
    }
}
