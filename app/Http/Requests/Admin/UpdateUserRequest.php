<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User
            && $this->user()?->can('update', $user) === true;
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $allowed = ['_token', '_method', 'status'];
            foreach ($this->keys() as $key) {
                if (! in_array($key, $allowed, true)) {
                    $validator->errors()->add($key, 'Kolom ini tidak dapat diubah.');
                }
            }
        });
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                UserStatus::ACTIVE->value,
                UserStatus::INACTIVE->value,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Status akun wajib diisi.',
            'status.in' => 'Status akun tidak valid.',
        ];
    }
}
