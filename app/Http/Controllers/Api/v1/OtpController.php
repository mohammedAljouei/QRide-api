<?php


namespace App\Http\Controllers\Api\v1;
use App\Http\Controllers\Controller; 


use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Cache; // For caching the OTP temporarily


class OtpController extends Controller
{
    public function generateOTP(Request $request)
    {
        $request->validate([
            'phone' => 'required', // Validate the phone number
            // the number should be like this: 966531035146
        ]);

        $phone = $request->phone;

        $basic  = new \Vonage\Client\Credentials\Basic("a11f4fbd", "6Xhsdmj2G3eCKT0S");
        $client = new \Vonage\Client(new \Vonage\Client\Credentials\Container($basic));

        $request = new \Vonage\Verify\Request($phone, "Vonage");
        $response = $client->verify()->start($request);

        // echo "Started verification, `request_id` is " . $response->getRequestId();
      
        return response()->json(['requestId' =>  $response->getRequestId()]);
    }


    public function verifyOTP(Request $request)
    {
        $request->validate([
            'requestId' => 'required', // Validate the phone number
            'code' => 'required', // Validate the phone number
            // the number should be like this: 966531035146
        ]);

        $requestId = $request->requestId;
        $code = $request->code;

        $basic  = new \Vonage\Client\Credentials\Basic("a11f4fbd", "6Xhsdmj2G3eCKT0S");
        $client = new \Vonage\Client(new \Vonage\Client\Credentials\Container($basic));

        $result = $client->verify()->check($requestId, $code);

        $result_text = $result->getResponseData();
      
        return response()->json(['result' =>  $result_text]);
    }


}
