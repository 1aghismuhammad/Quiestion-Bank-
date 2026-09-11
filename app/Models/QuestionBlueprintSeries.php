<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\QuestionBlueprintSeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'material_id',
])]
class QuestionBlueprintSeries extends Model
{
    /** @use HasFactory<QuestionBlueprintSeriesFactory> */
    use HasFactory;

    protected $primaryKey = 'blueprint_series_id';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id', 'material_id');
    }

    public function blueprints(): HasMany
    {
        return $this->hasMany(QuestionBlueprint::class, 'blueprint_series_id', 'blueprint_series_id')
            ->orderBy('version');
    }
}
