<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BlueprintImportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'material_id',
    'profile_version_id',
    'status',
    'original_file_name',
    'storage_path',
    'file_size',
    'file_hash',
    'mime_type',
    'extracted_text',
    'material_content_hash',
    'material_file_hash',
    'extractor_implementation',
    'error_code',
    'error_message',
    'queued_at',
    'claimed_at',
    'completed_at',
])]
class QuestionBlueprintImport extends Model
{
    use HasFactory;

    protected $primaryKey = 'import_id';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id', 'material_id');
    }

    public function profileVersion(): BelongsTo
    {
        return $this->belongsTo(MaterialProfileVersion::class, 'profile_version_id', 'profile_version_id');
    }

    protected function casts(): array
    {
        return [
            'status' => BlueprintImportStatus::class,
            'file_size' => 'integer',
            'queued_at' => 'datetime',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
