<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\StoresuggestionRequest;
use App\Http\Requests\UpdatesuggestionRequest;
use App\Models\suggestion;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class SuggestionController extends Controller
{







    public function postFeedBack(Request $request)
    {
        // Validate the incoming request data
        $validator = Validator::make($request->all(), [
            'orderId' => 'required|integer',
            'message' => 'string',
            'star' => 'required|integer',
    
        ]);

        // If validation fails, return a JSON response with errors
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Extract validated data
        $validatedData = $validator->validated();

        // Prepare the data for insertion
        $feedBackData = [
            'order_id' => $validatedData['orderId'],
            'message' => $validatedData['message'] ?? null, // Set the message field, default to null if not provided
            'star' => $validatedData['star'],
           
        ];

        // Create the order in the database
        $feedBack = Suggestion::create($feedBackData);

        // Return a JSON response with the generated order ID
        return response()->json(['feedBackId' => $feedBack->id]);
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
