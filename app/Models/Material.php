<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'title',
    'source_type',
    'file_name',
    'file_path',
    'file_size',
    'file_hash',
    'mime_type',
    'content',
    'extraction_status',
    'status',
])]
class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory, SoftDeletes;

    protected $primaryKey = 'material_id';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(MaterialTopic::class, 'material_id', 'material_id')
            ->orderBy('sort_order')
            ->orderBy('topic_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => SourceType::class,
            'file_size' => 'integer',
            'extraction_status' => ExtractionStatus::class,
            'status' => MaterialStatus::class,
        ];
    }
}
