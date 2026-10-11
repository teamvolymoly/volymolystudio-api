<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class CreatePlatformOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:255', 'exists:users,email'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $email = $this->input('owner_email');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'owner_email' => is_string($email) ? Str::lower(trim($email)) : $email,
        ]);
    }
}
