<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\StoreAddOnInfoRequest;
use App\Http\Requests\UpdateAddOnInfoRequest;
use App\Models\AddOnInfo;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request; 

class AddOnInfoController extends Controller
{

    public function byMenu(Request $request, $add_on_title_id)
    {

        $user = $request->user();
        if (!$user || !$user->tokenCan('superAdmin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $addOnTitles = AddOnInfo::where('add_on_title_id', $add_on_title_id)->get();
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
    public function store(StoreAddOnInfoRequest $request)
    {
        //
        $addOnInfos = AddOnInfo::create($request->validated());
        return response()->json($addOnInfos, 201);   
    }

    /**
     * Display the specified resource.
     */
    public function show(AddOnInfo $addOnInfo)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(AddOnInfo $addOnInfo)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAddOnInfoRequest $request, AddOnInfo $addOnInfo)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AddOnInfo $addOnInfo)
    {
        //

        $addOnInfo->delete();

        // Return a HTTP 200 response with a success message
        return response()->json(['message' => 'addOnInfo deleted successfully']);
    }
}
