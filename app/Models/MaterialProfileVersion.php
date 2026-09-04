<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaterialProfileStatus;
use Database\Factories\MaterialProfileVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'material_id',
    'user_id',
    'version',
    'status',
    'workflow_token',
    'queued_at',
    'started_at',
    'completed_at',
    'failed_at',
    'error_code',
    'error_message',
    'material_content_hash',
    'material_file_hash',
    'extractor_implementation',
])]
class MaterialProfileVersion extends Model
{
    /** @use HasFactory<MaterialProfileVersionFactory> */
    use HasFactory;

    protected $primaryKey = 'profile_version_id';

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id', 'material_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(MaterialProfileChunk::class, 'profile_version_id', 'profile_version_id')
            ->orderBy('chunk_index');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(MaterialProfileStep::class, 'profile_version_id', 'profile_version_id')
            ->orderBy('profile_step_id');
    }

    public function elements(): HasMany
    {
        return $this->hasMany(MaterialProfileElement::class, 'profile_version_id', 'profile_version_id')
            ->orderBy('sort_order')
            ->orderBy('profile_element_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(MaterialProfileAttempt::class, 'profile_version_id', 'profile_version_id')
            ->orderBy('profile_attempt_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => MaterialProfileStatus::class,
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
