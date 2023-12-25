<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\StoreSuperAdminRequest;
use App\Http\Requests\UpdateSuperAdminRequest;
use App\Models\SuperAdmin;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request; 

class SuperAdminController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {

        $user = $request->user();
        if (!$user || !$user->tokenCan('qrideAdmin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return SuperAdmin::all();
        //
    }

    // /**
    //  * Show the form for creating a new resource.
    //  */
    // public function create()
    // {
    //     //
    // }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSuperAdminRequest $request)
    {
        //
        $superAdmin = SuperAdmin::create($request->validated());
        return response()->json($superAdmin, 201);    }

    /**
     * Display the specified resource.
     */
    public function show(SuperAdmin $superAdmin, Request $request)
    {
        //

        // dd($superAdmin);

        $user = $request->user();
        if (!$user || !$user->tokenCan('qrideAdmin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $superAdmin;
    }

    // /**
    //  * Show the form for editing the specified resource.
    //  */
    // public function edit(SuperAdmin $superAdmin)
    // {
    //     //
    // }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSuperAdminRequest $request, SuperAdmin $superAdmin)
    {
        //
        $superAdmin->update($request->all());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SuperAdmin $superAdmin)
    {
        //
    }
}
