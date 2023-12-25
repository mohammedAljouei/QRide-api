<?php

// it is a shop
namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\StoreMenuRequest;
use App\Http\Requests\UpdateMenuRequest;
use App\Models\Menu;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request; 

class MenuController extends Controller
{
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
