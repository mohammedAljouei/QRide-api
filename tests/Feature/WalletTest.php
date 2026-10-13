<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AddOnInfo;
use App\Models\AddOnTitle;
use App\Models\Customer;
use App\Models\Meal;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Section;
use App\Models\WalletTransaction;
use Tests\Support\LegacySchema;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use LegacySchema;

    private Menu $shop;
    private Meal $latte;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();

        $admin = Admin::create(['name' => 'Staff', 'email' => 'staff@qride.net', 'password' => 'secret']);
        $this->adminToken = $admin->createToken('authToken', ['admin'])->plainTextToken;

        $this->shop = Menu::create(['admin_id' => $admin->id, 'super_admin_id' => 1, 'version' => 1, 'status' => 'active', 'name' => 'Cafe']);
        $section = Section::create(['menu_id' => $this->shop->id, 'name' => 'Coffee', 'sort' => 1]);
        $this->latte = Meal::create(['section_id' => $section->id, 'name' => 'Latte', 'price' => 20, 'status' => 1, 'sort' => 1]);
        $milk = AddOnTitle::create(['meal_id' => $this->latte->id, 'title' => 'Milk', 'min' => 0, 'max' => 1]);
        AddOnInfo::create(['add_on_title_id' => $milk->id, 'name' => 'Oat', 'price' => 3]);
    }

    // Each request authenticates from scratch, like a real one (the test app otherwise remembers the last user)
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $this->app['auth']->forgetGuards();
        return parent::json($method, $uri, $data, $headers, $options);
    }

    private function enableWallet(float $percent = 5): void
    {
        $this->shop->forceFill(['wallet_enabled' => true, 'cashback_percent' => $percent])->save();
    }

    // 2 lattes with oat milk = (20 + 3) x 2 = 46 on the server
    private function placeOrder(float $clientTotal = 46, string $phone = '0512345678', ?string $token = null)
    {
        $headers = $token ? ['Authorization' => "Bearer $token"] : [];
        return $this->postJson('/api/v1/place-order', [
            'menuId' => $this->shop->id,
            'carInfo' => ['carSize' => 'mid', 'carColor' => '#000000'],
            'userInfo' => ['phone' => $phone],
            'orderInfo' => ['meals' => [['mealId' => $this->latte->id, 'price' => 23, 'count' => 2, 'addOnsItems' => ['Oat']]]],
            'paymentInfo' => ['paymentMethod' => 'cash', 'paymentId' => '-', 'totalPrice' => $clientTotal],
        ], $headers);
    }

    private function setStatus(int $orderId, string $status)
    {
        return $this->postJson("/api/v1/orders/status/$orderId", ['status' => $status], ['Authorization' => "Bearer {$this->adminToken}"]);
    }

    public function test_shop_without_wallet_behaves_exactly_like_v1(): void
    {
        $res = $this->placeOrder()->assertOk();
        $this->assertSame(['orderId'], array_keys($res->json()));

        $this->setStatus($res->json('orderId'), 'DONE')->assertOk();

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, WalletTransaction::count());
        $this->getJson("/api/v1/menu/{$this->shop->id}/shop-info")->assertJson(['walletEnabled' => false, 'cashbackPercent' => 0]);
        $this->getJson('/api/v1/orders/status/' . $res->json('orderId'))->assertExactJson(['status' => 'DONE']);
    }

    public function test_order_earns_cashback_when_done(): void
    {
        $this->enableWallet(5);
        $res = $this->placeOrder()->assertOk();
        $orderId = $res->json('orderId');
        $token = $res->json('customerToken');
        $this->assertNotEmpty($token);

        $order = Order::find($orderId);
        $this->assertEquals(46, $order->server_total);
        $this->assertSame('966512345678', Customer::find($order->customer_id)->phone);

        // Nothing is credited before the order is done
        $this->getJson("/api/v1/shops/{$this->shop->id}/wallet", ['Authorization' => "Bearer $token"])->assertJson(['balance' => 0]);

        $this->setStatus($orderId, 'DONE')->assertOk();

        $this->getJson("/api/v1/orders/status/$orderId")->assertJson(['status' => 'DONE', 'cashbackAmount' => 2.3]);
        $this->getJson("/api/v1/shops/{$this->shop->id}/wallet", ['Authorization' => "Bearer $token"])
            ->assertJson(['balance' => 2.3, 'cashbackPercent' => 5])
            ->assertJsonCount(1, 'transactions');
        $this->getJson("/api/v1/menu/{$this->shop->id}/shop-info")->assertJson(['walletEnabled' => true, 'cashbackPercent' => 5]);
    }

    public function test_cashback_uses_server_total_when_client_inflates_the_price(): void
    {
        $this->enableWallet(10);
        $orderId = $this->placeOrder(1000)->json('orderId');
        $this->setStatus($orderId, 'DONE');
        $this->assertEquals(4.6, Order::find($orderId)->cashback_amount); // 10% of 46, not of 1000
    }

    public function test_cashback_uses_client_total_when_it_is_lower(): void
    {
        $this->enableWallet(10);
        $orderId = $this->placeOrder(30)->json('orderId');
        $this->setStatus($orderId, 'DONE');
        $this->assertEquals(3, Order::find($orderId)->cashback_amount); // never more than what was charged
    }

    public function test_an_order_earns_only_once(): void
    {
        $this->enableWallet(5);
        $orderId = $this->placeOrder()->json('orderId');
        $this->setStatus($orderId, 'DONE');
        $this->setStatus($orderId, 'ACCEPTED');
        $this->setStatus($orderId, 'DONE');
        $this->assertSame(1, WalletTransaction::count());
    }

    public function test_rejected_order_earns_nothing(): void
    {
        $this->enableWallet(5);
        $orderId = $this->placeOrder()->json('orderId');
        $this->setStatus($orderId, 'REJECTED');
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_device_keeps_its_token_and_balances_add_up(): void
    {
        $this->enableWallet(5);
        $first = $this->placeOrder();
        $token = $first->json('customerToken');
        $second = $this->placeOrder(46, '0512345678', $token);
        $this->assertNull($second->json('customerToken'));

        $this->setStatus($first->json('orderId'), 'DONE');
        $this->setStatus($second->json('orderId'), 'DONE');
        $this->getJson("/api/v1/shops/{$this->shop->id}/wallet", ['Authorization' => "Bearer $token"])->assertJson(['balance' => 4.6]);
    }

    public function test_wallet_is_per_shop(): void
    {
        $this->enableWallet(5);
        $token = $this->placeOrder()->json('customerToken');
        $other = Menu::create(['admin_id' => 1, 'super_admin_id' => 1, 'version' => 1, 'status' => 'active']);
        $other->forceFill(['wallet_enabled' => true, 'cashback_percent' => 5])->save();

        $this->setStatus(Order::first()->id, 'DONE');
        $this->getJson("/api/v1/shops/{$other->id}/wallet", ['Authorization' => "Bearer $token"])->assertJson(['balance' => 0]);
    }

    public function test_invalid_phone_still_places_the_order(): void
    {
        $this->enableWallet(5);
        $res = $this->placeOrder(46, '12345')->assertOk();
        $this->assertNull($res->json('customerToken'));
        $this->assertSame(0, Customer::count());
    }

    public function test_customer_token_cannot_use_staff_endpoints(): void
    {
        $this->enableWallet(5);
        $token = $this->placeOrder()->json('customerToken');
        $this->postJson("/api/v1/meals/status/{$this->latte->id}", [], ['Authorization' => "Bearer $token"])->assertForbidden();
        $this->postJson('/api/v1/orders/status/' . Order::first()->id, ['status' => 'DONE'], ['Authorization' => "Bearer $token"])->assertForbidden();
        $this->assertSame('1', (string) $this->latte->fresh()->status);
    }

    public function test_wallet_needs_a_customer_token(): void
    {
        $this->enableWallet(5);
        $this->getJson("/api/v1/shops/{$this->shop->id}/wallet")->assertUnauthorized();
        $this->getJson("/api/v1/shops/{$this->shop->id}/wallet", ['Authorization' => "Bearer {$this->adminToken}"])->assertForbidden();
    }

    // "Revert to QRide v1": rolling back v2 removes the wallet and leaves v1 data untouched
    public function test_v2_rollback_restores_the_v1_schema(): void
    {
        $this->enableWallet(5);
        $orderId = $this->placeOrder()->json('orderId');
        $this->setStatus($orderId, 'DONE');

        \Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_10_13_000000_qride_v2_wallet.php']);

        foreach (['customers', 'wallets', 'wallet_transactions'] as $table) {
            $this->assertFalse(\Schema::hasTable($table));
        }
        $this->assertFalse(\Schema::hasColumn('menus', 'wallet_enabled'));
        $this->assertFalse(\Schema::hasColumn('orders', 'cashback_amount'));
        $this->assertSame(0, \DB::table('personal_access_tokens')->where('tokenable_type', Customer::class)->count());
        $this->assertSame(1, \DB::table('personal_access_tokens')->where('tokenable_type', Admin::class)->count());
        $this->assertSame('DONE', \DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame('Cafe', \DB::table('menus')->value('name'));
    }

    public function test_wallet_is_hidden_when_shop_has_it_off(): void
    {
        $this->enableWallet(5);
        $token = $this->placeOrder()->json('customerToken');
        $this->shop->forceFill(['wallet_enabled' => false])->save();
        $this->getJson("/api/v1/shops/{$this->shop->id}/wallet", ['Authorization' => "Bearer $token"])->assertNotFound();
    }
}
