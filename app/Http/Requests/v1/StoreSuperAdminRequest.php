<?php

// ignore this file, comment by mohammed.
namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;


class StoreSuperAdminRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {

        return [
            'name' => 'required|string|max:255|unique:super_admins,userName', // assuming 'userName' is a unique field
            // 'type'     => 'required|string|max:255', // customize this as per your application's requirements
            // Add any other rules if necessary
        ];

    }
}
