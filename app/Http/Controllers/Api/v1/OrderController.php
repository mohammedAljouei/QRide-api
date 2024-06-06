<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Order;
use App\Models\Meal;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Events\MyEvent;
use Illuminate\Validation\Rule;


class OrderController extends Controller
{


    public function setNotiByCustomer($orderId)
    {
       
        // Find the order by ID
        $order = Order::findOrFail($orderId);


        $order->checkins()->create([
            'checkin_time' => now() // or any specific timestamp
        ]);

        return response()->json(['message' => 'Noti created']);
    }



      // Get check-ins based on menu ID
      public function getCheckinsByMenuId($menuId)
      {
          $checkins = Order::where('menu_id', $menuId)
                           ->with('checkins')
                           ->get()
                           ->pluck('checkins')
                           ->flatten();
  
          return response()->json($checkins);
      }



    public function updateStatus(Request $request, $orderId)
    {
        // Define the allowed statuses in uppercase
        $allowedStatuses = ['NEW', 'ACCEPTED', 'REJECTED', 'TIMEOUT', 'DONE'];

        // Validate the request
        $request->validate([
            'status' => ['required', 'string', Rule::in($allowedStatuses)],
        ]);

        // Check if the status is in uppercase
        if ($request->status !== $request->status) {
            return response()->json(['error' => 'Status must be uppercase.'], 422);
        }

        // Find the order by ID
        $order = Order::findOrFail($orderId);

        $order->status = $request->status;

        if ($request->status == "DONE") {
         
            // Delete the associated check-ins
            $order->checkins()->delete();

        }

        $order->save();

        event(new MyEvent($orderId)); // newly added by Eng. Mohammed

        return response()->json(['message' => 'Order status updated successfully']);
    }

    public function filterOrders(Request $request, $menuId)
    {
        $query = Order::query();

        // Filter by menu ID from the URL parameter
        $query->where('menu_id', $menuId);

        // Add status filters if any of them is set to true
        $statuses = [];
        if ($request->input('NEW', false)) {
            $statuses[] = 'new';
        }
        if ($request->input('ACCEPTED', false)) {
            $statuses[] = 'accepted';
        }
        if ($request->input('REJECTED', false)) {
            $statuses[] = 'rejected';
        }
        if ($request->input('TIMEOUT', false)) {
            $statuses[] = 'timeout';
        }
        if ($request->input('DONE', false)) {
            $statuses[] = 'done'; // Add the 'done' status to your filter
        }


        $query->whereIn('status', $statuses);
        
        // Order by 'created_at' in descending order
        $query->orderBy('created_at', 'desc');


        $orders = $query->get();

        // Decode the order_info JSON string for each order
        $orders->transform(function ($order) {
            $order->order_info = json_decode($order->order_info, true);
            return $order;
        });

        return response()->json($orders);
    }

    public function getOrder($orderId)
    {
        // Fetch the order by ID
        $order = Order::find($orderId);

        // If the order doesn't exist, return a not found response
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        // Decode the order_info JSON into an array
        $orderInfo = json_decode($order->order_info, true);

        // Construct the response object
        $response = [
            'note' => $order->note,
            'menuId' => $order->menu_id,
            'carInfo' => [
                'carSize' => $order->car_size,
                'carColor' => $order->car_color
            ],
            'userInfo' => [
                'phone' => $order->phone
            ],
            'orderInfo' => $orderInfo,
            'paymentInfo' => [
                'paymentMethod' => $order->payment_method,
                'paymentId' => $order->payment_id,
                'totalPrice' => $order->total_price
            ]
        ];

        // Return the order in JSON format
        return response()->json($response);
    }

    public function getOrderStatus($orderId)
    {
        // Fetch the order by ID
        $order = Order::find($orderId);

        // If the order doesn't exist, return a not found response
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

      

        // Construct the response object
        $response = [
           
            'status' => $order->status,
        ];

        // Return the order in JSON format
        return response()->json($response);
    }


