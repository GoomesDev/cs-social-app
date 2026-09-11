<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    use HasFactory;

    public const ICONS = [
        'target',
        'crosshair',
        'shield',
        'skull',
        'trophy',
        'flame',
        'crown',
        'swords',
        'users',
        'bomb',
        'zap',
        'award',
    ];

    protected $fillable = ['owner_id', 'name', 'icon'];

    public function owner()
    {
        return $this->belongsTo(Users::class, 'owner_id');
    }

    public function members()
    {
        return $this->belongsToMany(Users::class, 'group_members', 'group_id', 'user_id')
            ->withPivot('created_at');
    }
}
