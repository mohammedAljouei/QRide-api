<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\AddOnInfo;
use App\Models\AddOnTitle;
use App\Models\Meal;
use App\Models\Menu;
use App\Models\Section;
use App\Models\SuperAdmin;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The branch manager's menu editor (/manager): sections, meals and their add-ons for their own shop only.
// A meal's add-ons are saved together with it (orders find add-ons by name, so their ids may change).
class ManagerMenuController extends Controller
{
    private const IMAGE_MAX = 1200; // px, longest side

    public function show(Request $request, $menuId)
    {
        $menu = $this->menu($request, $menuId);
        return response()->json($this->menuJson($menu));
    }

    public function createSection(Request $request, $menuId)
    {
        $menu = $this->menu($request, $menuId);
        $data = $request->validate(['name' => 'required|string|max:80']);
        Section::create(['menu_id' => $menu->id, 'name' => trim($data['name']), 'sort' => (int) Section::where('menu_id', $menu->id)->max('sort') + 1]);
        return response()->json($this->menuJson($menu), 201);
    }

    public function updateSection(Request $request, $menuId, $sectionId)
    {
        $menu = $this->menu($request, $menuId);
        $section = Section::where('menu_id', $menu->id)->findOrFail($sectionId);
        $data = $request->validate(['name' => 'required|string|max:80']);
        $section->update(['name' => trim($data['name'])]);
        return response()->json($this->menuJson($menu));
    }

    // Deletes the section with its meals and their add-ons
    public function deleteSection(Request $request, $menuId, $sectionId)
    {
        $menu = $this->menu($request, $menuId);
        $section = Section::where('menu_id', $menu->id)->findOrFail($sectionId);
        DB::transaction(function () use ($section) {
            $mealIds = Meal::where('section_id', $section->id)->pluck('id')->all();
            self::deleteAddOns($mealIds);
            Meal::whereIn('id', $mealIds)->delete();
            $section->delete();
        });
        return response()->json($this->menuJson($menu));
    }

    // Done by hand too (the live database also cascades), so it is the same everywhere
    private static function deleteAddOns(array $mealIds): void
    {
        $titleIds = AddOnTitle::whereIn('meal_id', $mealIds)->pluck('id')->all();
        AddOnInfo::whereIn('add_on_title_id', $titleIds)->delete();
        AddOnTitle::whereIn('id', $titleIds)->delete();
    }

