<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\StoreAddOnTitleRequest;
use App\Http\Requests\UpdateAddOnTitleRequest;
use App\Models\AddOnTitle;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request; 


class AddOnTitleController extends Controller
{

    public function byMenu(Request $request, $meal_id)
    {

        $user = $request->user();
        if (!$user || !$user->tokenCan('superAdmin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $addOnTitles = AddOnTitle::where('meal_id', $meal_id)->get();
        return response()->json($addOnTitles);
    }

    public function byMenuId(Request $request, $menuId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
    
        // Query AddOnTitles based on meal's section's menu_id
        $addOnTitles = AddOnTitle::whereHas('meal.section', function ($query) use ($menuId) {
            $query->where('menu_id', $menuId);
        })->get();
    
        return response()->json($addOnTitles);
    }
    
    

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
    public function store(StoreAddOnTitleRequest $request)
    {
        //
        $addOnTitles = AddOnTitle::create($request->validated());
        return response()->json($addOnTitles, 201);   
    }

    /**
     * Display the specified resource.
     */
    public function show(AddOnTitle $addOnTitle)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(AddOnTitle $addOnTitle)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAddOnTitleRequest $request, AddOnTitle $addOnTitle)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AddOnTitle $addOnTitle)
    {
        //

        $addOnTitle->delete();

        // Return a HTTP 200 response with a success message
        return response()->json(['message' => 'addOnTitle deleted successfully']);
    }
}
