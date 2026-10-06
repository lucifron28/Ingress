<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ConfirmStudentActivation;
use App\Actions\IssueStudentActivationChallenge;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\StudentIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StudentActivationController extends Controller
{
    /**
     * Request an activation challenge for a student identity.
     */
    public function requestChallenge(
        Request $request,
        IssueStudentActivationChallenge $issuer
    ): JsonResponse {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $identity = StudentIdentity::findByIdentifier($validated['identifier']);

        if (! $identity) {
            return response()->json([
                'status' => 'identity_not_found',
                'message' => 'Student record not found in institutional directory.',
                'detail' => 'Please verify your student number or institutional email. Contact your college administration if you are newly enrolled.',
            ], 404);
        }

        if ($identity->isActivated()) {
            return response()->json([
                'status' => 'already_activated',
                'message' => 'This student account has already been activated.',
                'detail' => 'Please sign in using your institutional email and password.',
            ], 409);
        }

        $result = $issuer->execute(
            $identity,
            $request->ip(),
            $request->userAgent()
        );

        $payload = [
            'message' => 'Activation challenge sent to your institutional email.',
            'student_identity' => [
                'student_id' => $identity->student_id,
                'name' => $identity->name,
                'masked_email' => $result['masked_email'],
                'college' => $identity->college,
            ],
            'expires_at' => $result['expires_at']->toISOString(),
        ];

        // Provide demo code in demo mode or local/testing environments for rapid testing and demonstrations
        if (config('app.demo_mode') || app()->environment('local', 'testing')) {
            $payload['demo_code'] = $result['code'];
        }

        return response()->json($payload);
    }

    /**
     * Confirm activation challenge and activate student user account.
     */
    public function confirmActivation(
        Request $request,
        ConfirmStudentActivation $confirmer
    ): JsonResponse {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $confirmer->execute(
                $validated['identifier'],
                $validated['code'],
                $validated['password'],
                $validated['device_name'] ?? null,
                $request->ip()
            );
        } catch (ValidationException $e) {
            throw $e;
        }

        return response()->json([
            'message' => 'Student account activated successfully.',
            'token' => $result['token'],
            'token_type' => $result['token_type'],
            'user' => new UserResource($result['user']),
            'student_identity' => [
                'student_id' => $result['identity']->student_id,
                'name' => $result['identity']->name,
                'institutional_email' => $result['identity']->institutional_email,
                'college' => $result['identity']->college,
            ],
        ], 201);
    }

    /**
     * Resend an activation challenge.
     */
    public function resendChallenge(
        Request $request,
        IssueStudentActivationChallenge $issuer
    ): JsonResponse {
        return $this->requestChallenge($request, $issuer);
    }
}
