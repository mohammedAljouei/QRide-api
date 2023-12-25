<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user != null && $user->tokenCan('superAdmin');    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $method = $this->method();

        if ($method == 'PUT') {

     


            return [
                'menu_id' => ['required'], // customize this as per your application's requirements
                'name' => ['required'], // customize this as per your application's requirements
                'sort' => ['nullable' ], // customize this as per your application's requirements

                // Add any other rules if necessary
            ];


        } else{

            return [
                'menu_id' => ['required',  'sometimes'], // customize this as per your application's requirements
                'name' => ['required',  'sometimes'], // customize this as per your application's requirements
                'sort' => ['nullable' ,  'sometimes'], // customize this as per your application's requirements

                // Add any other rules if necessary
            ];
            
        }
    }
}
