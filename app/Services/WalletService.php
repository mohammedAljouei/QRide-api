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

    public static function cashbackFor(Menu $menu, float $amount, ?float $percent = null): float
    {
        return round(max(0, $amount) * ($percent ?? (float) $menu->cashback_percent) / 100, 2);
    }

    // Balance the customer needs before the wallet can be used at this shop
    public static function minBalance(?Menu $menu): float
    {
        return $menu ? max(0, (float) ($menu->wallet_min_balance ?? 10)) : 10;
    }

    // The shop's chances as whole weights: {"4":15,"5":85} -> ["4" => 1500, "5" => 8500].
    // Weights are relative (they don't need to add up to 100) and decimals are kept exactly (85.5 -> 8550).
    // Entries that aren't a positive percent with a number for a chance are ignored. Empty = fixed cashback_percent.
    public static function odds(Menu $menu): array
    {
        $odds = [];
        foreach ((array) $menu->cashback_odds as $percent => $chance) {
            if (!is_numeric($percent) || !is_numeric($chance) || (float) $percent <= 0) {
                continue;
            }
            $odds[(string) (float) $percent] = max(0, (int) round((float) $chance * 100));
        }
        ksort($odds, SORT_NUMERIC);
        return array_sum($odds) > 0 ? $odds : [];
    }

    // Every percent shown on the wheel, including ones with 0 chance
    public static function wheel(Menu $menu): array
    {
        $odds = self::odds($menu);
        return $odds ? array_map('floatval', array_keys($odds)) : [(float) $menu->cashback_percent];
    }

    // Draws this order's percent on the server. $pick is a uniform random whole number from 1 to the sum of
    // the weights (random_int, a secure generator); tests pass every possible pick to prove the odds exactly.
    public static function drawPercent(Menu $menu, ?int $pick = null): float
    {
        $odds = self::odds($menu);
        if (!$odds) {
            return (float) $menu->cashback_percent;
        }
        $pick ??= random_int(1, array_sum($odds));
        return self::percentForPick($odds, $pick);
    }

    // Lays the weights end to end on 1..sum and returns the percent whose stretch contains $pick.
    // A percent with weight 0 has an empty stretch, so no pick can ever land on it.
    public static function percentForPick(array $odds, int $pick): float
    {
        $upTo = 0;
        foreach ($odds as $percent => $weight) {
            $upTo += $weight;
            if ($weight > 0 && $pick <= $upTo) {
                return (float) $percent;
            }
        }
        throw new \LogicException('pick out of range'); // only if $pick > sum of weights, which random_int never gives
    }

    // Gives a wallet order its drawn percent once
    public static function drawForOrder(Order $order): void
    {
        $menu = Menu::find($order->menu_id);
        if ($menu && $menu->wallet_enabled && $order->customer_id && $order->cashback_percent === null) {
            $order->cashback_percent = self::drawPercent($menu);
            $order->save();
        }
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
        $balance = $wallet->balance();
        if ($balance < self::minBalance(Menu::find($order->menu_id))) {
            throw new \RuntimeException('wallet_below_minimum'); // the wallet unlocks at the shop's minimum (10 by default)
        }
        if ($balance < $amount) {
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
        $amount = self::cashbackFor($menu, $paid, $order->cashback_percent !== null ? (float) $order->cashback_percent : null);
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
