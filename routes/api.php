<?php

use App\Http\Controllers\Api\v1\AddOnInfoController;
use App\Http\Controllers\Api\v1\AddOnTitleController;
use App\Http\Controllers\Api\v1\OrderController;
use App\Models\Meal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\AuthController;

use App\Http\Controllers\Api\v1\SectionController;
use App\Http\Controllers\Api\v1\MealController;
use App\Http\Controllers\Api\v1\TimeslotController;
use App\Http\Controllers\Api\v1\LandingPageController;
use App\Http\Controllers\Api\v1\WalletController;
use App\Http\Controllers\Api\v1\CustomerAuthController;
use App\Http\Controllers\Api\v1\ReceiptController;
use App\Http\Controllers\Api\v1\CounterController;
use App\Http\Controllers\Api\v1\QrideAdminController;
use App\Http\Controllers\Api\v1\ManagerMenuController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/




// Registration
// Route::post('/register', [AuthController::class, 'register']);

// Login
Route::post('/login-qride-admin', [AuthController::class, 'loginQrideAdmin'])->middleware('throttle:10,1');
Route::post('/login-super-admin', [AuthController::class, 'loginSuperAdmin']);
Route::post('/login-admin', [AuthController::class, 'loginAdmin']);

// Logout
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });


Route::group(['prefix' => 'v1' , 'namespace' => 'App\Http\Controllers\Api\v1'], function () {

   // this is for landing page
   Route::post('store-details', [LandingPageController::class, 'storeDetails']);

   Route::get('orders/noti/{menu}', [OrderController::class, 'getCheckinsByMenuId']);




   Route::apiResource('add-on-infos' , AddOnInfoController::class); 
   Route::apiResource('add-on-titles' , AddOnTitleController::class); 
   Route::apiResource('meals' , MealController::class); 
   Route::apiResource('orders' , OrderController::class); 
   Route::get('/menu/{menu}', 'MenuController@getMenu'); // added by mohammed 
   Route::get('/menu/menuIdByAdmin/{adminId}', 'MenuController@getMenuIdByAdminId'); // added by mohammed 
   Route::get('/menu/menuIdBySuperAdmin/{superAdminId}', 'MenuController@getMenuIdBySuperAdminId'); // added by mohammed 
   
   Route::get('/menu/{menu}/payment-methods', 'MenuController@getPaymentMenu'); // added by mohammed
   Route::get('/menu/{menu}/social-platforms', 'MenuController@getSocialPlatformsByMenuId'); // added by mohammed

   
   Route::get('/menu/{menu}/shop-info', 'MenuController@getShopInfoMenu'); // added by mohammed
   

   Route::post('/place-order', 'OrderController@placeOrder'); 
   Route::post('/post-feedback', 'SuggestionController@postFeedBack'); 




//    Route::get('/sections/{menuId}', 'SectionController@sectionsById');
   Route::apiResource('shop-uis' , ShopUIController::class); 
   Route::apiResource('suggestions' , SuggestionController::class); 
//    Route::apiResource('store-menu' , StoreMenuController::class); 

   Route::get('/timeslots/{menuId}', [TimeslotController::class, 'show']);
   Route::get('/timeslots/{menuId}/check', [TimeslotController::class, 'checkAvailability']);
   Route::get('/orders/status/{orderId}', 'OrderController@getOrderStatus');
   Route::post('/orders/noti/{orderId}', 'OrderController@setNotiByCustomer');
   Route::post('/orders/validateTimeout/{orderId}', 'OrderController@setTimeOut');

   


   




});


Route::group(['prefix' => 'v1' , 'namespace' => 'App\Http\Controllers\Api\v1' , 'middleware' => ['auth:sanctum', 'deny.customers']], function () {
    Route::apiResource('admins' , AdminController::class); 
    Route::get('/admins/{admin}', 'AdminController@show');

    Route::apiResource('super-admins' , SuperAdminController::class); 
    Route::get('/super-admins/{superAdmin}', 'SuperAdminController@show');



    Route::apiResource('shops' , MenuController::class); 
    Route::get('/shops/{shop}', 'MenuController@show');

    // start super admin
    Route::apiResource('sections' , SectionController::class); 
    Route::get('sections/shop/{menu}', [SectionController::class, 'byMenu']);
    Route::apiResource('meals' , MealController::class); 
    Route::get('meals/section/{secation}', [MealController::class, 'byMenu']);
    Route::apiResource('add-on-titles' , AddOnTitleController::class); 
    Route::get('add-on-titles/meal/{mealId}', [AddOnTitleController::class, 'byMenu']);
    Route::apiResource('add-on-infos' , AddOnInfoController::class); 
    Route::get('add-on-infos/add-on-title/{addOnTitle}', [AddOnInfoController::class, 'byMenu']);

    Route::delete('/section/{section}', 'SectionController@destroy');
    Route::delete('/meal/{meal}', 'MealController@destroy');
    Route::delete('/add-on-title/{addOnTitle}', 'AddOnTitleController@destroy');
    Route::delete('/add-on-info/{addOnInfo}', 'AddOnInfoController@destroy');


    Route::post('/timeslots', [TimeslotController::class, 'store']);


   // end super admin


   



   Route::get('meals/shop/{menu}', [MealController::class, 'byMenuId']);   
   Route::get('add-on-titles/shop/{menu}', [AddOnTitleController::class, 'byMenuId']);
   Route::get('add-on-infos/shop/{menu}', [AddOnInfoController::class, 'byMenuId']);



    Route::get('/orders/{order}', 'OrderController@getOrder'); 
    Route::post('/orders/filter/{menuId}', 'OrderController@filterOrders');
    Route::post('/orders/status/{orderId}', 'OrderController@updateStatus');

    Route::post('/meals/status/{mealId}', 'MealController@updateStatus');



    // Inside routes/api.php or routes/web.php
   

    // need delete section


 });


