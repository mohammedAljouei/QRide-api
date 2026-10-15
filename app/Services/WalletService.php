<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Meal;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

// QRide v2: cashback + wallet. Every amount is calculated here, on the server.
class WalletService
{
    public static function enabledFor($menuId): bool
    {
        return (bool) Menu::where('id', $menuId)->value('wallet_enabled');
    }

    // "05XXXXXXXX", "5XXXXXXXX", "+9665XXXXXXXX" -> "9665XXXXXXXX"; null if it isn't a Saudi mobile
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        $digits = preg_replace('/^(00966|966|0)/', '', $digits);
        return preg_match('/^5\d{8}$/', $digits) ? '966' . $digits : null;
    }

    public static function customerForPhone(string $phone): ?Customer
    {
        $normalized = self::normalizePhone($phone);
        return $normalized ? Customer::firstOrCreate(['phone' => $normalized]) : null;
    }

    public static function walletFor($menuId, $customerId): Wallet
    {
        return Wallet::firstOrCreate(['menu_id' => $menuId, 'customer_id' => $customerId]);
    }

    // Rebuilds the order total from database prices: meal price + chosen add-on prices, times the count
    public static function serverTotal(array $meals): float
    {
        $total = 0;
        foreach ($meals as $item) {
            $meal = Meal::with('addOnTitles.addOnInfos')->find($item['mealId'] ?? null);
            if (!$meal) {
                continue;
            }
            $unit = (float) $meal->price;
            $options = $meal->addOnTitles->flatMap->addOnInfos;
            foreach ($item['addOnsItems'] ?? [] as $name) {
                $option = $options->firstWhere('name', $name);
                $unit += $option ? (float) $option->price : 0;
            }
            $total += $unit * max(0, (int) ($item['count'] ?? 1));
        }
        return round($total, 2);
    }

    public static function cashbackFor(Menu $menu, float $amount): float
    {
        return round(max(0, $amount) * (float) $menu->cashback_percent / 100, 2);
    }

    // The most this order can be charged: never more than the menu prices say, never more than the app showed
    public static function payable(Order $order): float
    {
        return round(min((float) $order->total_price, (float) ($order->server_total ?? $order->total_price)), 2);
    }

    // Puts a hold on the customer's balance for an order that was just placed.
    // Throws if the balance isn't enough; the caller rolls back the order.
    public static function holdForOrder(Order $order, Customer $customer, float $amount): WalletTransaction
    {
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > self::payable($order)) {
            throw new \RuntimeException('wallet_amount_invalid');
        }

        $wallet = self::walletFor($order->menu_id, $customer->id);
        Wallet::whereKey($wallet->id)->lockForUpdate()->first(); // two orders can't spend the same money
        if ($wallet->balance() < $amount) {
            throw new \RuntimeException('wallet_insufficient');
        }

        $order->customer_id = $customer->id;
        $order->wallet_amount = $amount;
        $order->save();

        return WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'redeem',
            'amount' => -$amount,
            'status' => 'pending',
            'source_type' => 'order',
            'source_id' => $order->id,
        ]);
    }

    // Order DONE: the hold becomes a real deduction, then cashback is credited on the money actually paid
    public static function settleOrder(Order $order): void
    {
        WalletTransaction::where(['source_type' => 'order', 'source_id' => $order->id, 'type' => 'redeem', 'status' => 'pending'])
            ->update(['status' => 'posted']);
        self::earnForOrder($order);
    }

    // Order REJECTED / TIMEOUT: the held amount goes back to the customer
    public static function releaseOrder(Order $order): void
    {
        WalletTransaction::where(['source_type' => 'order', 'source_id' => $order->id, 'type' => 'redeem', 'status' => 'pending'])
            ->update(['status' => 'void']);
    }

    // Credits the order's cashback once it's DONE. Safe to call more than once: an order earns only once.
    public static function earnForOrder(Order $order): ?WalletTransaction
    {
        $menu = Menu::find($order->menu_id);
        if (!$menu || !$menu->wallet_enabled || !$order->customer_id) {
            return null;
        }

        // Cashback only on what was paid in money, not on the part paid from the wallet
        $paid = self::payable($order) - (float) $order->wallet_amount;
        $amount = self::cashbackFor($menu, $paid);
        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($order, $menu, $amount) {
            $wallet = self::walletFor($menu->id, $order->customer_id);
            Wallet::whereKey($wallet->id)->lockForUpdate()->first();

            $existing = WalletTransaction::where(['source_type' => 'order', 'source_id' => $order->id, 'type' => 'earn'])->first();
            if ($existing) {
                return $existing;
            }

            $order->cashback_amount = $amount;
            $order->save();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'earn',
                'amount' => $amount,
                'status' => 'posted',
                'source_type' => 'order',
                'source_id' => $order->id,
            ]);
        });
    }
}
