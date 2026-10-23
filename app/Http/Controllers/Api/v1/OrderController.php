<?php

namespace App\Http\Controllers\Api\v1;

use App\Events\IamHere;
use App\Events\TimeOut;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Order;
use App\Models\Meal;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Events\MyEvent;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use App\Models\Customer;
use App\Services\WalletService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;


class OrderController extends Controller
{

    // An order nobody accepted within this time is timed out (by the server, whatever the customer's phone does)
    public const TIMEOUT_SECONDS = 180;

    public function setTimeOut($orderId)
    {
        $order = Order::findOrFail($orderId);
        if ($order->status !== 'NEW') {
            return response()->json(['error' => 'Sorry, action already taken on this order.'], 422);
        }
        if (self::timeoutIfLate($order)) {
            return response()->json(['message' => 'Order status updated to TIMEOUT due to inactivity.']);
        }
        return response()->json(['message' => 'Order has not been inactive long enough to timeout.']);
    }

    // Seconds the shop still has to accept a NEW order
    private static function secondsLeft(Order $order): int
    {
        return max(0, self::TIMEOUT_SECONDS - Carbon::parse($order->created_at)->diffInSeconds(Carbon::now()));
    }

    // NEW for longer than the limit: TIMEOUT, any wallet hold released, and the staff dashboard told. Only once.
    private static function timeoutIfLate(Order $order): bool
    {
        if ($order->status !== 'NEW' || self::secondsLeft($order) > 0) {
            return false;
        }
        $updated = Order::whereKey($order->id)->where('status', 'NEW')->update(['status' => 'TIMEOUT']);
        if (!$updated) {
            return false; // accepted or timed out a moment ago by another request
        }
        $order->status = 'TIMEOUT';
        try {
            WalletService::releaseOrder($order); // v2 wallet: give back any held amount
        } catch (\Throwable $e) {
            Log::error('Wallet release failed for timed out order ' . $order->id . ': ' . $e->getMessage());
        }
        event(new TimeOut($order->menu_id . "3"));
        return true;
    }



    public function setNotiByCustomer($orderId)
    {

        // Find the order by ID
        $order = Order::findOrFail($orderId);


        $order->checkins()->create([
            'checkin_time' => now() // or any specific timestamp
        ]);

        // Trigger any relevant events after order creation
        $idForNoti = $order->menu_id . "2";
        event(new IamHere($idForNoti));

        return response()->json(['message' => 'Noti created under event id: ' . $idForNoti]);
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

        // v2 wallet: done = the wallet payment is taken and cashback credited; rejected/timeout = the hold is released
        try {
            if ($request->status == "DONE") {
                WalletService::settleOrder($order);
            } elseif (in_array($request->status, ['REJECTED', 'TIMEOUT'])) {
                WalletService::releaseOrder($order);
            }
        } catch (\Throwable $e) {
            Log::error('Wallet update failed for order ' . $order->id . ': ' . $e->getMessage());
        }

        event(new MyEvent($orderId)); // newly added by Eng. Mohammed

        // Immediately respond to the client
        $response = response()->json(['message' => 'Order status updated successfully']);

        // // Handle the delayed timeout update in a separate process
        // if (!in_array($request->status, ['DONE', 'REJECTED', 'ACCEPTED'])) {
        //     Artisan::call('handle:order-timeout', [
        //         'orderId' => $orderId,
        //         '--delay' => 90 // delay in seconds
        //     ]);
        // }

        return $response;
    }

    // public function filterOrders(Request $request, $menuId)
    // {
    //     $query = Order::query();

    //     // Filter by menu ID from the URL parameter
    //     $query->where('menu_id', $menuId);

    //     // Add status filters if any of them is set to true
    //     $statuses = [];
    //     if ($request->input('NEW', false)) {
    //         $statuses[] = 'new';
    //     }
    //     if ($request->input('ACCEPTED', false)) {
    //         $statuses[] = 'accepted';
    //     }
    //     if ($request->input('REJECTED', false)) {
    //         $statuses[] = 'rejected';
    //     }
    //     if ($request->input('TIMEOUT', false)) {
    //         $statuses[] = 'timeout';
    //     }
    //     if ($request->input('DONE', false)) {
    //         $statuses[] = 'done'; // Add the 'done' status to your filter
    //     }


    //     $query->whereIn('status', $statuses);

