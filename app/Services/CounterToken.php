<?php

namespace App\Services;

// v2 counter: the code inside the customer's wallet QR. It names the customer and the shop, expires after
// 30 seconds and is signed with the app key, so it can't be guessed, changed or reused from an old screenshot.
class CounterToken
{
    public const TTL_SECONDS = 30;

    public static function make(int $customerId, int $menuId): string
    {
        // The random part makes every code unique, so one code = one deduction
        $payload = $customerId . '.' . $menuId . '.' . (now()->getTimestamp() + self::TTL_SECONDS) . '.' . bin2hex(random_bytes(4));
        return $payload . '.' . self::sign($payload);
    }

    // Returns ['customer_id' => .., 'menu_id' => ..] for a valid, unexpired code; null otherwise
    public static function parse(?string $code): ?array
    {
        $parts = explode('.', (string) $code);
        if (count($parts) !== 5) {
            return null;
        }
        [$customerId, $menuId, $expires, $nonce, $signature] = $parts;
        $payload = "$customerId.$menuId.$expires.$nonce";
        if (!hash_equals(self::sign($payload), $signature) || (int) $expires < now()->getTimestamp()) {
            return null;
        }
        return ['customer_id' => (int) $customerId, 'menu_id' => (int) $menuId];
    }

    private static function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', 'counter|' . $payload, (string) config('app.key')), 0, 32);
    }
}
