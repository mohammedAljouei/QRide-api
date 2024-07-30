<?php

use App\Http\Controllers\Api\v1\AddOnInfoController;
use App\Http\Controllers\Api\v1\AddOnTitleController;
use App\Http\Controllers\Api\v1\OrderController;
use App\Models\Meal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\AuthController;
use App\Http\Controllers\Api\v1\OtpController;

use App\Http\Controllers\Api\v1\SectionController;
use App\Http\Controllers\Api\v1\MealController;
use App\Http\Controllers\Api\v1\TimeslotController;
use App\Http\Controllers\Api\v1\LandingPageController;
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

// this is for landing page
Route::post('/store-details', [LandingPageController::class, 'storeDetails']);


// Registration
// Route::post('/register', [AuthController::class, 'register']);

// Login
Route::post('/login-qride-admin', [AuthController::class, 'loginQrideAdmin']);
Route::post('/login-super-admin', [AuthController::class, 'loginSuperAdmin']);
Route::post('/login-admin', [AuthController::class, 'loginAdmin']);

// Logout
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });


Route::group(['prefix' => 'v1' , 'namespace' => 'App\Http\Controllers\Api\v1'], function () {

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
   

   Route::post('/generate-otp', 'OtpController@generateOTP');
   Route::post('/verify-otp',  'OtpController@verifyOTP');
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


Route::group(['prefix' => 'v1' , 'namespace' => 'App\Http\Controllers\Api\v1' , 'middleware' => 'auth:sanctum'], function () {
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
 


