<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiagnosisCategory extends Model
{
    protected $table = 'diagnosis_categories';

    protected $fillable = [
        'id',
        'parent_id',
        'category_name',
        'description',
        'keyword',
        'is_active',
        'sort_order',
        'depth',
        'path',
        'path_label',
        'created_by',
        'created_ip',
        'modified_by',
        'modified_ip',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'depth' => 'integer',
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function diagnosisCodes(): HasMany
    {
        return $this->hasMany(DiagnosisCode::class, 'category_id');
    }
}
