<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Users extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'users';

    protected $fillable = [
        'steam_id',
        'username',
        'profile_url',
        'display_name',
        'last_sync_at',
        'friends_synced_at',
        'avatar',
        'is_active',
    ];

    protected $hidden = [
        'email',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_sync_at' => 'datetime',
        'friends_synced_at' => 'datetime',
    ];

    public function friends()
    {
        return $this->belongsToMany(
            Users::class,
            'friends',
            'user_id',
            'friend_id'
        );
    }

    public function addedMe()
    {
        return $this->belongsToMany(
            Users::class,
            'friends',
            'friend_id',
            'user_id'
        );
    }
}
