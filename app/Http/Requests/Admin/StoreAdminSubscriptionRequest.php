<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAdminSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User
            && $this->user()?->can('manageSubscription', $user) === true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = ['_token', '_method', 'duration_months', 'reason', 'idempotency_key'];

            foreach ($this->keys() as $key) {
                if (! in_array($key, $allowed, true)) {
                    $validator->errors()->add($key, 'Kolom ini tidak dapat diubah.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        if (is_string($reason)) {
            $this->merge(['reason' => trim($reason)]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'duration_months' => ['required', 'integer', Rule::in([1, 3, 6, 12])],
            'reason' => ['required', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'duration_months.required' => 'Durasi wajib diisi.',
            'duration_months.in' => 'Durasi langganan tidak valid.',
            'reason.required' => 'Alasan wajib diisi.',
            'idempotency_key.required' => 'Kunci idempotensi wajib diisi.',
            'idempotency_key.uuid' => 'Kunci idempotensi tidak valid.',
        ];
    }
}
