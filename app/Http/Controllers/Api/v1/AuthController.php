<?php

namespace App\Http\Controllers\Api\v1;
use App\Http\Controllers\Controller; 
use Illuminate\Support\Facades\Hash; 
use Illuminate\Http\Request;
use App\Models\SuperAdmin;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Validation\ValidationException; 
use App\Models\Menu;

class AuthController extends Controller
{
    //


    public function loginQrideAdmin(Request $request)
    {


        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $qrideAdmin = User::where('email', $request->email)->first();

        // if (!$qrideAdmin || !Hash::check($request->password, $qrideAdmin->password)) {
        
        if (!$qrideAdmin || $request->password !== $qrideAdmin->password) {

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return response()->json(['id'=> $qrideAdmin->id, 'token' => $qrideAdmin->createToken('authToken',  ['qrideAdmin'])->plainTextToken]);
    }



    public function loginSuperAdmin(Request $request)
    {

       
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $superAdmin = SuperAdmin::where('email', $request->email)->first();

        if (!$superAdmin || !Hash::check($request->password, $superAdmin->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }



        $menu = Menu::where('super_admin_id', $superAdmin->id)->first();

        if ($menu) {
            return response()->json([ 'menuId'=> $menu->id,'token' => $superAdmin->createToken('authToken',  ['superAdmin'])->plainTextToken]);
            
        } else {
            // Handle the case where no Menu is found for the provided adminId
            return response()->json(['error' => 'Please Create Shop for this super admin first'], 404);
        }


    }


    public function loginAdmin(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $admin = Admin::where('email', $request->email)->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $menu = Menu::where('admin_id', $admin->id)->first();

        if ($menu) {
            return response()->json([ 'menuId'=> $menu->id,'token' => $admin->createToken('authToken',  ['admin'])->plainTextToken]);
            
        } else {
            // Handle the case where no Menu is found for the provided adminId
            return response()->json(['error' => 'Please Create Shop for this admin first'], 404);
        }

       
    }

    /**
     * Logout the super admin.
     */
    // public function logout(Request $request)
    // {
    //     $request->user()->currentAccessToken()->delete();

    //     return response()->json(['message' => 'Successfully logged out']);
    // }

    public function logout(Request $request)
{
    $user = $request->user();

    if ($user) {
        $user->currentAccessToken()->delete();
        return response()->json(['message' => 'Successfully logged out']);
    }

    return response()->json(['message' => 'No authenticated user'], 401);
}

}
