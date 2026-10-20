<?php

namespace App\Services;

use Carbon\Carbon;

// Reads the QR printed on Saudi simplified tax invoices (ZATCA e-invoicing): base64 of TLV records
//   1 seller name · 2 VAT number · 3 timestamp · 4 total incl. VAT · 5 VAT amount  (phase 2 adds 6-9: hash, signature, keys)
class ZatcaQr
{
    // Returns ['seller', 'vat_number', 'invoice_at' (Carbon|null), 'total' (float), 'vat' (float)] or null if it isn't a ZATCA QR
    public static function parse(?string $qr): ?array
    {
        $bytes = base64_decode(trim((string) $qr), true);
        if ($bytes === false || strlen($bytes) < 6) {
            return null;
        }

        $fields = [];
        $i = 0;
        $n = strlen($bytes);
        while ($i + 2 <= $n) {
            $tag = ord($bytes[$i]);
            $len = ord($bytes[$i + 1]);
            $i += 2;
            // Some printers use a long-form length (0x81 = one more length byte, 0x82 = two)
            if ($len === 0x81 && $i < $n) {
                $len = ord($bytes[$i]);
                $i += 1;
            } elseif ($len === 0x82 && $i + 1 < $n) {
                $len = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);
                $i += 2;
            }
            if ($i + $len > $n) {
                return null; // truncated or not TLV
            }
            $fields[$tag] = substr($bytes, $i, $len);
            $i += $len;
        }

        if (!isset($fields[2], $fields[4]) || !is_numeric($fields[4])) {
            return null;
        }

        return [
            'seller' => $fields[1] ?? '',
            'vat_number' => preg_replace('/\D/', '', $fields[2]),
            'invoice_at' => self::time($fields[3] ?? null),
            'total' => round((float) $fields[4], 2),
            'vat' => isset($fields[5]) && is_numeric($fields[5]) ? round((float) $fields[5], 2) : 0.0,
        ];
    }

    // Builds the same TLV QR (used by tests and the local test receipt)
    public static function make(string $seller, string $vatNumber, string $timestamp, string $total, string $vat): string
    {
        $tlv = '';
        foreach ([1 => $seller, 2 => $vatNumber, 3 => $timestamp, 4 => $total, 5 => $vat] as $tag => $value) {
            $tlv .= chr($tag) . chr(strlen($value)) . $value;
        }
        return base64_encode($tlv);
    }

    // Printers write ISO 8601. Without a time zone it's Saudi local time. Some printers (seen on Foodics receipts)
    // write Saudi local time but mark it "Z" (UTC): read literally it lands 3 hours in the future, so a time
    // that would be in the future is read as Saudi local time instead.
    private static function time(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }
        try {
            $hasZone = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value);
            $wallClock = preg_replace('/(Z|[+-]\d{2}:?\d{2})$/', '', $value);
            $saudiLocal = Carbon::parse($wallClock, 'Asia/Riyadh');
            if (!$hasZone) {
                return $saudiLocal;
            }
            $asWritten = Carbon::parse($value);
            return $asWritten->gt(now()->addMinutes(10)) ? $saudiLocal : $asWritten;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
