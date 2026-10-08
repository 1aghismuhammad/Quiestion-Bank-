<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CancelAdminSubscriptionRequest extends FormRequest
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
            $allowed = ['_token', '_method', 'reason', 'idempotency_key'];

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
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
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
            'reason.required' => 'Alasan wajib diisi.',
            'idempotency_key.required' => 'Kunci idempotensi wajib diisi.',
            'idempotency_key.uuid' => 'Kunci idempotensi tidak valid.',
        ];
    }
}
