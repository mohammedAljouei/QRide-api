<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\StoreMealRequest;
use App\Http\Requests\UpdateMealRequest;
use App\Models\Meal;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request; 

class MealController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }



    public function byMenu(Request $request, $secationId)
    {

        $user = $request->user();
        if (!$user || !$user->tokenCan('superAdmin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $meals = Meal::where('section_id', $secationId)->get();
        return response()->json($meals);
    }


    public function byMenuId(Request $request, $menuId)
    {
        $user = $request->user();
        if (!$user || !$user->tokenCan('admin') || !$user->tokenCan('superAdmin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
    
        // Query meals based on section's menu_id
        $meals = Meal::whereHas('section', function ($query) use ($menuId) {
            $query->where('menu_id', $menuId);
        })->get();
    
        return response()->json($meals);
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
    public function store(StoreMealRequest $request)
    {
        //
        $meal = Meal::create($request->validated());
        return response()->json($meal, 201);   
    }

    /**
     * Display the specified resource.
     */
    public function show(Meal $meal)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Meal $meal)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMealRequest $request, Meal $meal)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Meal $meal)
    {
        //
        $meal->delete();

        // Return a HTTP 200 response with a success message
        return response()->json(['message' => 'meal deleted successfully']);
    }
}
