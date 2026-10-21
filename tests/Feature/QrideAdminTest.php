<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Menu;
use App\Models\SuperAdmin;
use App\Models\Timeslot;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// The QRide admin panel: login, onboarding a shop in one go, editing it
class QrideAdminTest extends WalletTestCase
{
    private string $qrideToken;
    private string $icons;

    protected function setUp(): void
    {
        parent::setUp();
        $this->icons = sys_get_temp_dir() . '/qride-icons-' . uniqid();
        config(['services.qride.icons_path' => $this->icons]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@qride.net', 'password' => 'right-password']);
        $this->qrideToken = $user->createToken('authToken', ['qrideAdmin'])->plainTextToken;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->icons . '/{,.}*.png', GLOB_BRACE) ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->icons);
        parent::tearDown();
    }

    private function form(array $over = []): array
    {
        return array_replace_recursive([
            'name' => 'New Cafe', 'slogan' => 'Fresh', 'color' => '#b2262d',
            'payment' => ['cash' => true, 'Machine' => true, 'applePay' => false],
            'platforms' => ['Instagram' => 'https://instagram.com/newcafe'],
            'owner' => ['name' => 'Owner', 'email' => 'new.owner@cafe.sa', 'password' => 'owner-pass-1'],
            'staff' => ['name' => 'Staff', 'email' => 'new.staff@cafe.sa', 'password' => 'staff-pass-1'],
            'hours' => ['Sunday' => [['from' => '06:00', 'to' => '02:00']], 'Monday' => [['from' => '08:00', 'to' => '12:00'], ['from' => '16:00', 'to' => '23:00']]],
            'wallet' => ['enabled' => true, 'odds' => ['1' => 0, '2' => 0, '3' => 0, '4' => 15, '5' => 85], 'minBalance' => 10, 'vatNumber' => '311143435200003', 'isTest' => false],
        ], $over);
    }

    private function logo(): UploadedFile
    {
        return UploadedFile::fake()->image('logo.jpg', 300, 200);
    }

    private function create(array $form, ?UploadedFile $logo = null, ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $files = $logo ? ['logo' => $logo] : [];
        return $this->call('POST', '/api/v1/qride/shops', ['data' => json_encode($form)], [], $files,
            ['HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->qrideToken), 'HTTP_ACCEPT' => 'application/json']);
    }

    public function test_login_hashes_an_old_plain_text_password_once(): void
    {
        DB::table('users')->insert(['name' => 'Old', 'email' => 'old@qride.net', 'password' => 'plain-one']);
        $this->postJson('/api/login-qride-admin', ['email' => 'old@qride.net', 'password' => 'wrong'])->assertStatus(422);
        $this->postJson('/api/login-qride-admin', ['email' => 'old@qride.net', 'password' => 'plain-one'])->assertOk()->assertJsonStructure(['token']);

        $stored = DB::table('users')->where('email', 'old@qride.net')->value('password');
        $this->assertNotSame('plain-one', $stored);
        $this->assertTrue(Hash::check('plain-one', $stored));
        // and it keeps working with the hash
        $this->postJson('/api/login-qride-admin', ['email' => 'old@qride.net', 'password' => 'plain-one'])->assertOk();
        $this->postJson('/api/login-qride-admin', ['email' => 'old@qride.net', 'password' => $stored])->assertStatus(422);
    }

    public function test_only_qride_admins_can_use_the_panel(): void
    {
        $this->getJson('/api/v1/qride/shops', ['Authorization' => "Bearer {$this->adminToken}"])->assertStatus(403);
        $owner = SuperAdmin::first()->createToken('authToken', ['superAdmin'])->plainTextToken;
        $this->getJson('/api/v1/qride/shops', ['Authorization' => "Bearer $owner"])->assertStatus(403);
        $this->getJson('/api/v1/qride/shops')->assertStatus(401);
        $this->getJson('/api/v1/qride/shops', ['Authorization' => "Bearer {$this->qrideToken}"])->assertOk()->assertJsonFragment(['name' => 'Cafe']);
    }

    public function test_creates_the_whole_shop_in_one_go(): void
    {
        $r = $this->create($this->form(), $this->logo())->assertStatus(201);
        $id = $r->json('id');
        $menu = Menu::find($id);

        $this->assertSame('#B2262D', $menu->color);
        $this->assertSame('open', $menu->status);
        $this->assertTrue($menu->wallet_enabled);
        $this->assertFalse($menu->is_test);
        $this->assertEquals(5, $menu->cashback_percent);
        $this->assertSame([1 => 0, 2 => 0, 3 => 0, 4 => 15, 5 => 85], $menu->cashback_odds);
        $this->assertSame(['link' => 'https://instagram.com/newcafe', 'status' => true], $menu->platforms['Instagram']);
        $this->assertSame(['link' => '', 'status' => false], $menu->platforms['TikTok']);

        // the accounts can log in with the passwords given (stored hashed)
        $this->postJson('/api/login-super-admin', ['email' => 'new.owner@cafe.sa', 'password' => 'owner-pass-1'])->assertOk()->assertJson(['menuId' => $id]);
        $this->postJson('/api/login-admin', ['email' => 'new.staff@cafe.sa', 'password' => 'staff-pass-1'])->assertOk()->assertJson(['menuId' => $id]);

        // 06:00-02:00 on Sunday is stored as Sunday until 23:59 + Monday from 00:00
        $slots = Timeslot::where('menu_id', $id)->orderBy('id')->get(['day', 'start_time', 'end_time'])->map(fn ($t) => "$t->day " . substr($t->start_time, 0, 5) . '-' . substr($t->end_time, 0, 5))->all();
        $this->assertSame(['Sunday 06:00-23:59', 'Monday 00:00-02:00', 'Monday 08:00-12:00', 'Monday 16:00-23:00'], $slots);

        // the logo: a real 512x512 PNG named after the shop
        $info = getimagesize("{$this->icons}/$id.png");
        $this->assertSame([512, 512, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);

        // the wallet works straight away
        $this->getJson("/api/v1/menu/$id/shop-info")->assertJson(['walletEnabled' => true, 'cashbackPercent' => 5, 'cashbackWheel' => [1, 2, 3, 4, 5]]);
    }

    public function test_nothing_is_created_when_any_part_is_wrong(): void
    {
        $before = [Menu::count(), SuperAdmin::count(), Admin::count(), Timeslot::count()];
        $this->create($this->form(['staff' => ['email' => 'staff@qride.net']]), $this->logo())->assertStatus(422); // staff email taken
        $this->create($this->form(), null)->assertStatus(422)->assertJson(['message' => 'logo_required']);
        $this->create($this->form(['wallet' => ['odds' => ['4' => 0, '5' => 0]]]), $this->logo())->assertStatus(422)->assertJson(['message' => 'wallet_needs_odds']);
        $this->create($this->form(), UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;'))->assertStatus(422)->assertJson(['message' => 'logo_invalid']);
        $this->assertSame($before, [Menu::count(), SuperAdmin::count(), Admin::count(), Timeslot::count()]);
    }

    public function test_a_failed_logo_save_undoes_everything(): void
    {
        $before = [Menu::count(), SuperAdmin::count(), Admin::count(), Timeslot::count()];
        touch($this->icons . '-file'); // a file where the folder should be: the logo can't be saved
        config(['services.qride.icons_path' => $this->icons . '-file']);
        try {
            $this->create($this->form(), $this->logo())->assertStatus(500);
        } finally {
            unlink($this->icons . '-file');
        }
        $this->assertSame($before, [Menu::count(), SuperAdmin::count(), Admin::count(), Timeslot::count()]);
    }

    public function test_a_test_shop_without_wallet_odds_or_vat(): void
    {
        $id = $this->create($this->form(['wallet' => ['enabled' => false, 'odds' => ['4' => 0, '5' => 0], 'vatNumber' => null, 'isTest' => true]]), $this->logo())->assertStatus(201)->json('id');
        $menu = Menu::find($id);
        $this->assertFalse($menu->wallet_enabled);
        $this->assertTrue($menu->is_test);
        $this->assertNull($menu->cashback_odds);
    }

    public function test_edit_keeps_the_accounts_and_replaces_the_hours(): void
    {
        $id = $this->create($this->form(), $this->logo())->json('id');
        $form = $this->form(['name' => 'Renamed', 'wallet' => ['enabled' => false]]);
        unset($form['owner'], $form['staff']);
        $form['hours'] = ['Friday' => [['from' => '13:00', 'to' => '23:00']]];

        $this->app['auth']->forgetGuards();
        $this->call('POST', "/api/v1/qride/shops/$id", ['data' => json_encode($form)], [], [], ['HTTP_AUTHORIZATION' => "Bearer {$this->qrideToken}", 'HTTP_ACCEPT' => 'application/json'])
            ->assertOk()->assertJson(['name' => 'Renamed', 'wallet' => ['enabled' => false], 'ownerEmail' => 'new.owner@cafe.sa']);
        $this->assertSame(['Friday'], Timeslot::where('menu_id', $id)->pluck('day')->all());
        $this->postJson('/api/login-admin', ['email' => 'new.staff@cafe.sa', 'password' => 'staff-pass-1'])->assertOk();
    }

    public function test_reset_password_gives_a_new_one_and_signs_out_the_old_tokens(): void
    {
        $id = $this->create($this->form(), $this->logo())->json('id');
        $old = $this->postJson('/api/login-admin', ['email' => 'new.staff@cafe.sa', 'password' => 'staff-pass-1'])->json('token');

        $new = $this->postJson("/api/v1/qride/shops/$id/reset-password", ['who' => 'staff'], ['Authorization' => "Bearer {$this->qrideToken}"])->assertOk()->json('password');
        $this->assertMatchesRegularExpression('/^[A-Za-z2-9]{4}-[A-Za-z2-9]{4}-[A-Za-z2-9]{4}$/', $new);
        $this->postJson('/api/login-admin', ['email' => 'new.staff@cafe.sa', 'password' => 'staff-pass-1'])->assertStatus(422);
        $this->postJson('/api/login-admin', ['email' => 'new.staff@cafe.sa', 'password' => $new])->assertOk();
        $this->getJson('/api/v1/counter/scan?code=x', ['Authorization' => "Bearer $old"])->assertStatus(401);
        // the owner's password didn't change
        $this->postJson('/api/login-super-admin', ['email' => 'new.owner@cafe.sa', 'password' => 'owner-pass-1'])->assertOk();
    }
}