    public function orderSections(Request $request, $menuId)
    {
        $menu = $this->menu($request, $menuId);
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];
        $this->saveOrder(Section::where('menu_id', $menu->id)->pluck('id')->all(), $ids, fn ($id, $i) => Section::whereKey($id)->update(['sort' => $i]));
        return response()->json($this->menuJson($menu));
    }

    public function orderMeals(Request $request, $menuId, $sectionId)
    {
        $menu = $this->menu($request, $menuId);
        $section = Section::where('menu_id', $menu->id)->findOrFail($sectionId);
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];
        $this->saveOrder(Meal::where('section_id', $section->id)->pluck('id')->all(), $ids, fn ($id, $i) => Meal::whereKey($id)->update(['sort' => $i]));
        return response()->json($this->menuJson($menu));
    }

    public function createMeal(Request $request, $menuId)
    {
        $menu = $this->menu($request, $menuId);
        $data = $this->mealData($request, $menu);
        DB::transaction(function () use ($data) {
            $meal = Meal::create([
                'section_id' => $data['sectionId'], 'name' => $data['name'], 'description' => $data['description'],
                'price' => $data['price'], 'image_path' => $data['image'], 'status' => $data['available'] ? 1 : 0,
                'sort' => (int) Meal::where('section_id', $data['sectionId'])->max('sort') + 1,
            ]);
            $this->saveAddOns($meal, $data['addOns']);
        });
        return response()->json($this->menuJson($menu), 201);
    }

    public function updateMeal(Request $request, $menuId, $mealId)
    {
        $menu = $this->menu($request, $menuId);
        $meal = $this->meal($menu, $mealId);
        $data = $this->mealData($request, $menu);
        DB::transaction(function () use ($meal, $data) {
            $moved = (int) $meal->section_id !== (int) $data['sectionId'];
            $meal->update([
                'section_id' => $data['sectionId'], 'name' => $data['name'], 'description' => $data['description'],
                'price' => $data['price'], 'image_path' => $data['image'], 'status' => $data['available'] ? 1 : 0,
                'sort' => $moved ? (int) Meal::where('section_id', $data['sectionId'])->max('sort') + 1 : $meal->sort,
            ]);
            self::deleteAddOns([$meal->id]);
            $this->saveAddOns($meal, $data['addOns']);
        });
        return response()->json($this->menuJson($menu));
    }

    public function setAvailable(Request $request, $menuId, $mealId)
    {
        $menu = $this->menu($request, $menuId);
        $meal = $this->meal($menu, $mealId);
        $meal->update(['status' => $request->validate(['available' => 'required|boolean'])['available'] ? 1 : 0]);
        return response()->json($this->menuJson($menu));
    }

    public function deleteMeal(Request $request, $menuId, $mealId)
    {
        $menu = $this->menu($request, $menuId);
        $meal = $this->meal($menu, $mealId);
        DB::transaction(function () use ($meal) {
            self::deleteAddOns([$meal->id]);
            $meal->delete();
        });
        return response()->json($this->menuJson($menu));
    }

    // A meal photo: resized to 1200px at most and saved as a JPEG under a random name in img/uploads
    public function uploadImage(Request $request, $menuId)
    {
        $menu = $this->menu($request, $menuId);
        $file = $request->file('image');
        abort_unless($file instanceof UploadedFile && $file->isValid() && $file->getSize() <= 10 * 1024 * 1024, 422, 'image_invalid');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true), 422, 'image_invalid');
        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        abort_unless($src !== false, 422, 'image_invalid');

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, self::IMAGE_MAX / max($w, $h));
        $dw = max(1, (int) round($w * $scale));
        $dh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($dw, $dh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // transparent PNGs on white
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $w, $h);

        $dir = (string) config('services.qride.uploads_path');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $name = "m{$menu->id}-" . Str::lower(Str::random(16)) . '.jpg';
        $ok = imagejpeg($dst, "$dir/$name", 82);
        imagedestroy($src);
        imagedestroy($dst);
        abort_unless($ok, 500, 'image_not_saved');
        @chmod("$dir/$name", 0644);

        return response()->json(['url' => rtrim((string) config('services.qride.uploads_url'), '/') . "/$name"], 201);
    }

    // The branch manager of this shop (their token), or nobody
    private function menu(Request $request, $menuId): Menu
    {
        $user = $request->user();
        abort_unless($user instanceof SuperAdmin && $user->tokenCan('superAdmin'), 403, 'Unauthorized');
        $menu = Menu::find($menuId);
        abort_unless($menu && (int) $menu->super_admin_id === (int) $user->id, 403, 'Unauthorized');
        return $menu;
    }

    private function meal(Menu $menu, $mealId): Meal
    {
        return Meal::whereKey($mealId)->whereHas('section', fn ($q) => $q->where('menu_id', $menu->id))->firstOrFail();
    }

    private function mealData(Request $request, Menu $menu): array
    {
        $data = $request->validate([
            'sectionId' => 'required|integer',
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0|max:100000',
            'image' => 'nullable|string|max:255',
            'available' => 'required|boolean',
            'addOns' => 'present|array|max:20',
            'addOns.*.title' => 'required|string|max:120',
            'addOns.*.min' => 'required|integer|min:0|max:50',
            'addOns.*.max' => 'required|integer|min:1|max:50',
            'addOns.*.items' => 'required|array|min:1|max:50',
            'addOns.*.items.*.name' => 'required|string|max:120',
            'addOns.*.items.*.price' => 'required|numeric|min:0|max:100000',
        ]);
        abort_unless(Section::where('menu_id', $menu->id)->whereKey($data['sectionId'])->exists(), 422, 'section_invalid');
        // A photo is either empty or one of ours (uploaded through this editor, or an existing menu photo)
        $image = trim((string) ($data['image'] ?? ''));
        abort_unless($image === '' || str_starts_with($image, 'https://'), 422, 'image_invalid');

        $names = [];
        foreach ($data['addOns'] as $i => $group) {
            abort_unless($group['min'] <= $group['max'] && $group['min'] <= count($group['items']), 422, 'addon_limits');
            foreach ($group['items'] as $item) {
                $key = mb_strtolower(trim($item['name']));
                abort_if(isset($names[$key]), 422, 'addon_duplicate'); // orders find an add-on by its name
                $names[$key] = true;
            }
        }
        $data['name'] = trim($data['name']);
        $data['description'] = trim((string) ($data['description'] ?? ''));
        $data['image'] = $image;
        return $data;
    }

    private function saveAddOns(Meal $meal, array $groups): void
    {
        foreach ($groups as $group) {
            $title = AddOnTitle::create(['meal_id' => $meal->id, 'title' => trim($group['title']), 'min' => $group['min'], 'max' => $group['max']]);
            foreach ($group['items'] as $item) {
                AddOnInfo::create(['add_on_title_id' => $title->id, 'name' => trim($item['name']), 'price' => $item['price']]);
            }
        }
    }

    // $ids must be exactly the current ids, in the new order
    private function saveOrder(array $current, array $ids, callable $save): void
    {
        $ids = array_map('intval', $ids);
        abort_unless(count($ids) === count($current) && !array_diff($current, $ids), 422, 'order_invalid');
        DB::transaction(function () use ($ids, $save) {
            foreach ($ids as $i => $id) {
                $save($id, $i + 1);
            }
        });
    }

    private function menuJson(Menu $menu): array
    {
        $sections = Section::where('menu_id', $menu->id)->orderBy('sort')->orderBy('id')
            ->with(['meals' => fn ($q) => $q->orderBy('sort')->orderBy('id'), 'meals.addOnTitles.addOnInfos'])->get();
        $icon = config('services.qride.icons_path') . "/{$menu->id}.png";

        return [
            'shop' => [
                'id' => $menu->id, 'name' => $menu->name, 'color' => $menu->color,
                'logo' => is_file($icon) ? "https://qride.net/icons/{$menu->id}.png?v=" . filemtime($icon) : null,
            ],
            'sections' => $sections->map(fn (Section $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'meals' => $s->meals->map(fn (Meal $m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'description' => $m->description,
                    'price' => (float) $m->price,
                    'image' => $m->image_path,
                    'available' => (int) $m->status === 1,
                    'addOns' => $m->addOnTitles->map(fn (AddOnTitle $t) => [
                        'title' => $t->title, 'min' => (int) $t->min, 'max' => (int) $t->max,
                        'items' => $t->addOnInfos->map(fn (AddOnInfo $i) => ['name' => $i->name, 'price' => (float) $i->price])->values(),
                    ])->values(),
                ])->values(),
            ])->values(),
        ];
    }
}
