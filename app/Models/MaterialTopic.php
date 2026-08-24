<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MaterialTopicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'material_id',
    'topic_name',
    'focus_area',
    'chapter',
    'sub_chapter',
    'sort_order',
    'page_start',
    'page_end',
])]
class MaterialTopic extends Model
{
    /** @use HasFactory<MaterialTopicFactory> */
    use HasFactory;

    protected $primaryKey = 'topic_id';

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id', 'material_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'page_start' => 'integer',
            'page_end' => 'integer',
        ];
    }
}
