<?php

namespace Tests\Feature;

use App\Models\AddOnInfo;
use App\Models\AddOnTitle;
use App\Models\Meal;
use App\Models\Section;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Http\UploadedFile;

// The branch manager's menu editor: their own shop only, meals saved together with their add-ons
class ManagerMenuTest extends WalletTestCase
{
    private string $token;
    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploads = sys_get_temp_dir() . '/qride-uploads-' . uniqid();
        config(['services.qride.uploads_path' => $this->uploads, 'services.qride.uploads_url' => 'https://qride.net/img/uploads']);
        $this->token = $this->postJson('/api/login-super-admin', ['email' => 'owner@qride.net', 'password' => 'secret'])->json('token');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploads . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->uploads);
        parent::tearDown();
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/manager/{$this->shop->id}$path";
    }

    private function as(?string $token = null): array
    {
        return ['Authorization' => 'Bearer ' . ($token ?? $this->token)];
    }

    private function meal(array $over = []): array
    {
        return array_replace([
            'sectionId' => Section::first()->id, 'name' => 'Flat white', 'description' => 'Double shot', 'price' => 18,
            'image' => '', 'available' => true,
            'addOns' => [['title' => 'Milk', 'min' => 1, 'max' => 1, 'items' => [['name' => 'Full', 'price' => 0], ['name' => 'Almond', 'price' => 4]]]],
        ], $over);
    }

    public function test_shows_the_menu_with_meals_and_add_ons(): void
    {
        $this->getJson($this->url('/menu'), $this->as())->assertOk()
            ->assertJsonPath('shop.id', $this->shop->id)
            ->assertJsonPath('sections.0.name', 'Coffee')
            ->assertJsonPath('sections.0.meals.0.name', 'Latte')
            ->assertJsonPath('sections.0.meals.0.addOns.0.items.0', ['name' => 'Oat', 'price' => 3]);
    }

    public function test_only_this_shops_branch_manager(): void
    {
        $other = SuperAdmin::create(['name' => 'Other', 'email' => 'other@qride.net', 'password' => 'secret']);
        $otherToken = $other->createToken('authToken', ['superAdmin'])->plainTextToken;
        $this->getJson($this->url('/menu'), $this->as($otherToken))->assertStatus(403);
        $this->postJson($this->url('/sections'), ['name' => 'X'], $this->as($otherToken))->assertStatus(403);
        $this->getJson($this->url('/menu'), $this->as($this->adminToken))->assertStatus(403); // staff can't edit the menu
        $this->getJson($this->url('/menu'))->assertStatus(401);
    }

    public function test_sections_add_rename_order_delete(): void
    {
        $this->postJson($this->url('/sections'), ['name' => 'Sweets'], $this->as())->assertStatus(201)->assertJsonPath('sections.1.name', 'Sweets');
        $sweets = Section::where('name', 'Sweets')->first();
        $coffee = Section::where('name', 'Coffee')->first();
        $this->postJson($this->url("/sections/{$sweets->id}"), ['name' => 'Desserts'], $this->as())->assertJsonPath('sections.1.name', 'Desserts');
        $this->postJson($this->url('/sections/order'), ['ids' => [$sweets->id, $coffee->id]], $this->as())->assertJsonPath('sections.0.name', 'Desserts');
        $this->postJson($this->url('/sections/order'), ['ids' => [$sweets->id]], $this->as())->assertStatus(422); // must list them all

        // deleting a section takes its meals and their add-ons with it
        $this->deleteJson($this->url("/sections/{$coffee->id}"), [], $this->as())->assertOk()->assertJsonCount(1, 'sections');
        $this->assertSame(0, Meal::count());
        $this->assertSame(0, AddOnTitle::count());
        $this->assertSame(0, AddOnInfo::count());
    }

    public function test_a_meal_and_its_add_ons_are_saved_together(): void
    {
        $this->postJson($this->url('/meals'), $this->meal(), $this->as())->assertStatus(201)
            ->assertJsonPath('sections.0.meals.1.name', 'Flat white')
            ->assertJsonPath('sections.0.meals.1.addOns.0.items.1', ['name' => 'Almond', 'price' => 4]);
        $meal = Meal::where('name', 'Flat white')->first();

        $this->postJson($this->url("/meals/{$meal->id}"), $this->meal(['price' => 19.5, 'addOns' => []]), $this->as())->assertOk()
            ->assertJsonPath('sections.0.meals.1.price', 19.5)
            ->assertJsonPath('sections.0.meals.1.addOns', []);
        $this->assertSame(1, AddOnTitle::count()); // only the latte's milk is left

        // and the order total uses the new price and add-ons (worked out on the server for wallet shops)
        $this->enableWallet(5);
        $this->postJson($this->url("/meals/{$meal->id}"), $this->meal(), $this->as())->assertOk();
        $r = $this->postJson('/api/v1/place-order', [
            'menuId' => $this->shop->id, 'note' => 'any', 'carInfo' => ['carSize' => 'mid', 'carColor' => '#000000'], 'userInfo' => ['phone' => '0512345678'],
            'orderInfo' => ['meals' => [['mealId' => $meal->id, 'price' => 22, 'count' => 1, 'addOnsItems' => ['Almond']]]],
            'paymentInfo' => ['paymentMethod' => 'cash', 'paymentId' => '-', 'totalPrice' => 22],
        ])->assertOk();
        $this->assertEquals(22, \App\Models\Order::find($r->json('orderId'))->server_total);
    }

    public function test_bad_meals_are_refused(): void
    {
        $this->postJson($this->url('/meals'), $this->meal(['name' => '']), $this->as())->assertStatus(422);
        $this->postJson($this->url('/meals'), $this->meal(['price' => -1]), $this->as())->assertStatus(422);
        $this->postJson($this->url('/meals'), $this->meal(['image' => 'javascript:alert(1)']), $this->as())->assertStatus(422);
        $this->postJson($this->url('/meals'), $this->meal(['addOns' => [['title' => 'Milk', 'min' => 3, 'max' => 2, 'items' => [['name' => 'A', 'price' => 0]]]]]), $this->as())->assertStatus(422)->assertJson(['message' => 'addon_limits']);
        $this->postJson($this->url('/meals'), $this->meal(['addOns' => [
            ['title' => 'Milk', 'min' => 0, 'max' => 1, 'items' => [['name' => 'Oat', 'price' => 0]]],
            ['title' => 'Extra', 'min' => 0, 'max' => 1, 'items' => [['name' => 'oat', 'price' => 2]]],
        ]]), $this->as())->assertStatus(422)->assertJson(['message' => 'addon_duplicate']);

        // a section of another shop
        $otherShop = $this->makeShop($this->shop->admin_id, SuperAdmin::create(['name' => 'O', 'email' => 'o2@q.net', 'password' => 'x'])->id, 'Other');
        $foreign = Section::create(['menu_id' => $otherShop->id, 'name' => 'Theirs', 'sort' => 1]);
        $this->postJson($this->url('/meals'), $this->meal(['sectionId' => $foreign->id]), $this->as())->assertStatus(422)->assertJson(['message' => 'section_invalid']);
        $this->assertSame(1, Meal::count());
    }

    public function test_hide_order_and_delete_meals(): void
    {
        $this->postJson($this->url('/meals'), $this->meal(), $this->as());
        $section = Section::first();
        [$latte, $flat] = [Meal::where('name', 'Latte')->first(), Meal::where('name', 'Flat white')->first()];

        $this->postJson($this->url("/meals/{$latte->id}/available"), ['available' => false], $this->as())->assertJsonPath('sections.0.meals.0.available', false);
        $this->postJson($this->url("/sections/{$section->id}/order"), ['ids' => [$flat->id, $latte->id]], $this->as())->assertJsonPath('sections.0.meals.0.name', 'Flat white');
        $this->deleteJson($this->url("/meals/{$flat->id}"), [], $this->as())->assertOk()->assertJsonCount(1, 'sections.0.meals');
        $this->assertSame(1, AddOnTitle::count());
    }

    public function test_photo_upload_becomes_a_jpeg_of_at_most_1200px(): void
    {
        $url = $this->post($this->url('/images'), ['image' => UploadedFile::fake()->image('big.png', 2400, 1600)], $this->as() + ['Accept' => 'application/json'])
            ->assertStatus(201)->json('url');
        $this->assertStringStartsWith('https://qride.net/img/uploads/m' . $this->shop->id . '-', $url);
        $info = getimagesize($this->uploads . '/' . basename($url));
        $this->assertSame([1200, 800, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);

        $this->post($this->url('/images'), ['image' => UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;')], $this->as() + ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_qride_panel_opens_the_menu_editor_as_the_branch_manager(): void
    {
        $qride = User::create(['name' => 'Admin', 'email' => 'admin@qride.net', 'password' => 'x'])->createToken('authToken', ['qrideAdmin'])->plainTextToken;
        $token = $this->postJson("/api/v1/qride/shops/{$this->shop->id}/portal", ['who' => 'owner'], $this->as($qride))->assertOk()->json('token');
        $this->getJson($this->url('/menu'), $this->as($token))->assertOk();
    }
}
