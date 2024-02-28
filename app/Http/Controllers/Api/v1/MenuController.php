<?php

// it is a shop
namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\StoreMenuRequest;
use App\Http\Requests\UpdateMenuRequest;
use App\Models\Menu;
use App\Models\Section;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request; 

class MenuController extends Controller
{


    // newly added by mohammed 
    
    
    
  public function getPaymentMenu($menuId)
    {
        $menu = Menu::find($menuId);
        $paymentMethods = $menu->payment_methods;

    
        return response()->json(['paymentMethods' => $paymentMethods]);
    }
    

    public function getMenu($menuId)
    {
        $menu = Menu::with(['sections.meals.addOnTitles.addOnInfos'])
                    ->where('id', $menuId)
                    ->first();
    
        if (!$menu) {
            return response()->json(['error' => 'Menu not found'], 404);
        }
    
        $transformedMenu = [
            'menuId' => $menu->id,
            'sections' => $menu->sections ? $menu->sections->map(function ($section) {
                return [
                    'sectionId' => $section->id,
                    'sectionName' => $section->name, // Replace with actual field name in Section model
                    'meals' => $section->meals ? $section->meals->map(function ($meal) {
                        return [
                            'mealId' => $meal->id,
                            'isAvailable' => $meal->status == 1 ? true : false,
                            'imagePath' => $meal->image_path,
                            'mealName' => $meal->name,
                            'description' => $meal->description,
                            'price' => $meal->price,
                            // Include other meal fields here...
                            'addOns' => $meal->addOnTitles ? $meal->addOnTitles->map(function ($addOnTitle) {
                                return [
                                    'title' => $addOnTitle->title, 
                                    'min' => $addOnTitle->min,
                                    'max' => $addOnTitle->max, 
                                    'addOnsItems' => $addOnTitle->addOnInfos ? $addOnTitle->addOnInfos->map(function ($addOnInfo) {
                                        return [
                                            'addOnName' => $addOnInfo->name, // Replace with actual field name in AddOnInfo
                                            'addOnPrice' => $addOnInfo->price // Replace with actual field name in AddOnInfo
                                        ];
                                    }) : []
                                ];
                            }) : []
                        ];
                    }) : []
                ];
            }) : []
        ];
    
        return response()->json(['menu' => $transformedMenu]);
    }
    
// end func added by mohammed
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        //

        $user = $request->user();
        if (!$user || !$user->tokenCan('qrideAdmin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return Menu::all();
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMenuRequest $request)
    {
        //

        $menu = Menu::create($request->validated());
        return response()->json($menu, 201);    
    }

    /**
     * Display the specified resource.
     */
    public function show(Menu $shop ,  Request $request)
    {
        //

        $user = $request->user();
        if (!$user || !$user->tokenCan('superAdmin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $shop;
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Menu $menu)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMenuRequest $request, Menu $shop)
    {

        $shop->update($request->all());
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Menu $menu)
    {
        //
    }
}
