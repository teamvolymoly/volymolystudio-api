<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class CreatePlatformStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:120'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'timezone' => ['required', 'timezone'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $currency = $this->input('currency');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'currency' => is_string($currency) ? Str::upper(trim($currency)) : $currency,
        ]);
    }
}
