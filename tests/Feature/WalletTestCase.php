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
use App\Models\SuperAdmin;
use App\Models\WalletTransaction;
use Tests\Support\LegacySchema;
use Tests\TestCase;

// Shared setup for the v2 wallet tests: one shop with a latte (20) and an oat milk add-on (3)
abstract class WalletTestCase extends TestCase
{
    use LegacySchema;

    protected Menu $shop;
    protected Meal $latte;
    protected string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();

        $admin = Admin::create(['name' => 'Staff', 'email' => 'staff@qride.net', 'password' => 'secret']);
        $this->adminToken = $admin->createToken('authToken', ['admin'])->plainTextToken;

        $owner = SuperAdmin::create(['name' => 'Owner', 'email' => 'owner@qride.net', 'password' => 'secret']);

        $this->shop = $this->makeShop($admin->id, $owner->id, 'Cafe');
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

    // Live columns are NOT NULL, so a shop needs all of them (as onboarding sets them)
    protected function makeShop(int $adminId, int $ownerId, string $name): Menu
    {
        return Menu::create([
            'admin_id' => $adminId, 'super_admin_id' => $ownerId, 'version' => 1, 'status' => 'active',
            'name' => $name, 'slogan' => '', 'color' => '#0F766E',
            'payment_methods' => ['cash' => true, 'Machine' => true, 'applePay' => false], 'platforms' => [],
        ]);
    }

    protected function enableWallet(float $percent = 5): void
    {
        $this->shop->forceFill(['wallet_enabled' => true, 'cashback_percent' => $percent])->save();
    }

    // 2 lattes with oat milk = (20 + 3) x 2 = 46 on the server
    protected function placeOrder(float $clientTotal = 46, string $phone = '0512345678', ?string $token = null, float $walletAmount = 0)
    {
        $headers = $token ? ['Authorization' => "Bearer $token"] : [];
        return $this->postJson('/api/v1/place-order', [
            'wallet' => ['amount' => $walletAmount],
            'menuId' => $this->shop->id,
            'note' => 'any', // what the app sends by default
            'carInfo' => ['carSize' => 'mid', 'carColor' => '#000000'],
            'userInfo' => ['phone' => $phone],
            'orderInfo' => ['meals' => [['mealId' => $this->latte->id, 'price' => 23, 'count' => 2, 'addOnsItems' => ['Oat']]]],
            'paymentInfo' => ['paymentMethod' => 'cash', 'paymentId' => '-', 'totalPrice' => $clientTotal],
        ], $headers);
    }

    protected function setStatus(int $orderId, string $status)
    {
        return $this->postJson("/api/v1/orders/status/$orderId", ['status' => $status], ['Authorization' => "Bearer {$this->adminToken}"]);
    }

}
