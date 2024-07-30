<?php

namespace App\Http\Controllers\Api\v1;
use App\Http\Controllers\Controller; 
use Illuminate\Http\Request;
use App\Models\FormDetails;

class LandingPageController extends Controller
{
    public function storeDetails(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'resName' => 'required|string|max:255',
        ]);

        FormDetails::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'resName' => $request->resName,
        ]);

        return response()->json(['message' => 'Form details saved successfully!'], 200);
    }
}
