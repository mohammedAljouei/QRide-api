<?php

namespace App\Http\Controllers\Api\v1;

// TBD for order
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Menu;
use App\Models\Section;
use App\Models\Meal;
use App\Models\AddOnInfo;
use App\Models\AddOnTitle;

class StoreMenuController extends Controller
{
    /**
     * Handle the incoming request to save multiple models.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        // Start a transaction
        DB::beginTransaction();

        try {
            // Assuming the JSON structure is provided in the request as shown previously
            $menuData = $request->input('menu');
            $menu = Menu::create($menuData);

            $sections = $request->input('sections', []);
            foreach ($sections as $sectionData) {
                $section = new Section($sectionData);
                $menu->sections()->save($section);
            }

            $meals = $request->input('meals', []);
            foreach ($meals as $mealData) {
                $meal = new Meal($mealData);
                // If meals are related to a section, you'll need to adjust this
                $meal->save();
            }

            $addOnInfos = $request->input('addOnInfos', []);
            foreach ($addOnInfos as $addOnInfoData) {
                $addOnInfo = new AddOnInfo($addOnInfoData);
                $addOnInfo->save();
            }

            $addOnTitles = $request->input('addOnTitles', []);
            foreach ($addOnTitles as $addOnTitleData) {
                $addOnTitle = new AddOnTitle($addOnTitleData);
                $addOnTitle->save();
            }

            // Commit the transaction
            DB::commit();

            // Return a successful response
            return response()->json(['success' => true, 'message' => 'All data saved successfully', 'data' => $menu]);
        } catch (\Throwable $e) {
            // An error occurred; rollback the transaction
            DB::rollback();

            // Return an error response
            return response()->json(['success' => false, 'message' => 'Failed to save data', 'error' => $e->getMessage()], 500);
        }
    }
}