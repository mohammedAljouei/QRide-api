<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Menu;
use App\Models\SuperAdmin;
use App\Models\Timeslot;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// The QRide admin panel (/qride): onboarding and editing shops. Only QRide's own accounts (users table).
class QrideAdminController extends Controller
{
    public const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    private const PLATFORMS = ['Instagram', 'Snapchat', 'TikTok', 'X', 'WhatsApp', 'YouTube'];
    private const LOGO_SIZE = 512; // the home-screen icon (InstallAppPrompt uses icons/{id}.png)

    public function shops(Request $request)
    {
        $this->authorizeQride($request);
        $menus = Menu::orderByDesc('id')->get();
        $owners = SuperAdmin::whereIn('id', $menus->pluck('super_admin_id'))->get()->keyBy('id');
        $staff = Admin::whereIn('id', $menus->pluck('admin_id'))->get()->keyBy('id');

        return response()->json($menus->map(fn (Menu $m) => [
            'id' => $m->id,
            'name' => $m->name,
            'slogan' => $m->slogan,
            'color' => $m->color,
            'walletEnabled' => (bool) $m->wallet_enabled,
            'isTest' => (bool) $m->is_test,
            'maxPercent' => $m->wallet_enabled ? max(WalletService::wheel($m)) : 0,
            'logo' => self::logoUrl($m->id),
            'ownerEmail' => $owners[$m->super_admin_id]->email ?? null,
            'ownerPassword' => self::readPassword($owners[$m->super_admin_id] ?? null),
            'staffEmail' => $staff[$m->admin_id]->email ?? null,
            'staffPassword' => self::readPassword($staff[$m->admin_id] ?? null),
        ]));
    }

    public function show(Request $request, $id)
    {
        $this->authorizeQride($request);
        $menu = Menu::findOrFail($id);
        return response()->json($this->shopJson($menu));
    }

    // One request creates the owner, the staff account, the shop, its hours and its logo: all of it or nothing
    public function store(Request $request)
    {
        $this->authorizeQride($request);
        $data = $this->validated($request, null);
        $logo = $request->file('logo');
        if (!$logo instanceof UploadedFile) {
            return response()->json(['message' => 'logo_required'], 422);
        }
        $png = $this->logoPng($logo);

        $menu = DB::transaction(function () use ($data, $png) {
            $owner = SuperAdmin::create(['name' => $data['owner']['name'], 'email' => $data['owner']['email'], 'password' => $data['owner']['password']]);
            $staff = Admin::create(['name' => $data['staff']['name'], 'email' => $data['staff']['email'], 'password' => $data['staff']['password']]);
            self::keepPassword($owner, $data['owner']['password']);
            self::keepPassword($staff, $data['staff']['password']);
            $menu = new Menu();
            $menu->forceFill(['admin_id' => $staff->id, 'super_admin_id' => $owner->id, 'version' => 1, 'status' => 'open']);
            $this->fillShop($menu, $data);
            $menu->save();
            $this->saveHours($menu, $data['hours']);
            $this->saveLogo($menu, $png); // last: a failure here undoes everything above
            return $menu;
        });

        return response()->json($this->shopJson($menu->fresh()), 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeQride($request);
        $menu = Menu::findOrFail($id);
        $data = $this->validated($request, $menu);
        $logo = $request->file('logo');
        $png = $logo instanceof UploadedFile ? $this->logoPng($logo) : null;

        DB::transaction(function () use ($menu, $data, $png) {
            $this->fillShop($menu, $data);
            $menu->save();
            if (array_key_exists('hours', $data)) {
                Timeslot::where('menu_id', $menu->id)->delete();
                $this->saveHours($menu, $data['hours']);
            }
            if ($png !== null) {
                $this->saveLogo($menu, $png);
            }
        });

        return response()->json($this->shopJson($menu->fresh()));
    }

    // A new password for the shop's owner or staff account, shown once in the panel
    public function resetPassword(Request $request, $id)
    {
        $this->authorizeQride($request);
        $request->validate(['who' => ['required', Rule::in(['owner', 'staff'])]]);
        $menu = Menu::findOrFail($id);
        $account = $request->who === 'owner' ? SuperAdmin::findOrFail($menu->super_admin_id) : Admin::findOrFail($menu->admin_id);
        $password = self::newPassword();
        $account->password = $password; // hashed by the model
        $account->save();
        self::keepPassword($account, $password);
        $account->tokens()->delete(); // signs the old password out everywhere
        return response()->json(['password' => $password]);
    }

    // Opens the shop's staff dashboard or branch manager's menu editor as that shop, without its password:
    // a normal token of that account
    public function portal(Request $request, $id)
    {
        $this->authorizeQride($request);
        $who = $request->validate(['who' => ['required', Rule::in(['staff', 'owner'])]])['who'];
        $menu = Menu::findOrFail($id);
        $token = $who === 'staff'
            ? Admin::findOrFail($menu->admin_id)->createToken('qride-portal', ['admin'])
            : SuperAdmin::findOrFail($menu->super_admin_id)->createToken('qride-portal', ['superAdmin']);
        return response()->json(['token' => $token->plainTextToken, 'menuId' => $menu->id]);
    }

    // The user asked to see the passwords in the panel: a copy encrypted with the app key (login uses the hash)
    private static function keepPassword($account, string $password): void
    {
        $account->forceFill(['panel_password' => Crypt::encryptString($password)])->save();
    }

    private static function readPassword($account): ?string
    {
        if (!$account?->panel_password) {
            return null; // made before the panel: unknown until a new one is set
        }
        try {
            return Crypt::decryptString($account->panel_password);
        } catch (\Throwable $e) {
            return null; // app key changed
        }
    }

    // The logo URL with its file time, so a new logo shows straight away (the CDN caches the old one)
    private static function logoUrl(int $id): ?string
    {
        $file = config('services.qride.icons_path') . "/$id.png";
        return is_file($file) ? "https://qride.net/icons/$id.png?v=" . filemtime($file) : null;
    }

    public static function newPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no look-alikes (0/O, 1/l/I)
        $groups = [];
        for ($g = 0; $g < 3; $g++) {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $s;
        }
        return implode('-', $groups);
    }

