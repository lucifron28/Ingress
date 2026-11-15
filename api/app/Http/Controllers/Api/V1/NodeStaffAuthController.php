<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\NodeStaffAccess;
use App\Actions\NodeStaffPairing;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NodeStaffAuthController extends Controller
{
    public function pair(Request $request, NodeStaffPairing $pairing): JsonResponse
    {
        if (! $request->secure() && ! $this->allowsLanDemoHttp()) {
            abort(403, 'HTTPS is required.');
        }
        $data = $request->validate([
            'node_id' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_-]{1,64}\z/'],
            'token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);
        $session = $pairing->redeem($data['token'], $data['node_id'], $data['device_name'] ?? 'node-staff');

        return response()->json(['token' => $session['token'], 'token_type' => 'Bearer',
            'user' => new UserResource($session['user']), 'node_staff' => true, 'node_id' => config('ingress.node_id')])
            ->header('Cache-Control', 'no-store, private');
    }

    public function login(Request $request, NodeStaffAccess $access): JsonResponse
    {
        if (! $request->secure() && ! $this->allowsLanDemoHttp()) {
            abort(403, 'HTTPS is required.');
        }
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);
        $email = Str::lower(trim($data['email']));
        $users = User::whereRaw('LOWER(email) = ?', [$email])->limit(2)->get();
        $user = $users->count() === 1 ? $users->first() : null;
        $credential = $user ? DB::table('node_staff_credentials')->where('user_id', $user->id)->first() : null;
        $matches = $credential ? Hash::check($data['password'], $credential->password_hash) : false;
        if (! $credential) {
            Hash::make($data['password']);
        }
        if (! $user || ! $credential || ! $matches) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }
        $token = DB::transaction(function () use ($user, $credential, $data, $access) {
            DB::table('runtime_identity')->where('id', 1)->lockForUpdate()->first();
            $current = DB::table('node_staff_credentials')->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $current || ! hash_equals($current->password_hash, $credential->password_hash) || ! $access->assigned($user->id)) {
                throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
            }

            return $user->createToken($data['device_name'] ?? 'node-staff', ['node:staff'], now()->addHours(12))->plainTextToken;
        });

        return response()->json(['token' => $token, 'token_type' => 'Bearer', 'user' => new UserResource($user), 'node_staff' => true])
            ->header('Cache-Control', 'no-store, private');
    }

    private function allowsLanDemoHttp(): bool
    {
        return app()->environment(['local', 'testing'])
            || (config('ingress.mode') === 'node' && config('ingress.lan_demo') === true);
    }
}
