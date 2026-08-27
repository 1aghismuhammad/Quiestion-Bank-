<?php

declare(strict_types=1);

namespace App\Actions\Materials;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class MaterialTopicInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     topic_name: string,
     *     focus_area: ?string,
     *     chapter: string,
     *     sub_chapter: string,
     *     sort_order: int,
     *     page_start: ?int,
     *     page_end: ?int
     * }
     */
    public static function validatedForCreate(array $input): array
    {
        $validated = self::validate($input, [
            'topic_name' => ['required', 'string', 'max:255'],
            'focus_area' => ['nullable', 'string', 'max:255'],
            'chapter' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sub_chapter' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'page_start' => ['nullable', 'integer', 'min:1'],
            'page_end' => ['nullable', 'integer', 'min:1'],
        ]);

        $attributes = [
            'topic_name' => self::requiredTopicName($validated['topic_name']),
            'focus_area' => self::nullableTrimmed($validated['focus_area'] ?? null),
            'chapter' => trim((string) ($validated['chapter'] ?? '')),
            'sub_chapter' => trim((string) ($validated['sub_chapter'] ?? '')),
            'sort_order' => $validated['sort_order'] ?? 0,
            'page_start' => $validated['page_start'] ?? null,
            'page_end' => $validated['page_end'] ?? null,
        ];

        self::assertPageRange($attributes['page_start'], $attributes['page_end']);

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|int|null>
     */
    public static function validatedForUpdate(array $input): array
    {
        $validated = self::validate($input, [
            'topic_name' => ['sometimes', 'required', 'string', 'max:255'],
            'focus_area' => ['sometimes', 'nullable', 'string', 'max:255'],
            'chapter' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sub_chapter' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'page_start' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page_end' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $attributes = [];

        if (array_key_exists('topic_name', $validated)) {
            $attributes['topic_name'] = self::requiredTopicName($validated['topic_name']);
        }

        if (array_key_exists('focus_area', $validated)) {
            $attributes['focus_area'] = self::nullableTrimmed($validated['focus_area']);
        }

        if (array_key_exists('chapter', $validated)) {
            $attributes['chapter'] = trim((string) $validated['chapter']);
        }

        if (array_key_exists('sub_chapter', $validated)) {
            $attributes['sub_chapter'] = trim((string) $validated['sub_chapter']);
        }

        if (array_key_exists('sort_order', $validated)) {
            $attributes['sort_order'] = $validated['sort_order'];
        }

        if (array_key_exists('page_start', $validated)) {
            $attributes['page_start'] = $validated['page_start'];
        }

        if (array_key_exists('page_end', $validated)) {
            $attributes['page_end'] = $validated['page_end'];
        }

        return $attributes;
    }

    public static function assertPageRange(?int $pageStart, ?int $pageEnd): void
    {
        if ($pageStart !== null && $pageEnd !== null && $pageEnd < $pageStart) {
            throw ValidationException::withMessages([
                'page_end' => 'Halaman akhir harus lebih besar atau sama dengan halaman awal.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, mixed>
     */
    private static function validate(array $input, array $rules): array
    {
        $input = Arr::only($input, [
            'topic_name',
            'focus_area',
            'chapter',
            'sub_chapter',
            'sort_order',
            'page_start',
            'page_end',
        ]);

        return Validator::make($input, $rules, [
            'topic_name.required' => 'Nama topik wajib diisi.',
            'page_start.min' => 'Nomor halaman harus lebih besar atau sama dengan 1.',
            'page_end.min' => 'Nomor halaman harus lebih besar atau sama dengan 1.',
        ])->validate();
    }

    private static function requiredTopicName(mixed $value): string
    {
        $topicName = trim((string) $value);

        if ($topicName === '') {
            throw ValidationException::withMessages([
                'topic_name' => 'Nama topik wajib diisi.',
            ]);
        }

        return $topicName;
    }

    private static function nullableTrimmed(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
