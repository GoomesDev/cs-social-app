<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Users;

class UsersController extends Controller
{
    public function getProfile($userId)
    {
        $profile = Users::find($userId);
        
        if (!$profile) {
            return response()->json(['error' => 'Usuário não encontrado'], 404);
        }
        
        return response()->json($profile);
    }
}
