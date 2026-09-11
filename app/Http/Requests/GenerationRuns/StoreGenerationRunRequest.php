<?php

declare(strict_types=1);

namespace App\Http\Requests\GenerationRuns;

use App\Enums\OutputLanguage;
use App\Models\QuestionBlueprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        $blueprint = $this->route('blueprint');

        return $blueprint instanceof QuestionBlueprint
            && $this->user()?->can('view', $blueprint) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'output_language' => ['required', Rule::enum(OutputLanguage::class)],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
