<?php

declare(strict_types=1);

namespace App\Http\Requests\Materials;

use App\Enums\SourceType;
use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $material = $this->route('material');

        return $material instanceof Material
            && $this->user()?->can('update', $material) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $material = $this->route('material');
        $rules = [
            'title' => ['required', 'string', 'max:255'],
        ];

        if ($material instanceof Material && $material->source_type === SourceType::TEXT) {
            $rules['content'] = ['required', 'string'];
        } else {
            $rules['content'] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Judul materi wajib diisi.',
        ];
    }
}
