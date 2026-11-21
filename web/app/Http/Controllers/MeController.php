<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MeController extends Controller
{
    /**
     * Профиль / мои выборы (заглушка).
     */
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Show', [
            'profile' => [
                'name' => $user?->name,
                'email' => $user?->email,
                'points' => 0,
            ],
            'picks' => [],
        ]);
    }
}
