<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Client;
use App\Models\Referral;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // Public sign-up is for clients only. Cleaners and admins are created by admin.
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            // the role is set here, never taken from the request
            $user = User::create([
                'name' => $request->input('name'),
                'phone' => $request->input('phone'),
                'email' => $request->input('email'),
                'password' => $request->input('password'),
                'role' => 'client',
                'status' => 'active',
            ]);

            $referrer = $request->filled('referral_code')
                ? Client::where('referral_code', $request->input('referral_code'))->first()
                : null;

            $client = $user->client()->create([
                'referred_by_client_id' => $referrer?->id,
                'preferred_contact' => $request->input('preferred_contact', 'whatsapp'),
                'marketing_opt_in' => $request->boolean('marketing_opt_in'),
            ]);

            if ($referrer) {
                Referral::create([
                    'referrer_client_id' => $referrer->id,
                    'referred_client_id' => $client->id,
                ]);
            }

            return $user;
        });

        return response()->json([
            'token' => $user->createToken($request->input('device_name', 'web'))->plainTextToken,
            'user' => new UserResource($user->load(['client', 'cleaner'])),
        ], 201);
    }

    // identifier = phone (any common format) or email
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $identifier = trim($data['identifier']);

        $user = str_contains($identifier, '@')
            ? User::where('email', strtolower($identifier))->first()
            : User::where('phone', Phone::normalize($identifier) ?? $identifier)->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => ['These details do not match our records.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'identifier' => ['This account is suspended. Please contact us.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $user->createToken($data['device_name'] ?? 'web')->plainTextToken,
            'user' => new UserResource($user->load(['client', 'cleaner'])),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['client', 'cleaner']));
    }
}