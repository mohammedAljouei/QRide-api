<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAddOnInfoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */

     public function authorize(): bool
     {
         $user = $this->user();
 
         return $user != null && $user->tokenCan('superAdmin');
     }
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'add_on_title_id' => 'required',
            'name' => 'required',
            'price' => 'required', // version is not required and can be null
            // Add other validation rules as needed
        ];
    }
}
