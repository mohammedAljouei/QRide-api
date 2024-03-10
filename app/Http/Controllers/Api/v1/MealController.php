<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\StoreMealRequest;
use App\Http\Requests\UpdateMealRequest;
use App\Models\Meal;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;


class MealController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }


    public function updateStatus(Request $request, $mealId)
    {
        // Define the allowed statuses in uppercase
        $allowedStatuses = [1,0];

        // Validate the request
        $request->validate([
            'status' => ['required', 'string', Rule::in($allowedStatuses)],
        ]);

        // Check if the status is in uppercase
        if ($request->status !== $request->status) {
            return response()->json(['error' => 'Status must 1 or 0.'], 422);
        }

        // Find the order by ID
        $meal = Meal::findOrFail($mealId);

        $meal->status = $request->status;
        $meal->save();

        return response()->json(['message' => 'Meal status updated successfully']);
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
        if (!$user) {
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
