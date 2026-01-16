<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicalRecord extends Model
{
    protected $fillable = [
        'category_id',
        'source_uid',
        'patient_name',
        'age',
        'gender',
        'chief_complaints',
        'case_description',
        'is_active',
    ];


    protected $casts = [
        'is_active' => 'boolean',
        'age' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(RecordCategory::class, 'category_id');
    }

    public function codes(): HasMany
    {
        return $this->hasMany(MedicalRecordCode::class, 'medical_record_id')->orderBy('sort_order');
    }
}
