<?php

declare(strict_types=1);

namespace App\Http\Requests\Subscriptions;

use App\Models\SubscriptionUpgradeRequest;
use Illuminate\Foundation\Http\FormRequest;

class RejectSubscriptionUpgradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $upgradeRequest = $this->route('upgradeRequest');

        return $upgradeRequest instanceof SubscriptionUpgradeRequest
            && $this->user()?->can('reject', $upgradeRequest) === true;
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('rejection_reason');

        if (is_string($reason)) {
            $this->merge([
                'rejection_reason' => trim($reason),
            ]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rejection_reason.required' => 'Alasan penolakan wajib diisi.',
        ];
    }
}
