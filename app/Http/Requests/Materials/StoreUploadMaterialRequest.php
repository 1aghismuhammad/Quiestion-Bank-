<?php

declare(strict_types=1);

namespace App\Http\Requests\Materials;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;

class StoreUploadMaterialRequest extends FormRequest
{
    public const MAX_FILE_SIZE_KILOBYTES = 10 * 1024;

    /**
     * @var list<string>
     */
    public const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'txt'];

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
            'title' => ['required', 'string', 'max:255'],
            'file' => [
                'required',
                File::types(self::ALLOWED_EXTENSIONS)
                    ->extensions(self::ALLOWED_EXTENSIONS)
                    ->max(self::MAX_FILE_SIZE_KILOBYTES),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value instanceof UploadedFile && $value->getSize() === 0) {
                        $fail('File tidak boleh kosong.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Judul materi wajib diisi.',
            'file.required' => 'File materi wajib diunggah.',
            'file.mimes' => 'File harus berupa PDF, DOCX, atau TXT.',
            'file.extensions' => 'File harus berupa PDF, DOCX, atau TXT.',
            'file.max' => 'Ukuran file maksimal 10 MB.',
        ];
    }
}
