<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Notifications\ResetPasswordViaGraph;

class User extends Authenticatable implements JWTSubject
{
    use Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'username',
        'email',
        'mobile',
        'password',
        'provider_id',
        'type',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    // ----- JWTSubject -----
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    // Put user details + roles + permissions into token claims
    public function getJWTCustomClaims(): array
    {
        // avoid huge tokens later; for now OK for MVP
        return [
            'user' => [
                'id' => $this->id,
                'name' => $this->name,
                'username' => $this->username,
                'email' => $this->email,
                'mobile' => $this->mobile,
                'type' => $this->type,
                'provider_id' => $this->provider_id,
            ],
            'rbac' => [
                'roles' => $this->getRoleNames()->values()->all(),
                'permissions' => $this->getAllPermissions()->pluck('name')->values()->all(),
            ],
        ];
    }

    /**
     * IMPORTANT:
     * Spatie uses a "guard_name" for roles/permissions.
     * We'll dynamically select guard based on user type.
     */
    public function getDefaultGuardName(): string
    {
        return $this->type === 'admin' ? 'admin_api' : 'provider_api';
    }



    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordViaGraph($token));
    }


    public function categorySettings(): HasMany
    {
        return $this->hasMany(StudentCategorySetting::class, 'student_id');
    }

    public function recordAssignments(): HasMany
    {
        return $this->hasMany(StudentRecordAssignment::class, 'student_id');
    }

    public function recordAttempts(): HasMany
    {
        return $this->hasMany(StudentRecordAttempt::class, 'student_id');
    }
}
