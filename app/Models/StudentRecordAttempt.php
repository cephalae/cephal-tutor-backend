<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentRecordAttempt extends Model
{
    protected $fillable = [
        'student_id',
        'medical_record_id',
        'assignment_id',
        'attempt_no',
        'submitted_codes',
        'is_correct',
        'wrong_codes',
        'missing_codes',
    ];

    protected $casts = [
        'attempt_no' => 'integer',
        'submitted_codes' => 'array',
        'wrong_codes' => 'array',
        'missing_codes' => 'array',
        'is_correct' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(StudentRecordAssignment::class, 'assignment_id');
    }
}
