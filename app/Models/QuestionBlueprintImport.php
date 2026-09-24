<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BlueprintImportGroundingStatus;
use App\Enums\BlueprintImportInterpretationStatus;
use App\Enums\BlueprintImportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'material_id',
    'profile_version_id',
    'created_blueprint_id',
    'status',
    'original_file_name',
    'storage_path',
    'file_size',
    'file_hash',
    'mime_type',
    'extracted_text',
    'structured_document',
    'structure_schema_version',
    'interpretation_status',
    'interpretation_result',
    'interpretation_prompt_version',
    'interpretation_error_code',
    'interpretation_error_message',
    'interpretation_queued_at',
    'interpretation_claimed_at',
    'interpretation_completed_at',
    'grounding_status',
    'grounding_result',
    'grounding_prompt_version',
    'grounding_error_code',
    'grounding_error_message',
    'grounding_queued_at',
    'grounding_claimed_at',
    'grounding_completed_at',
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

    public function createdBlueprint(): BelongsTo
    {
        return $this->belongsTo(QuestionBlueprint::class, 'created_blueprint_id', 'blueprint_id');
    }

    protected function casts(): array
    {
        return [
            'status' => BlueprintImportStatus::class,
            'structured_document' => 'array',
            'interpretation_status' => BlueprintImportInterpretationStatus::class,
            'interpretation_result' => 'array',
            'grounding_status' => BlueprintImportGroundingStatus::class,
            'grounding_result' => 'array',
            'file_size' => 'integer',
            'queued_at' => 'datetime',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
            'interpretation_queued_at' => 'datetime',
            'interpretation_claimed_at' => 'datetime',
            'interpretation_completed_at' => 'datetime',
            'grounding_queued_at' => 'datetime',
            'grounding_claimed_at' => 'datetime',
            'grounding_completed_at' => 'datetime',
        ];
    }
}
