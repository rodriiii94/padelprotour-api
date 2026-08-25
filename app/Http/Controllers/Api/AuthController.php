<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

// Autenticación por token para clientes "de terceros" (móvil + web vía
// Expo), no autenticación de SPA por cookie -- por eso no usamos el guard
// "web"/sesión de Sanctum, solo emisión y verificación de tokens.
class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'device_name' => ['required', 'string'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'user' => $user,
            'token' => $user->createToken($validated['device_name'])->plainTextToken,
        ], 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string'], // p.ej. "iphone-de-marta", "web-chrome"
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son correctas.'],
            ]);
        }

        // Un token por dispositivo: revocamos cualquier token previo con el
        // mismo nombre de dispositivo para no acumular tokens huérfanos si
        // el usuario vuelve a iniciar sesión desde el mismo móvil/navegador.
        $user->tokens()->where('name', $validated['device_name'])->delete();

        return response()->json([
            'user' => $user,
            'token' => $user->createToken($validated['device_name'])->plainTextToken,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function update(Request $request)
    {
        // Email y password se quedan fuera a propósito: cambiarlos aquí sin
        // reautenticación (contraseña actual, verificación de email...)
        // abriría una vía fácil de secuestro de cuenta.
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'level' => ['sometimes', 'nullable', 'string', 'max:255'],
            'club' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $request->user()->update($validated);

        return $request->user();
    }
}
