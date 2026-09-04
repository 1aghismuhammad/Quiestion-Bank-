<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MaterialProfileChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'profile_version_id',
    'chunk_index',
    'char_start',
    'char_end',
    'overlap_before_start',
    'overlap_before_end',
    'core_text_hash',
    'required',
])]
class MaterialProfileChunk extends Model
{
    /** @use HasFactory<MaterialProfileChunkFactory> */
    use HasFactory;

    protected $primaryKey = 'profile_chunk_id';

    public function version(): BelongsTo
    {
        return $this->belongsTo(MaterialProfileVersion::class, 'profile_version_id', 'profile_version_id');
    }

    public function mapStep(): HasOne
    {
        return $this->hasOne(MaterialProfileStep::class, 'profile_chunk_id', 'profile_chunk_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'char_start' => 'integer',
            'char_end' => 'integer',
            'overlap_before_start' => 'integer',
            'overlap_before_end' => 'integer',
            'required' => 'boolean',
        ];
    }
}
