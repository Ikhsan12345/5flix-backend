<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $request->validate([
                'username' => 'required|string',
                'password' => 'required|string'
            ]);

            $user = User::where('username', $request->username)->first();

            if ($user && Hash::check($request->password, $user->password)) {
                // Delete existing tokens
                $user->tokens()->delete();

                $token = $user->createToken('')->plainTextToken;

                return response()->json([
                    'success' => true,
                    'message' => 'Login berhasil',
                    'user' => [
                        'id' => $user->id,
                        'username' => $user->username,
                        'role' => $user->role
                    ],
                    'token' => $token
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Username atau password salah'
            ], 401);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error'
            ], 500);
        }
    }

    public function register(Request $request)
    {
        try {
            $request->validate([
                'username' => 'required|string|max:50|unique:users,username',
                'password' => 'required|string|min:6',
            ]);

            $user = User::create([
                'username' => $request->username,
                'password' => Hash::make($request->password),
                'role' => 'user'
            ]);

            $token = $user->createToken('')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Registrasi berhasil',
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'role' => $user->role
                ],
                'token' => $token
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error'
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout berhasil'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error'
            ], 500);
        }
    }
}