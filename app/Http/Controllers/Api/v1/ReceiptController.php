<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Order;
use App\Models\ReceiptClaim;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use App\Services\ZatcaQr;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// v2 counter: the customer scans the ZATCA QR on their receipt and the cashback is added straight away
class ReceiptController extends Controller
{
    public const MAX_TOTAL = 1000;      // receipts above this are refused
    public const DAILY_LIMIT = 5;       // receipts per customer per shop per day
    public const MAX_AGE_HOURS = 3;     // a receipt can be claimed this long after it was printed

    public function claim(Request $request, $menuId)
    {
        $request->validate(['qr' => 'required|string|max:2000']);
        $customer = $request->user();
        if (!($customer instanceof Customer) || !$customer->tokenCan('customer')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $menu = Menu::find($menuId);
        if (!$menu || !$menu->wallet_enabled) {
            return response()->json(['message' => 'Wallet is not available for this shop'], 404);
        }
        if ($customer->phone === null) {
            return $this->fail('phone_required');
        }

        $receipt = ZatcaQr::parse($request->qr);
        if (!$receipt || !$receipt['invoice_at'] || $receipt['total'] <= 0) {
            return $this->fail('receipt_unreadable');
        }
        $testMode = (bool) $menu->is_test; // QRide's test shop: any receipt, again and again
        if (!$testMode && (!$menu->vat_number || preg_replace('/\D/', '', $menu->vat_number) !== $receipt['vat_number'])) {
            return $this->fail('receipt_other_shop');
        }
        if ($receipt['total'] > self::MAX_TOTAL) {
            return $this->fail('receipt_too_large');
        }
        if (!$testMode && $receipt['invoice_at']->lt(now()->subHours(self::MAX_AGE_HOURS))) {
            return $this->fail('receipt_too_old');
        }
        if (!$testMode && $receipt['invoice_at']->gt(now()->addMinutes(10))) {
            return $this->fail('receipt_unreadable'); // printed "in the future": not a real receipt
        }

        $fingerprint = hash('sha256', implode('|', [$receipt['vat_number'], $receipt['invoice_at']->getTimestamp(), $receipt['total'], $receipt['vat']]));
        if ($testMode) {
            $fingerprint = hash('sha256', $fingerprint . '|test|' . bin2hex(random_bytes(8))); // every test claim is new
        }

        try {
            $result = DB::transaction(function () use ($customer, $menu, $receipt, $fingerprint, $testMode) {
                $wallet = WalletService::walletFor($menu->id, $customer->id);
                Wallet::whereKey($wallet->id)->lockForUpdate()->first();

                $existing = ReceiptClaim::where('fingerprint', $fingerprint)->first();
                if ($existing) {
                    return $existing->order_id ? 'receipt_car_order' : 'receipt_already_claimed';
                }

                // A car order's receipt: its cashback is added automatically, so the receipt earns nothing.
                // Same customer, same total, printed between 10 min before and 60 min after the order was placed;
                // each car order matches one receipt only. (Test shop: the receipt counts as printed now.)
                $carOrder = self::matchingCarOrder($menu->id, $customer->id, $receipt, $testMode ? now() : $receipt['invoice_at']);
                if ($carOrder) {
                    ReceiptClaim::create([
                        'menu_id' => $menu->id, 'customer_id' => $customer->id, 'fingerprint' => $fingerprint,
                        'vat_number' => $receipt['vat_number'], 'invoice_at' => $receipt['invoice_at'], 'total' => $receipt['total'],
                        'cashback_percent' => 0, 'cashback_amount' => 0, 'order_id' => $carOrder->id,
                    ]);
                    return 'receipt_car_order';
                }
                $todayStart = now('Asia/Riyadh')->startOfDay()->utc();
                $today = ReceiptClaim::where(['menu_id' => $menu->id, 'customer_id' => $customer->id])->where('created_at', '>=', $todayStart)->count();
                if (!$testMode && $today >= self::DAILY_LIMIT) {
                    return 'receipt_daily_limit';
                }

                $percent = WalletService::drawPercent($menu);
                $amount = WalletService::cashbackFor($menu, $receipt['total'], $percent);
                $claim = ReceiptClaim::create([
                    'menu_id' => $menu->id, 'customer_id' => $customer->id, 'fingerprint' => $fingerprint,
                    'vat_number' => $receipt['vat_number'], 'invoice_at' => $receipt['invoice_at'], 'total' => $receipt['total'],
                    'cashback_percent' => $percent, 'cashback_amount' => $amount,
                ]);
                if ($amount > 0) {
                    WalletTransaction::create([
                        'wallet_id' => $wallet->id, 'type' => 'earn', 'amount' => $amount, 'status' => 'posted',
                        'source_type' => 'receipt', 'source_id' => $claim->id,
                    ]);
                }
                return ['claim' => $claim, 'balance' => $wallet->balance()];
            });
        } catch (QueryException $e) {
            return $this->fail('receipt_already_claimed'); // two scans of the same receipt at the same moment
        }

        if (is_string($result)) {
            return $this->fail($result);
        }
        return response()->json([
            'total' => $result['claim']->total,
            'cashbackPercent' => $result['claim']->cashback_percent,
            'cashbackAmount' => $result['claim']->cashback_amount,
            'balance' => $result['balance'],
        ]);
    }

    private static function matchingCarOrder(int $menuId, int $customerId, array $receipt, \Carbon\Carbon $printed): ?Order
    {
        return Order::where(['menu_id' => $menuId, 'customer_id' => $customerId])
            ->whereBetween('created_at', [$printed->copy()->subMinutes(60), $printed->copy()->addMinutes(10)])
            ->whereNotIn('id', ReceiptClaim::whereNotNull('order_id')->select('order_id'))
            ->orderBy('created_at')
            ->get()
            ->first(function (Order $order) use ($receipt) {
                // the amount charged for the order (what the till rings up)
                foreach ([$order->total_price, $order->server_total] as $total) {
                    if ($total !== null && abs((float) $total - $receipt['total']) < 0.005) {
                        return true;
                    }
                }
                return false;
            });
    }

    private function fail(string $code)
    {
        return response()->json(['message' => $code], 422);
    }
}
