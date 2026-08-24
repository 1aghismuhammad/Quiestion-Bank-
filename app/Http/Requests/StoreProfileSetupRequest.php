<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProfileSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'phone_number' => [
                'required',
                'string',
                'regex:/^\+62[0-9]{8,13}$/',
                Rule::unique('whatsapp_contacts', 'phone_number')
                    ->ignore($this->user()?->whatsappContact?->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'phone_number.regex' => 'Nomor telepon harus menggunakan format Indonesia yang valid.',
            'phone_number.unique' => 'Nomor telepon sudah digunakan oleh akun lain.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $phoneNumber = (string) $this->input('phone_number', '');
        $digits = preg_replace('/\D+/', '', $phoneNumber) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif ($digits !== '' && ! str_starts_with($digits, '62')) {
            $digits = '62'.$digits;
        }

        $this->merge([
            'phone_number' => $digits === '' ? '' : '+'.$digits,
        ]);
    }
}