    public function placeOrder(Request $request)
    {
        // Validate the incoming request data
        $validator = Validator::make($request->all(), [
            'menuId' => 'required|integer',
            'note' => 'nullable|string',
            'carInfo.carSize' => 'required|in:small,mid,large',
            'carInfo.carColor' => 'required|string',
            'userInfo.phone' => 'required|string',
            'orderInfo' => 'required|array',
            'orderInfo.meals' => 'required|array',
            'paymentInfo.paymentMethod' => 'required|in:applePay,cash,machine',
            'paymentInfo.paymentId' => 'nullable|string',
            'paymentInfo.totalPrice' => 'required|numeric',
            // Add more validation rules as necessary
        ]);

        // If validation fails, return a JSON response with errors
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Extract validated data
        $validatedData = $validator->validated();


        // // Add the MealName for each meal
        // foreach ($validatedData['orderInfo']['meals'] as &$meal) {
        //     $meal['MealName'] = $this->getMealName($meal['mealId']);
        // }
        // unset($meal); // Break the reference with the last element

        // Add the MealImagePath for each meal
        foreach ($validatedData['orderInfo']['meals'] as &$meal) {
            $meal['mealImagePath'] = $this->getMealImagePath($meal['mealId']);
            $meal['mealName'] = $this->getMealName($meal['mealId']);
        }
        unset($meal); // Break the reference with the last element

        // Prepare the data for insertion
        $orderData = [
            'menu_id' => $validatedData['menuId'],
            'order_info' => json_encode($validatedData['orderInfo']), // orderInfo now includes MealImagePath
            'car_color' => $validatedData['carInfo']['carColor'],
            'phone' => $validatedData['userInfo']['phone'],
            'car_size' => $validatedData['carInfo']['carSize'],
            'payment_method' => $validatedData['paymentInfo']['paymentMethod'],
            'payment_id' => $validatedData['paymentInfo']['paymentId'] ?? null,
            'total_price' => $validatedData['paymentInfo']['totalPrice'],
            'note' => $validatedData['note'] ?? null,
            'status' => 'NEW', // Adjust according to your application logic
        ];

        // Create the order in the database
        $order = Order::create($orderData);

        // Trigger any relevant events after order creation
         event(new MyEvent($order->menu_id));

        // Return a JSON response with the generated order ID
        return response()->json(['orderId' => $order->id]);
    }



    // Helper function to fetch the meal's name based on mealId
    protected function getMealName($mealId)
    {
        $meal = Meal::find($mealId); // Assuming Meal is your Eloquent model for the meals table

        // Return the meal name if the meal is found, else return a default value or handle as needed
        return $meal ? $meal->name : 'Unknown Meal';
    }

    // Helper function to fetch the meal's image path based on mealId
    protected function getMealImagePath($mealId)
    {
        $meal = Meal::find($mealId); // Assuming Meal is your Eloquent model for the meals table

        // Return the image path if the meal is found, else return a default image path
        return $meal ? $meal->image_path : 'default_image_path.jpg';
    }

    // public function placeOrder(Request $request)
    // {
    //     // Validate the incoming request data
    //     $validator = Validator::make($request->all(), [
    //         'menuId' => 'required|integer',
    //         'note' => 'string',
    //         'carInfo.carSize' => 'required|in:small,mid,large',
    //         'carInfo.carColor' => 'required|string',
    //         'userInfo.phone' => 'required|string',
    //         'orderInfo' => 'required|array',
    //         'orderInfo.meals' => 'required|array',
    //         'paymentInfo.paymentMethod' => 'required|in:applePay,cash,machine',
    //         'paymentInfo.paymentId' => 'string',
    //         'paymentInfo.totalPrice' => 'required|numeric',

    //         // Add more validation rules as necessary
    //     ]);

    //     // If validation fails, return a JSON response with errors
    //     if ($validator->fails()) {
    //         return response()->json(['errors' => $validator->errors()], 422);
    //     }

    //     // Extract validated data
    //     $validatedData = $validator->validated();

    //     // Prepare the data for insertion
    //     $orderData = [
    //         'menu_id' => $validatedData['menuId'],
    //         'order_info' => json_encode($validatedData['orderInfo']), // Convert order info to JSON string
    //         'car_color' => $validatedData['carInfo']['carColor'],
    //         'phone' => $validatedData['userInfo']['phone'],
    //         'car_size' => $validatedData['carInfo']['carSize'],
    //         'payment_method' => $validatedData['paymentInfo']['paymentMethod'],
    //         'payment_id' => $validatedData['paymentInfo']['paymentId'] ?? null, // Optional field
    //         'total_price' => $validatedData['paymentInfo']['totalPrice'],
    //         'note' => $validatedData['note'] ?? null, // Set the note field, default to null if not provided
    //         'status' => 'NEW',
    //         // 'status' should be set according to your application's logic
    //     ];

    //     // Create the order in the database
    //     $order = Order::create($orderData);

    //     event(new MyEvent($order->menu_id));


    //     // Return a JSON response with the generated order ID
    //     return response()->json(['orderId' => $order->id]);
    // }


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
    public function store(StoreOrderRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Order $order)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Order $order)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateOrderRequest $request, Order $order)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Order $order)
    {
        //
    }
}
