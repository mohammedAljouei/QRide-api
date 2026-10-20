<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\CounterCharge;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\CounterToken;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// v2 counter: paying from the wallet at the till.
// The cashier scans the customer's wallet QR, asks for an amount, and the customer confirms on their phone.
class CounterController extends Controller
{
    public const CONFIRM_SECONDS = 120; // the customer has this long to confirm

    // ---------- cashier (staff login) ----------

    // What the cashier sees after scanning a wallet QR
    public function scan(Request $request)
    {
        [$menu, $customer, $error] = $this->fromToken($request);
        if ($error) {
            return $error;
        }
        $wallet = WalletService::walletFor($menu->id, $customer->id);
        return response()->json([
            'shopName' => $menu->name,
            'shopColor' => $menu->color, // the cashier's device may never have opened the shop's menu
            'maskedPhone' => $customer->phone ? '05•••••' . substr($customer->phone, -3) : null,
            'balance' => $wallet->balance(),
            'minBalance' => WalletService::minBalance($menu),
        ]);
    }

    public function createCharge(Request $request)
    {
        $request->validate(['amount' => 'required|numeric|min:0.01']);
        [$menu, $customer, $error] = $this->fromToken($request);
        if ($error) {
            return $error;
        }
        $amount = round((float) $request->amount, 2);
        $result = DB::transaction(function () use ($menu, $customer, $amount, $request) {
            $wallet = WalletService::walletFor($menu->id, $customer->id);
            Wallet::whereKey($wallet->id)->lockForUpdate()->first();
            $balance = $wallet->balance();
            if ($balance < WalletService::minBalance($menu)) {
                return 'wallet_below_minimum';
            }
            if ($balance < $amount) {
                return 'wallet_insufficient';
            }
            // A wallet QR can be used for one deduction only (a refused attempt doesn't use it up)
            if (!Cache::add('counter-token-used:' . sha1($request->t), 1, CounterToken::TTL_SECONDS + 5)) {
                return 'qr_used';
            }
            $charge = CounterCharge::create([
                'menu_id' => $menu->id, 'customer_id' => $customer->id, 'admin_id' => $request->user()->id,
                'amount' => $amount, 'status' => 'pending', 'expires_at' => now()->addSeconds(self::CONFIRM_SECONDS),
            ]);
            // Hold the money while the customer decides
            WalletTransaction::create([
                'wallet_id' => $wallet->id, 'type' => 'redeem', 'amount' => -$amount, 'status' => 'pending',
                'source_type' => 'counter', 'source_id' => $charge->id,
            ]);
            return $charge;
        });

        if (is_string($result)) {
            return $this->fail($result);
        }
        return response()->json(['chargeId' => $result->id, 'expiresIn' => self::CONFIRM_SECONDS]);
    }

    // The cashier's screen polls this until the customer answers
    public function chargeStatus(Request $request, CounterCharge $charge)
    {
        $admin = $request->user();
        if (!($admin instanceof Admin) || !Menu::where(['id' => $charge->menu_id, 'admin_id' => $admin->id])->exists()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $this->expireIfLate($charge);
        $balance = WalletService::walletFor($charge->menu_id, $charge->customer_id)->balance();
        return response()->json(['status' => $charge->status, 'amount' => $charge->amount, 'balance' => $balance]);
    }

    // ---------- customer ----------

    // The wallet QR screen polls this to show the cashier's request
    public function pending(Request $request, $menuId)
    {
        $customer = $request->user();
        if (!($customer instanceof Customer)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $charge = CounterCharge::where(['menu_id' => $menuId, 'customer_id' => $customer->id, 'status' => 'pending'])->latest('id')->first();
        if ($charge) {
            $this->expireIfLate($charge);
        }
        if (!$charge || $charge->status !== 'pending') {
            return response()->json(['charge' => null]);
        }
        return response()->json(['charge' => [
            'id' => $charge->id, 'amount' => $charge->amount, 'shopName' => Menu::where('id', $menuId)->value('name'),
            'expiresIn' => max(0, now()->diffInSeconds($charge->expires_at, false)),
        ]]);
    }

    public function respond(Request $request, CounterCharge $charge)
    {
        $request->validate(['action' => 'required|in:confirm,decline']);
        $customer = $request->user();
        if (!($customer instanceof Customer) || (int) $charge->customer_id !== (int) $customer->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($request->action === 'confirm' && !$customer->tokenCan('spend')) {
            return $this->fail('wallet_verify_required');
        }

        $status = DB::transaction(function () use ($charge, $request) {
            $charge = CounterCharge::whereKey($charge->id)->lockForUpdate()->first();
            $this->expireIfLate($charge);
            if ($charge->status !== 'pending') {
                return $charge->status;
            }
            $confirm = $request->action === 'confirm';
            WalletTransaction::where(['source_type' => 'counter', 'source_id' => $charge->id, 'type' => 'redeem', 'status' => 'pending'])
                ->update(['status' => $confirm ? 'posted' : 'void']);
            $charge->status = $confirm ? 'confirmed' : 'declined';
            $charge->save();
            return $charge->status;
        });

        $balance = WalletService::walletFor($charge->menu_id, $customer->id)->balance();
        return response()->json(['status' => $status, 'balance' => $balance]);
    }

    // ---------- helpers ----------

    // Reads the wallet QR code and checks it belongs to the logged-in cashier's shop
    private function fromToken(Request $request): array
    {
        $request->validate(['t' => 'required|string']);
        $admin = $request->user();
        if (!($admin instanceof Admin)) {
            return [null, null, response()->json(['message' => 'Unauthorized'], 403)];
        }
        $data = CounterToken::parse($request->t);
        if (!$data) {
            return [null, null, $this->fail('qr_expired')];
        }
        $menu = Menu::find($data['menu_id']);
        if (!$menu || (int) $menu->admin_id !== (int) $admin->id) {
            return [null, null, $this->fail('qr_other_shop')];
        }
        if (!$menu->wallet_enabled) {
            return [null, null, response()->json(['message' => 'Wallet is not available for this shop'], 404)];
        }
        $customer = Customer::find($data['customer_id']);
        if (!$customer) {
            return [null, null, $this->fail('qr_expired')];
        }
        return [$menu, $customer, null];
    }

    // A request nobody answered in time gives the money back
    private function expireIfLate(CounterCharge $charge): void
    {
        if ($charge->status === 'pending' && $charge->expires_at->isPast()) {
            WalletTransaction::where(['source_type' => 'counter', 'source_id' => $charge->id, 'type' => 'redeem', 'status' => 'pending'])
                ->update(['status' => 'void']);
            $charge->status = 'expired';
            $charge->save();
        }
    }

    private function fail(string $code)
    {
        return response()->json(['message' => $code], 422);
    }
}
