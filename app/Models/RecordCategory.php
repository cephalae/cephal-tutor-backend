<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecordCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    public function medicalRecords(): HasMany
    {
        return $this->hasMany(MedicalRecord::class, 'category_id');
    }
}