    private function authorizeQride(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->tokenCan('qrideAdmin'), 403, 'Unauthorized');
    }

    // The panel sends the form as JSON in "data" (multipart, so the logo can come along)
    private function validated(Request $request, ?Menu $menu): array
    {
        $input = json_decode((string) $request->input('data'), true);
        $creating = $menu === null;
        $rules = [
            'name' => 'required|string|max:80',
            'slogan' => 'nullable|string|max:120',
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'payment' => 'required|array',
            'payment.cash' => 'boolean', 'payment.Machine' => 'boolean', 'payment.applePay' => 'boolean',
            'platforms' => 'nullable|array',
            'platforms.*' => 'nullable|string|max:300',
            'hours' => ($creating ? 'required' : 'sometimes') . '|array',
            'hours.*' => 'array',
            'hours.*.*.from' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'hours.*.*.to' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'wallet' => 'required|array',
            'wallet.enabled' => 'required|boolean',
            'wallet.odds' => 'nullable|array',
            'wallet.odds.*' => 'integer|min:0|max:100',
            'wallet.minBalance' => 'required|numeric|min:0|max:10000',
            'wallet.vatNumber' => ['nullable', 'regex:/^\d{15}$/'],
            'wallet.isTest' => 'required|boolean',
        ];
        if ($creating) {
            $rules += [
                'owner.name' => 'required|string|max:80',
                'owner.email' => 'required|email|max:120|unique:super_admins,email',
                'owner.password' => 'required|string|min:8|max:100',
                'staff.name' => 'required|string|max:80',
                'staff.email' => 'required|email|max:120|unique:admins,email',
                'staff.password' => 'required|string|min:8|max:100',
            ];
        }
        $data = Validator::make(is_array($input) ? $input : [], $rules)->validate();

        foreach (array_keys($data['hours'] ?? []) as $day) {
            abort_unless(in_array($day, self::DAYS, true), 422, 'invalid_day');
        }
        $odds = $this->odds($data['wallet']['odds'] ?? []);
        if ($data['wallet']['enabled'] && !$odds) {
            abort(422, 'wallet_needs_odds');
        }
        $data['wallet']['odds'] = $odds;
        return $data;
    }

    // Weights for 1-5%, kept as entered (the wheel shows every percent, including the 0-chance ones)
    private function odds(array $raw): array
    {
        $odds = [];
        foreach ([1, 2, 3, 4, 5] as $p) {
            $odds[(string) $p] = (int) ($raw[(string) $p] ?? $raw[$p] ?? 0);
        }
        return array_sum($odds) > 0 ? $odds : [];
    }

    private function fillShop(Menu $menu, array $data): void
    {
        $platforms = [];
        foreach (self::PLATFORMS as $p) {
            $link = trim((string) ($data['platforms'][$p] ?? ''));
            $platforms[$p] = ['link' => $link, 'status' => $link !== ''];
        }
        $odds = $data['wallet']['odds'];
        $menu->forceFill([
            'name' => $data['name'],
            'slogan' => (string) ($data['slogan'] ?? ''),
            'color' => strtoupper($data['color']),
            'payment_methods' => [
                'cash' => (bool) ($data['payment']['cash'] ?? false),
                'Machine' => (bool) ($data['payment']['Machine'] ?? false),
                'applePay' => (bool) ($data['payment']['applePay'] ?? false),
            ],
            'platforms' => $platforms,
            'wallet_enabled' => (bool) $data['wallet']['enabled'],
            'cashback_odds' => $odds ?: null,
            // the highest percent with a chance: used when there is no wheel
            'cashback_percent' => $odds ? max(array_keys(array_filter($odds))) : 0,
            'wallet_min_balance' => $data['wallet']['minBalance'],
            'vat_number' => $data['wallet']['vatNumber'] ?? null,
            'is_test' => (bool) $data['wallet']['isTest'],
        ]);
    }

    // A period that ends after midnight (06:00 to 02:00) is stored as two: until 23:59, then the next day from 00:00
    private function saveHours(Menu $menu, array $hours): void
    {
        foreach ($hours as $day => $periods) {
            $next = self::DAYS[(array_search($day, self::DAYS, true) + 1) % 7];
            foreach ($periods as $p) {
                if ($p['to'] > $p['from']) {
                    $this->slot($menu, $day, $p['from'], $p['to']);
                } else {
                    $this->slot($menu, $day, $p['from'], '23:59');
                    if ($p['to'] !== '00:00') {
                        $this->slot($menu, $next, '00:00', $p['to']);
                    }
                }
            }
        }
    }

    private function slot(Menu $menu, string $day, string $from, string $to): void
    {
        Timeslot::create(['menu_id' => $menu->id, 'day' => $day, 'start_time' => "$from:00", 'end_time' => "$to:00"]);
    }

    // Any common image becomes a square PNG (transparent padding), so icons/{id}.png is always a real PNG
    private function logoPng(UploadedFile $file): string
    {
        abort_unless($file->isValid() && $file->getSize() <= 5 * 1024 * 1024, 422, 'logo_invalid');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true), 422, 'logo_invalid');
        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        abort_unless($src !== false, 422, 'logo_invalid');

        $w = imagesx($src);
        $h = imagesy($src);
        $size = self::LOGO_SIZE;
        $scale = min($size / $w, $size / $h);
        $dw = max(1, (int) round($w * $scale));
        $dh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($size, $size);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $w, $h);

        ob_start();
        imagepng($dst, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return $png;
    }

    private function saveLogo(Menu $menu, string $png): void
    {
        $dir = (string) config('services.qride.icons_path');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tmp = $dir . '/.' . $menu->id . '-' . Str::random(8) . '.png';
        if (file_put_contents($tmp, $png) === false || !rename($tmp, "$dir/{$menu->id}.png")) {
            @unlink($tmp);
            throw new \RuntimeException('Could not save the logo');
        }
        @chmod("$dir/{$menu->id}.png", 0644);
    }

    private function shopJson(Menu $menu): array
    {
        $hours = array_fill_keys(self::DAYS, []);
        foreach (Timeslot::where('menu_id', $menu->id)->orderBy('start_time')->get() as $t) {
            $hours[$t->day][] = ['from' => substr($t->start_time, 0, 5), 'to' => substr($t->end_time, 0, 5)];
        }
        $platforms = [];
        foreach (self::PLATFORMS as $p) {
            $platforms[$p] = (string) (((array) $menu->platforms)[$p]['link'] ?? '');
        }
        $odds = (array) $menu->cashback_odds;

        return [
            'id' => $menu->id,
            'name' => $menu->name,
            'slogan' => $menu->slogan,
            'color' => $menu->color,
            'payment' => [
                'cash' => (bool) (((array) $menu->payment_methods)['cash'] ?? false),
                'Machine' => (bool) (((array) $menu->payment_methods)['Machine'] ?? false),
                'applePay' => (bool) (((array) $menu->payment_methods)['applePay'] ?? false),
            ],
            'platforms' => $platforms,
            'hours' => $hours,
            'wallet' => [
                'enabled' => (bool) $menu->wallet_enabled,
                'odds' => array_map('intval', array_replace(['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0], $odds)),
                'minBalance' => (float) $menu->wallet_min_balance,
                'vatNumber' => $menu->vat_number,
                'isTest' => (bool) $menu->is_test,
            ],
            'logo' => self::logoUrl($menu->id),
            'ownerEmail' => ($owner = SuperAdmin::find($menu->super_admin_id))?->email,
            'ownerPassword' => self::readPassword($owner),
            'staffEmail' => ($staff = Admin::find($menu->admin_id))?->email,
            'staffPassword' => self::readPassword($staff),
        ];
    }
}
