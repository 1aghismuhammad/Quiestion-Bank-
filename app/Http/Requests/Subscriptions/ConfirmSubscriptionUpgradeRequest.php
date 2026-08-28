<?php

declare(strict_types=1);

namespace App\Http\Requests\Subscriptions;

use App\Models\SubscriptionUpgradeRequest;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmSubscriptionUpgradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SubscriptionUpgradeRequest::class) === true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'offer_id' => ['nullable', 'integer'],
        ];
    }
}
