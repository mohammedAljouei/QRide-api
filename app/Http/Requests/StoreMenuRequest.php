<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMenuRequest extends FormRequest
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
        return [
            'admin_id' => 'required',
            'super_admin_id' => 'required',
            'version' => 'nullable', // version is not required and can be null
            'status' => 'nullable',  // status is not required and can be null
            'payment_methods' => 'nullable',  // status is not required and can be null
            'color' => 'nullable', 
            'name' => 'nullable',  
            'slogan' => 'nullable',
            'platforms' => 'nullable'

            // Add other validation rules as needed
        ];
    }
}
