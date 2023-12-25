<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\StoresuggestionRequest;
use App\Http\Requests\UpdatesuggestionRequest;
use App\Models\suggestion;
use App\Http\Controllers\Controller;

class SuggestionController extends Controller
{
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
    public function store(StoresuggestionRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(suggestion $suggestion)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(suggestion $suggestion)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatesuggestionRequest $request, suggestion $suggestion)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(suggestion $suggestion)
    {
        //
    }
}
