<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\StoreAdminRequest;
use App\Http\Requests\UpdateAdminRequest;
use App\Models\Admin;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request; 

class AdminController extends Controller
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


        return Admin::all();
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
    public function store(StoreAdminRequest $request)
    {
        //

        $admin = Admin::create($request->validated());
        return response()->json($admin, 201);  
    }

    /**
     * Display the specified resource.
     */
    public function show(Admin $admin , Request $request)
    {
        //

        $user = $request->user();
        if (!$user || !$user->tokenCan('qrideAdmin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $admin;
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Admin $admin)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAdminRequest $request, Admin $admin)
    {
        //
        $admin->update($request->all());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Admin $admin)
    {
        //
    }
}
