<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\CloudOperatorDatabase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Throwable;

#[Signature('ingress:provision-staging-account {role} {--confirm-staging}')]
#[Description('Create a synthetic staging organizer, staff member, or student on the cloud database.')]
class ProvisionStagingAccount extends Command
{
    public function handle(CloudOperatorDatabase $cloudDatabase): int
    {
        if (! $this->option('confirm-staging')) {
            $this->error('Pass --confirm-staging to create a synthetic staging account.');

            return self::FAILURE;
        }

        $role = $this->argument('role');
        $roles = [
            'organizer' => User::ROLE_ORGANIZER,
            'staff' => User::ROLE_STAFF,
            'student' => User::ROLE_STUDENT,
        ];
        if (! is_string($role) || ! array_key_exists($role, $roles)) {
            $this->error('Choose organizer, staff, or student. Administrator accounts use ingress:create-admin.');

            return self::FAILURE;
        }

        $errors = $cloudDatabase->configurationErrors();
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            $connection = $cloudDatabase->connection();
        } catch (Throwable) {
            $this->error('Could not verify the cloud PostgreSQL 18 database and runtime identity. Confirm Render startup completed.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Staging account name'));
        $email = strtolower(trim((string) $this->ask('Synthetic email ending in @example.test')));
        $studentId = $roles[$role] === User::ROLE_STUDENT
            ? strtoupper(trim((string) $this->ask('Synthetic student ID beginning with STAGE-')))
            : null;
        $password = $this->secret('Password, at least 12 characters');
        $confirmation = $this->secret('Confirm password');

        if ($name === '' || mb_strlen($name) > 100
            || strlen($email) > 255
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! str_ends_with($email, '@example.test')
            || ($studentId !== null && ! preg_match('/\ASTAGE-[A-Z0-9-]{1,58}\z/', $studentId))
            || ! is_string($password) || strlen($password) < 12 || strlen($password) > 72
            || ! is_string($confirmation) || ! hash_equals($password, $confirmation)) {
            $this->error('Use a valid name, an @example.test email, a STAGE- student ID when needed, and matching passwords between 12 and 72 bytes.');

            return self::FAILURE;
        }

        $created = null;
        try {
            $locked = $cloudDatabase->runLocked($connection, function () use (
                $connection,
                &$created,
                $email,
                $name,
                $password,
                $role,
                $roles,
                $studentId,
            ): void {
                $connection->transaction(function () use (
                    $connection,
                    &$created,
                    $email,
                    $name,
                    $password,
                    $role,
                    $roles,
                    $studentId,
                ): void {
                    $existing = User::on($connection->getName())
                        ->whereRaw('LOWER(email) = ?', [$email])
                        ->first();

                    if ($existing) {
                        if ($existing->role !== $roles[$role]) {
                            throw new \RuntimeException('Synthetic email is assigned to a different role.');
                        }

                        $created = false;

                        return;
                    }

                    if ($studentId !== null && User::on($connection->getName())
                        ->whereRaw('LOWER(student_id) = ?', [strtolower($studentId)])
                        ->exists()) {
                        throw new \RuntimeException('Synthetic student ID is already assigned.');
                    }

                    $user = new User;
                    $user->setConnection($connection->getName());
                    $user->name = $name;
                    $user->email = $email;
                    $user->role = $roles[$role];
                    $user->student_id = $studentId;
                    $user->email_verified_at = null;
                    $user->password = Hash::make($password);
                    $user->save();
                    $created = true;
                });
            });
        } catch (Throwable) {
            $this->error('Staging account creation failed. Check the cloud database state and retry.');

            return self::FAILURE;
        }

        if (! $locked) {
            $this->error('The cloud database is busy with a deployment migration. Retry after startup completes.');

            return self::FAILURE;
        }

        if ($created === false) {
            $this->info('That synthetic account already exists with the same role; no changes were made.');

            return self::SUCCESS;
        }

        $this->info('Synthetic staging '.$role.' account created.');

        return self::SUCCESS;
    }
}
