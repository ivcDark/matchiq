<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(
            [
                'name' => 'required|string|max:40|unique:'.User::class,
                'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
                'password' => ['required', 'confirmed', Rules\Password::defaults()],
            ],
            [
                'name.required' => 'Укажите ник для рейтинга.',
                'name.unique' => 'Такой ник уже занят.',
                'email.required' => 'Укажите email.',
                'email.email' => 'Укажите корректный email.',
                'email.unique' => 'Этот email уже зарегистрирован.',
                'password.required' => 'Придумайте пароль.',
                'password.confirmed' => 'Пароли не совпадают.',
            ],
            [
                'name' => 'ник',
                'email' => 'email',
                'password' => 'пароль',
            ],
        );

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password, // cast `hashed` на User
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('matches.index', absolute: false));
    }
}
