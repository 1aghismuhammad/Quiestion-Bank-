<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use Database\Factories\MaterialProfileElementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'profile_version_id',
    'source_chunk_id',
    'kind',
    'text',
    'origin',
    'evidence_excerpt',
    'evidence_locator',
    'char_start',
    'char_end',
    'sort_order',
])]
class MaterialProfileElement extends Model
{
    /** @use HasFactory<MaterialProfileElementFactory> */
    use HasFactory;

    protected $primaryKey = 'profile_element_id';

    public function version(): BelongsTo
    {
        return $this->belongsTo(MaterialProfileVersion::class, 'profile_version_id', 'profile_version_id');
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(MaterialProfileChunk::class, 'source_chunk_id', 'profile_chunk_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MaterialProfileElementKind::class,
            'origin' => MaterialProfileElementOrigin::class,
            'char_start' => 'integer',
            'char_end' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
