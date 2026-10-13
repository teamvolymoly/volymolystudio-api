<?php

namespace App\Http\Requests\Platform;

use App\Models\StoreMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMerchantStoreAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in([
                StoreMembership::ROLE_OWNER,
                StoreMembership::ROLE_ADMIN,
                StoreMembership::ROLE_MANAGER,
                StoreMembership::ROLE_STAFF,
            ])],
        ];
    }
}
