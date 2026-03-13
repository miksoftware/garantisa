<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index()
    {
        $users = User::orderBy('name')->get();
        return view('users.index', compact('users'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => ['required', 'min:6'],
        ]);

        User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => $request->password, // El cast 'hashed' del modelo lo encripta
        ]);

        return redirect('/usuarios')->with('success', 'Usuario creado correctamente.');
    }

    public function destroy(User $user)
    {
        if (User::count() <= 1) {
            return redirect('/usuarios')->with('error', 'No puedes eliminar el último usuario.');
        }

        $user->delete();
        return redirect('/usuarios')->with('success', 'Usuario eliminado.');
    }
}
