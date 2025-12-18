<?php

namespace App\Models;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Users extends Model
{
    use HasApiTokens, HasFactory;

    protected $table = 'users';

    protected $fillable = [
        'steam_id',
        'username',
        'display_name',
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
    ];

    public function steamProfile()
    {
        return $this->hasOne(SteamProfile::class);
    }

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
