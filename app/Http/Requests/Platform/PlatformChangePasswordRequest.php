<?php

namespace App\Http\Requests\Platform;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class PlatformChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
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
}
