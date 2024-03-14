<?php

namespace App\Http\Controllers\Api\v1;

use App\Models\Timeslot; // Assuming your model is named Timeslot
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;


class TimeslotController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'menu_id' => 'required|exists:menus,id',
            'weekly_availability' => 'required|array',
        ]);

        foreach ($data['weekly_availability'] as $day => $slots) {
            foreach ($slots as $slot) {
                Timeslot::create([
                    'menu_id' => $data['menu_id'],
                    'day' => $day,
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time']
                ]);
            }
        }

        return response()->json(['message' => 'Timeslots saved successfully'], 200);
    }
}
