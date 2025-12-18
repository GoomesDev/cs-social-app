<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Badges extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'icon',
    ];
}
