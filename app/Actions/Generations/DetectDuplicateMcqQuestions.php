<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use Normalizer;

class DetectDuplicateMcqQuestions
{
    /**
     * @param  list<string>  $existingTexts
     */
    public function isDuplicate(string $question, array $existingTexts): bool
    {
        $normalized = $this->normalize($question);

        if ($normalized === '') {
            return false;
        }

        foreach ($existingTexts as $existing) {
            if ($this->normalize($existing) === $normalized) {
                return true;
            }
        }

        return false;
    }

    public function normalize(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_C);
            $text = is_string($normalized) ? $normalized : $text;
        }

        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        return trim($text, " \t\n\r\0\x0B.,;:!?\"'()[]{}");
    }
}
