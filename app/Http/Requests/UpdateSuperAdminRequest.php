<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;


class UpdateSuperAdminRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user != null && $user->tokenCan('qrideAdmin');

    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // return [
        //     //
        // ];

        $method = $this->method();

        if ($method == 'PUT') {


            return [
                'name' => ['required' , 'string' , 'max:255'], // customize this as per your application's requirements
                'email' => ['required' , 'string' , 'max:255'], // customize this as per your application's requirements
                'password' => ['required' , 'string' , 'max:255'], // customize this as per your application's requirements
                // Add any other rules if necessary
            ];


        } else{
                  return [

                    'name' => ['required' , 'string' , 'max:255' ,  'sometimes'], // customize this as per your application's requirements
                    'email' => ['required' , 'string' , 'max:255' ,  'sometimes'], // customize this as per your application's requirements
                    'password' => ['required' , 'string' , 'max:255' ,  'sometimes'], // customize this as per your application's requirements
                    // Add any other rules if necessary

        ];
        }


    }
}
