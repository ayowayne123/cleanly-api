<?php

namespace App\Http\Requests;

use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // Clean the phone and email BEFORE checking they are unique
    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => Phone::normalize($this->input('phone')) ?? $this->input('phone'),
            'email' => $this->filled('email') ? strtolower(trim($this->input('email'))) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^\+234\d{10}$/', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'referral_code' => ['nullable', 'string', 'exists:clients,referral_code'],
            'preferred_contact' => ['nullable', Rule::in(['whatsapp', 'call', 'email'])],
            'marketing_opt_in' => ['nullable', 'boolean'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Please enter a valid Nigerian phone number.',
            'phone.unique' => 'That phone number is already registered.',
            'email.unique' => 'That email is already registered.',
            'referral_code.exists' => 'That referral code is not valid.',
        ];
    }
}