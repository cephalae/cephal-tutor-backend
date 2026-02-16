<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiagnosisCode extends Model
{
    protected $table = 'diagnosis_codes';

    protected $fillable = [
        'id',
        'category_id',
        'code',
        'long_description',
        'short_description',
        'created_at',
        'updated_at',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(DiagnosisCategory::class, 'category_id');
    }
}
