<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SteamProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'persona_name',
        'profile_url',
        'avatar',
        'avatar_medium',
        'avatar_full',
        'last_sync_at'
    ];

    public function user()
    {
        return $this->belongsTo(Users::class);
    }
}
