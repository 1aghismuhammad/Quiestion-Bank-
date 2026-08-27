<?php

declare(strict_types=1);

namespace App\Http\Requests\Materials;

use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;

class MaterialTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        $material = $this->route('material');

        return $material instanceof Material
            && $this->user()?->can('manageTopics', $material) === true;
    }

    protected function prepareForValidation(): void
    {
        $nullable = [];

        foreach (['focus_area', 'page_start', 'page_end'] as $field) {
            if ($this->exists($field) && $this->input($field) === '') {
                $nullable[$field] = null;
            }
        }

        if ($this->exists('sort_order') && $this->input('sort_order') === '') {
            $nullable['sort_order'] = 0;
        }

        if ($nullable !== []) {
            $this->merge($nullable);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function topicInput(): array
    {
        return $this->only([
            'topic_name',
            'focus_area',
            'chapter',
            'sub_chapter',
            'sort_order',
            'page_start',
            'page_end',
        ]);
    }
}
