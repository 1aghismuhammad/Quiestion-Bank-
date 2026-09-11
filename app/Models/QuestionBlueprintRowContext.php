<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\QuestionBlueprintRowContextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'blueprint_row_id',
    'profile_element_id',
    'profile_chunk_id',
    'char_start',
    'char_end',
    'context_hash',
    'rank',
])]
class QuestionBlueprintRowContext extends Model
{
    /** @use HasFactory<QuestionBlueprintRowContextFactory> */
    use HasFactory;

    protected $primaryKey = 'blueprint_row_context_id';

    public function row(): BelongsTo
    {
        return $this->belongsTo(QuestionBlueprintRow::class, 'blueprint_row_id', 'blueprint_row_id');
    }

    public function profileElement(): BelongsTo
    {
        return $this->belongsTo(MaterialProfileElement::class, 'profile_element_id', 'profile_element_id');
    }

    public function profileChunk(): BelongsTo
    {
        return $this->belongsTo(MaterialProfileChunk::class, 'profile_chunk_id', 'profile_chunk_id');
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