    //     // Order by 'created_at' in descending order
    //     $query->orderBy('created_at', 'desc');


    //     $orders = $query->get();

    //     // Decode the order_info JSON string for each order
    //     $orders->transform(function ($order) {
    //         $order->order_info = json_decode($order->order_info, true);
    //         return $order;
    //     });

    //     return response()->json($orders);
    // }


    public function filterOrders(Request $request, $menuId)
    {
        // Orders nobody accepted in time are timed out before the staff list is built
        Order::where('menu_id', $menuId)->where('status', 'NEW')
            ->where('created_at', '<', Carbon::now()->subSeconds(self::TIMEOUT_SECONDS))
            ->get()->each(fn (Order $o) => self::timeoutIfLate($o));

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

        if (!empty($statuses)) {
            $query->whereIn('status', $statuses);
        }

        // Order by 'created_at' in descending order
        $query->orderBy('created_at', 'desc');

        $orders = $query->with('checkins')->get(); // Load the related check-ins

        // Decode the order_info JSON string for each order and check for check-ins
        $orders->transform(function ($order) {
            $order->order_info = json_decode($order->order_info, true);
            $order->checkin = $order->checkins->isNotEmpty(); // Check if there are any check-ins
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



        self::timeoutIfLate($order);

        $response = [
            'status' => $order->status,
        ];
        if ($order->status === 'NEW') {
            $response['secondsLeft'] = self::secondsLeft($order); // the customer's countdown
        }

        // v2 wallet: the drawn percent (revealed right away) and, once done, the amount earned
        if ($order->cashback_percent > 0) {
            $response['cashbackPercent'] = (float) $order->cashback_percent;
        }
        if ($order->cashback_amount > 0) {
            $response['cashbackAmount'] = (float) $order->cashback_amount;
        }

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
            'wallet.amount' => 'nullable|numeric|min:0', // v2: part of the total paid from the wallet
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

        // v2 wallet: paying part of the order from the wallet needs a phone-verified device
        $walletAmount = round((float) ($validatedData['wallet']['amount'] ?? 0), 2);
        $spender = $walletAmount > 0 ? auth('sanctum')->user() : null;
        if ($walletAmount > 0 && (!WalletService::enabledFor($validatedData['menuId']) || !($spender instanceof Customer) || !$spender->tokenCan('spend'))) {
            return response()->json(['message' => 'wallet_verify_required'], 422);
        }

        // Create the order (and, when paying from the wallet, its hold) together: if the hold fails, no order
        try {
            $order = DB::transaction(function () use ($orderData, $walletAmount, $spender, $validatedData) {
                $order = Order::create($orderData);
                if ($walletAmount > 0) {
                    $order->server_total = WalletService::serverTotal($validatedData['orderInfo']['meals']);
                    WalletService::holdForOrder($order, $spender, $walletAmount);
                }
                return $order;
            });
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'wallet_')) {
                return response()->json(['message' => $e->getMessage()], 422); // wallet_insufficient | wallet_amount_invalid
            }
            throw $e;
        }

        // v2 wallet: link the order to the customer and record the real total (shops with the wallet on only)
        $customerToken = null;
        try {
            if ($walletAmount <= 0 && WalletService::enabledFor($order->menu_id)) {
                $customer = WalletService::customerForPhone($validatedData['userInfo']['phone']);
                if ($customer) {
                    $order->customer_id = $customer->id;
                    $order->server_total = WalletService::serverTotal($validatedData['orderInfo']['meals']);
                    $order->save();

                    // Give this device a wallet token unless it already has one for this customer
                    $current = auth('sanctum')->user();
                    if (!($current instanceof Customer) || $current->id !== $customer->id) {
                        $customerToken = $customer->createToken('wallet', WalletService::abilitiesForPhone())->plainTextToken;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Wallet link failed for order ' . $order->id . ': ' . $e->getMessage());
        }

        // v2 wallet: draw this order's cashback percent (the order page reveals it)
        try {
            WalletService::drawForOrder($order);
        } catch (\Throwable $e) {
            Log::error('Cashback draw failed for order ' . $order->id . ': ' . $e->getMessage());
        }

        // Trigger any relevant events after order creation
        event(new MyEvent($order->menu_id));

        // Return a JSON response with the generated order ID
        return response()->json(array_filter(['orderId' => $order->id, 'customerToken' => $customerToken]));
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
