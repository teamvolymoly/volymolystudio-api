<?php

namespace App\Http\Requests\Platform;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlatformStoreStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([Store::STATUS_ACTIVE, Store::STATUS_SUSPENDED])],
        ];
    }
}