// v2 wallet: customer endpoints (customer tokens only)
Route::group(['prefix' => 'v1', 'middleware' => 'auth:sanctum'], function () {
    Route::get('/shops/{menu}/wallet', [WalletController::class, 'show']);
    Route::get('/shops/{menu}/wallet/qr', [WalletController::class, 'qr']);
    Route::post('/shops/{menu}/receipts', [ReceiptController::class, 'claim'])->middleware('throttle:20,1');
    Route::get('/shops/{menu}/counter/pending', [CounterController::class, 'pending']);
    Route::post('/counter/charges/{charge}/respond', [CounterController::class, 'respond']);
});

// v2 wallet: phone verification before spending (rate-limited per IP)
Route::group(['prefix' => 'v1'], function () {
    Route::post('/customer/device', [CustomerAuthController::class, 'device'])->middleware('throttle:10,1');
    Route::post('/customer/phone', [CustomerAuthController::class, 'phone'])->middleware('throttle:10,1');
    Route::post('/customer/otp/start', [CustomerAuthController::class, 'startOtp'])->middleware('throttle:5,1');
    Route::post('/customer/otp/verify', [CustomerAuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
});

// v2 counter: cashier (staff logins only)
Route::group(['prefix' => 'v1', 'middleware' => ['auth:sanctum', 'deny.customers']], function () {
    Route::get('/counter/scan', [CounterController::class, 'scan']);
    Route::post('/counter/charges', [CounterController::class, 'createCharge']);
    Route::get('/counter/charges/{charge}', [CounterController::class, 'chargeStatus']);
});

// QRide admin panel (/qride): onboarding and editing shops. The controller allows QRide admin logins only.
Route::group(['prefix' => 'v1/qride', 'middleware' => ['auth:sanctum', 'deny.customers', 'throttle:120,1']], function () {
    Route::get('/shops', [QrideAdminController::class, 'shops']);
    Route::post('/shops', [QrideAdminController::class, 'store']);
    Route::get('/shops/{id}', [QrideAdminController::class, 'show'])->whereNumber('id');
    Route::post('/shops/{id}', [QrideAdminController::class, 'update'])->whereNumber('id'); // POST: multipart with the logo
    Route::post('/shops/{id}/reset-password', [QrideAdminController::class, 'resetPassword'])->whereNumber('id');
    Route::post('/shops/{id}/portal', [QrideAdminController::class, 'portal'])->whereNumber('id');
});

// Branch manager's menu editor (/manager): their own shop only (checked in the controller)
Route::group(['prefix' => 'v1/manager/{menuId}', 'middleware' => ['auth:sanctum', 'deny.customers', 'throttle:240,1'], 'where' => ['menuId' => '[0-9]+']], function () {
    Route::get('/menu', [ManagerMenuController::class, 'show']);
    Route::post('/sections', [ManagerMenuController::class, 'createSection']);
    Route::post('/sections/order', [ManagerMenuController::class, 'orderSections']);
    Route::post('/sections/{sectionId}', [ManagerMenuController::class, 'updateSection'])->whereNumber('sectionId');
    Route::delete('/sections/{sectionId}', [ManagerMenuController::class, 'deleteSection'])->whereNumber('sectionId');
    Route::post('/sections/{sectionId}/order', [ManagerMenuController::class, 'orderMeals'])->whereNumber('sectionId');
    Route::post('/meals', [ManagerMenuController::class, 'createMeal']);
    Route::post('/meals/{mealId}', [ManagerMenuController::class, 'updateMeal'])->whereNumber('mealId');
    Route::post('/meals/{mealId}/available', [ManagerMenuController::class, 'setAvailable'])->whereNumber('mealId');
    Route::delete('/meals/{mealId}', [ManagerMenuController::class, 'deleteMeal'])->whereNumber('mealId');
    Route::post('/images', [ManagerMenuController::class, 'uploadImage']);
});
