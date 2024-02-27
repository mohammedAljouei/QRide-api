<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\AuthController;
use App\Http\Controllers\Api\v1\OtpController;
use App\Http\Controllers\Api\v1\SectionController;
use App\Http\Controllers\Api\v1\AddOnInfoController;
use App\Http\Controllers\Api\v1\AddOnTitleController;
use App\Http\Controllers\Api\v1\MealController;
use App\Http\Controllers\Api\v1\OrderController;
use App\Http\Controllers\Api\v1\ShopUIController;
use App\Http\Controllers\Api\v1\AdminController;
use App\Http\Controllers\Api\v1\SuggestionController;
use App\Http\Controllers\Api\v1\SuperAdminController;
use App\Http\Controllers\Api\v1\MenuController;

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
Route::post('/login-qride-admin', [AuthController::class, 'loginQrideAdmin']);
Route::post('/login-super-admin', [AuthController::class, 'loginSuperAdmin']);
Route::post('/login-admin', [AuthController::class, 'loginAdmin']);

// Logout
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);




// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });


Route::group(['prefix' => 'v1' , 'namespace' => 'App\Http\Controllers\Api\v1'], function () {
   Route::apiResource('add-on-infos' , AddOnInfoController::class); 
   Route::apiResource('add-on-titles' , AddOnTitleController::class); 
   Route::apiResource('meals' , MealController::class); 
   Route::apiResource('orders' , OrderController::class); 
   Route::get('/menu/{menu}', 'MenuController@getMenu'); 
   Route::post('/generate-otp', 'OtpController@generateOTP');
   Route::post('/verify-otp',  'OtpController@verifyOTP');
   Route::post('/place-order', 'OrderController@placeOrder'); 
   Route::get('/orders/{order}', 'OrderController@getOrder'); 
   Route::post('/post-feedback', 'SuggestionController@postFeedBack'); 

   Route::get('/menu/{menu}/payment-methods', 'MenuController@getPaymentMenu'); 



   




//    Route::get('/sections/{menuId}', 'SectionController@sectionsById');
   Route::apiResource('shop-uis' , ShopUIController::class); 
   Route::apiResource('suggestions' , SuggestionController::class); 
//    Route::apiResource('store-menu' , StoreMenuController::class); 
});


Route::group(['prefix' => 'v1' , 'namespace' => 'App\Http\Controllers\Api\v1' , 'middleware' => 'auth:sanctum'], function () {
    Route::apiResource('admins' , AdminController::class); 
    Route::get('/admins/{admin}', 'AdminController@show');

    Route::apiResource('super-admins' , SuperAdminController::class); 
    Route::get('/super-admins/{superAdmin}', 'SuperAdminController@show');

    Route::apiResource('shops' , MenuController::class); 
    Route::get('/shops/{shop}', 'MenuController@show');

    Route::apiResource('sections' , SectionController::class); 
    Route::get('sections/shop/{menu}', [SectionController::class, 'byMenu']);
    // Inside routes/api.php or routes/web.php
   

    // need delete section


 });
 


