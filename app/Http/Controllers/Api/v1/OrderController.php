<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Order;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class OrderController extends Controller
{




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



    public function placeOrder(Request $request)
    {
        // Validate the incoming request data
        $validator = Validator::make($request->all(), [
            'menuId' => 'required|integer',
            'note' => 'string',
            'carInfo.carSize' => 'required|in:small,mid,large',
            'carInfo.carColor' => 'required|string',
            'userInfo.phone' => 'required|string',
            'orderInfo' => 'required|array',
            'orderInfo.meals' => 'required|array',
            'paymentInfo.paymentMethod' => 'required|in:applePay,cash,machine',
            'paymentInfo.paymentId' => 'string',
            'paymentInfo.totalPrice' => 'required|numeric',

            // Add more validation rules as necessary
        ]);

        // If validation fails, return a JSON response with errors
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Extract validated data
        $validatedData = $validator->validated();

        // Prepare the data for insertion
        $orderData = [
            'menu_id' => $validatedData['menuId'],
            'order_info' => json_encode($validatedData['orderInfo']), // Convert order info to JSON string
            'car_color' => $validatedData['carInfo']['carColor'],
            'phone' => $validatedData['userInfo']['phone'],
            'car_size' => $validatedData['carInfo']['carSize'],
            'payment_method' => $validatedData['paymentInfo']['paymentMethod'],
            'payment_id' => $validatedData['paymentInfo']['paymentId'] ?? null, // Optional field
            'total_price' => $validatedData['paymentInfo']['totalPrice'],
            'note' => $validatedData['note'] ?? null, // Set the note field, default to null if not provided
            'status' => 'NEW',
            // 'status' should be set according to your application's logic
        ];

        // Create the order in the database
        $order = Order::create($orderData);

        // Return a JSON response with the generated order ID
        return response()->json(['orderId' => $order->id]);
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
