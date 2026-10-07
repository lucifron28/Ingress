<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Mobile login via Sanctum bearer token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::where('email', strtolower(trim($validated['email'])))->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $deviceName = $validated['device_name'] ?? 'mobile-app';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Register a new student account.
     */
    public function register(Request $request): JsonResponse
    {
        $normalized = [];
        if ($request->has('name') && is_string($request->input('name'))) {
            $normalized['name'] = trim($request->input('name'));
        }
        if ($request->has('student_id') && is_string($request->input('student_id'))) {
            $normalized['student_id'] = trim($request->input('student_id'));
        }
        if ($request->has('email') && is_string($request->input('email'))) {
            $normalized['email'] = strtolower(trim($request->input('email')));
        }
        if ($normalized !== []) {
            $request->merge($normalized);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'student_id' => ['required', 'string', 'max:64', 'unique:users,student_id'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'student_id' => $validated['student_id'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => User::ROLE_STUDENT,
        ]);

        $deviceName = $validated['device_name'] ?? 'mobile-app';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ], 201);
    }

    /**
     * Current authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    /**
     * Revoke current mobile access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
