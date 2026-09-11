<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiGenerationRunItemSpanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'generation_run_item_id',
    'char_start',
    'char_end',
    'rank',
    'content_hash',
    'profile_element_id',
    'profile_chunk_id',
])]
class AiGenerationRunItemSpan extends Model
{
    /** @use HasFactory<AiGenerationRunItemSpanFactory> */
    use HasFactory;

    protected $primaryKey = 'generation_run_item_span_id';

    public function item(): BelongsTo
    {
        return $this->belongsTo(AiGenerationRunItem::class, 'generation_run_item_id', 'generation_run_item_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'char_start' => 'integer',
            'char_end' => 'integer',
            'rank' => 'integer',
        ];
    }
}
