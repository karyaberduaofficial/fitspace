<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'google_id',
        'auth_provider',
        'avatar_url',
        'phone',
        'birth_date',
        'password',
        'role',
        'branch_id',
        'is_active',
        'is_guest',
        'approval_status',
        'approved_by',
        'approved_at',
        'approval_notes',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'google_id',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'birth_date' => 'date',
            'approved_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_guest' => 'boolean',
        ];
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isGuestAccount(): bool
    {
        return (bool) $this->is_guest || $this->role === 'guest';
    }

    public function isApproved(): bool
    {
        if ($this->role === 'admin') {
            return true;
        }

        return ($this->approval_status ?? 'approved') === 'approved';
    }

    public function age(): ?int
    {
        if (! $this->birth_date) {
            return null;
        }

        return Carbon::parse($this->birth_date)->age;
    }
}
