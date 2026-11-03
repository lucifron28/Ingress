<?php

namespace App\Http\Controllers;

use App\Mail\StudentVerificationMail;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class StudentEmailVerificationController extends Controller
{
    private function requireSecureDeployment(Request $request): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $url = parse_url((string) config('app.url'));
            try {
                $mailer = (new ConfigurationUrlParser)->parseConfiguration(config('mail.mailers.'.config('mail.default')) ?? []);
            } catch (Throwable) {
                abort(503);
            }
            $mailDriver = $mailer['transport'] ?? null;
            $smtpScheme = $mailer['scheme'] ?? (($mailer['port'] ?? null) == 465 ? 'smtps' : 'smtp');
            abort_unless($request->secure() && is_array($url) && ($url['scheme'] ?? null) === 'https'
                && ! empty($url['host']) && ! isset($url['query']) && ! isset($url['fragment'])
                && in_array($mailDriver, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend'], true)
                && ($mailDriver !== 'smtp' || $smtpScheme === 'smtps'), 503);
        }
    }

    private function page(string $view, array $data = [], int $status = 200): Response
    {
        return response()->view($view, $data, $status)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }

    public function requestLink(Request $request): JsonResponse
    {
        $this->requireSecureDeployment($request);
        $input = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255']]);
        $email = Str::lower(trim($input['email']));
        if (! preg_match('/\A[^@\s]+@student\.mseuf\.edu\.ph\z/', $email)) {
            return response()->json(['message' => 'Enter your @student.mseuf.edu.ph email address.'], 422);
        }
        $token = bin2hex(random_bytes(32));
        $send = DB::transaction(function () use ($email, $token) {
            DB::table('student_email_verifications')->insertOrIgnore([
                'email' => $email, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('student_email_verifications')->where('email', $email)->lockForUpdate()->first();
            if (! $row) {
                return false;
            }
            if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                DB::table('student_email_verifications')->where('email', $email)->delete();

                return false;
            }
            DB::table('student_email_verifications')->where('email', $email)->update([
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30), 'updated_at' => now(),
            ]);

            return true;
        });
        if ($send) {
            try {
                $url = rtrim((string) config('app.url'), '/').'/student/complete#'.$token;
                Mail::to($email)->send(new StudentVerificationMail($url));
            } catch (Throwable) {
                Log::error('Student verification email delivery failed.');
            }
        }

        return response()->json(['message' => 'If the address is eligible, check your inbox for a verification link.'], 202);
    }

    public function show(Request $request): Response
    {
        $this->requireSecureDeployment($request);

        return $this->page('student.complete');
    }

    public function complete(Request $request): Response
    {
        $this->requireSecureDeployment($request);
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'name' => ['required', 'string', 'max:100'],
            'student_id' => ['required', 'string', 'regex:/\A[A-Za-z0-9-]{1,64}\z/'],
            'password' => ['required', 'string', 'min:12', 'confirmed', function ($attribute, $value, $fail) {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('The password must not exceed 72 bytes.');
                }
            }],
        ]);
        if ($validator->fails()) {
            return $this->page('student.complete', ['errors' => $validator->errors(), 'token' => is_string($request->input('token')) ? substr($request->input('token'), 0, 64) : ''], 422);
        }
        $data = $validator->validated();
        try {
            $completed = DB::transaction(function () use ($data) {
                $record = DB::table('student_email_verifications')->where('token_hash', hash('sha256', $data['token']))->lockForUpdate()->first();
                if (! $record || now()->gte($record->expires_at)
                    || User::whereRaw('LOWER(email) = ?', [$record->email])->exists()
                    || User::whereRaw('LOWER(student_id) = ?', [Str::lower($data['student_id'])])->exists()) {
                    return false;
                }
                $user = new User;
                $user->name = trim($data['name']);
                $user->email = $record->email;
                $user->student_id = Str::upper($data['student_id']);
                $user->role = User::ROLE_STUDENT;
                $user->password = Hash::make($data['password']);
                $user->email_verified_at = now();
                $user->save();
                DB::table('student_email_verifications')->where('email', $record->email)->delete();

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            $completed = false;
        }
        if (! $completed) {
            return $this->page('student.complete', ['error' => 'The link is invalid or expired, or the account details are already in use. Request a new link or contact support.'], 422);
        }

        return $this->page('student.completed');
    }
}
