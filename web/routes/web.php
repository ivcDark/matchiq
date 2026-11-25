<?php

use App\Http\Controllers\ClubController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\UserProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MatchController::class, 'index'])->name('matches.index');
Route::get('/matches/{match}', [MatchController::class, 'show'])->name('matches.show');
Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard');
Route::get('/tournaments/{id}', [TournamentController::class, 'show'])->name('tournaments.show')->whereNumber('id');
Route::get('/clubs/{id}', [ClubController::class, 'show'])->name('clubs.show')->whereNumber('id');
Route::get('/users/{id}', [UserProfileController::class, 'show'])->name('users.show')->whereNumber('id');

Route::middleware('auth')->group(function () {
    Route::get('/me', [MeController::class, 'show'])->name('me');

    // Breeze profile edit (настройки аккаунта; MVP-профиль смотрим на /me)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
