<?php

namespace App\Http\Requests\Platform;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class PlatformResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'max:255',
                'confirmed',
                Password::min(12)->mixedCase()->letters()->numbers()->symbols(),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && trim($value) !== $value) {
                        $fail('The password cannot begin or end with a space.');
                    }
                },
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }
}
