<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\StoreSectionRequest;
use App\Http\Requests\UpdateSectionRequest;
use App\Models\Section;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request; 

class SectionController extends Controller
{
    /**
     * Display a listing of the resource.
     */


    public function byMenu(Request $request, $menuId)
    {

        $user = $request->user();
        if (!$user || !$user->tokenCan('superAdmin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $sections = Section::where('menu_id', $menuId)->get();
        return response()->json($sections);
    }


    // public function index(Request $request)
    // {
    //     $user = $request->user();
    //     if (!$user || !$user->tokenCan('superAdmin')) {
    //         return response()->json(['message' => 'Unauthorized'], 403);
    //     }
    
    //     // Assuming you want to filter by 'menu_id' which is passed as a query parameter.
    //     $menuId = $request->query('menu_id');
    
    //     if ($menuId) {
    //         $sections = Section::where('menu_id', $menuId)->get();
    //     } else {
    //         // If no specific filter is provided, you may want to limit the results or sort them
    //         $sections = Section::all(); // or use ->limit(10)->get() for example
    //     }
    
    //     return response()->json($sections);
    // }
    
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
    public function store(StoreSectionRequest $request)
    {
        $section = Section::create($request->validated());
        return response()->json($section, 201);   
    }

    /**
     * Display the specified resource.
     */
    public function show(Section $section)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Section $section)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSectionRequest $request, Section $section)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Section $section)
    {
        //
    }
}
