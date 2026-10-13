<?php

namespace App\Http\Requests\Platform;

use App\Models\OrganizationMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMerchantOrganizationAccessRequest extends FormRequest
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
                OrganizationMembership::ROLE_OWNER,
                OrganizationMembership::ROLE_ADMIN,
                OrganizationMembership::ROLE_MEMBER,
            ])],
        ];
    }
}
