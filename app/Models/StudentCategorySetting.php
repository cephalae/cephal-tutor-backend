<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentCategorySetting extends Model
{
    protected $fillable = [
        'student_id',
        'category_id',
        'questions_count',
    ];

    protected $casts = [
        'questions_count' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RecordCategory::class, 'category_id');
    }
}
