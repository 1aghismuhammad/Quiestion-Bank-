<?php

declare(strict_types=1);

namespace App\Enums;

enum QuestionSetStatus: string
{
    case DRAFT = 'draft';
    case GENERATING = 'generating';
    case REVIEW = 'review';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';
}
