<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Http\Request;

// QRide v2: the customer's wallet at one shop
class WalletController extends Controller
{
    public function show(Request $request, $menuId)
    {
        $customer = $request->user();
        if (!($customer instanceof Customer) || !$customer->tokenCan('customer')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $menu = Menu::find($menuId);
        if (!$menu || !$menu->wallet_enabled) {
            return response()->json(['message' => 'Wallet is not available for this shop'], 404);
        }

        $wallet = Wallet::where(['menu_id' => $menu->id, 'customer_id' => $customer->id])->first();

        return response()->json([
            'balance' => $wallet ? $wallet->balance() : 0,
            'cashbackPercent' => (float) $menu->cashback_percent,
            'canSpend' => $customer->tokenCan('spend'), // false = the app asks for an SMS code first
            'minBalance' => WalletService::minBalance($menu), // the wallet can be used once balance reaches this
            'transactions' => $wallet
                ? $wallet->transactions()->where('status', 'posted')->latest('id')->limit(20)
                    ->get(['type', 'amount', 'source_type', 'created_at'])
                : [],
        ]);
    }
}
