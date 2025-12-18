<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Friend extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'friend_id',
        'created_at',
    ];

    public function user()
    {
        return $this->belongsTo(Users::class, 'user_id');
    }

    public function friend()
    {
        return $this->belongsTo(Users::class, 'friend_id');
    }
}
