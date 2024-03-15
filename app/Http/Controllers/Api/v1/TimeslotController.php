<?php

namespace App\Http\Controllers\Api\v1;

use App\Models\Timeslot; // Assuming your model is named Timeslot
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

use Carbon\Carbon;

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



    public function show($menuId)
    {
        // Retrieve all timeslots for the menu_id
        $timeslots = Timeslot::where('menu_id', $menuId)->get();

        if ($timeslots->isEmpty()) {
            return response()->json(['message' => 'No timeslots found for this shop'], 404);
        }

        // Group timeslots by day
        $groupedByDay = $timeslots->groupBy('day');

        // Initialize the structure for all days
        $weekly_availability = [
            'Monday'    => [],
            'Tuesday'   => [],
            'Wednesday' => [],
            'Thursday'  => [],
            'Friday'    => [],
            'Saturday'  => [],
            'Sunday'    => [],
        ];

        // Populate the structure with timeslot data
        foreach ($groupedByDay as $day => $slots) {
            foreach ($slots as $slot) {
                $weekly_availability[$day][] = [
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                ];
            }
        }

        // Prepare the response data
        $response = [
            'menu_id' => $menuId,
            'weekly_availability' => $weekly_availability
        ];

        return response()->json($response);
    }


    public function checkAvailability(Request $request, $menuId)
    {
        // Validate the request
        $request->validate([
            'timestamp' => 'required|date',
        ]);

        // Use Carbon to parse the timestamp and get day of week and time
        $timestamp = Carbon::parse($request->input('timestamp'));
        $dayOfWeek = $timestamp->format('l');
        $time = $timestamp->format('H:i:s');

        // Find timeslots for the given menu_id and day of the week
        $timeslots = Timeslot::where('menu_id', $menuId)
                     ->where('day', $dayOfWeek)
                     ->get();

        // Check if the given time is within any of the timeslots
        $isOpen = $timeslots->contains(function ($timeslot) use ($time) {
            return $time >= $timeslot->start_time && $time <= $timeslot->end_time;
        });

        // Return the appropriate response
        return response()->json([
            'open' => $isOpen
        ]);
    }
}
