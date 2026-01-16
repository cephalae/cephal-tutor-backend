<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentRecordAssignment extends Model
{
    protected $fillable = [
        'student_id',
        'medical_record_id',
        'category_id',
        'status',
        'attempts_used',
        'max_attempts',
        'last_attempt_at',
    ];

    protected $casts = [
        'attempts_used' => 'integer',
        'max_attempts' => 'integer',
        'last_attempt_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RecordCategory::class, 'category_id');
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(StudentRecordAttempt::class, 'assignment_id');
    }
}
