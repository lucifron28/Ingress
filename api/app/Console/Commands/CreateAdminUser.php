<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PostgresAdvisoryMigrationLock;
use App\Support\RenderDeploymentConfiguration;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

#[Signature('ingress:create-admin')]
class CreateAdminUser extends Command
{
    protected $description = 'Create an administrator on the deployed Ingress cloud database.';

    public function handle(PostgresAdvisoryMigrationLock $migrationLock): int
    {
        $errors = [
            ...RenderDeploymentConfiguration::runtimeErrors(
                config('app'),
                config('ingress'),
                app()->environment(),
            ),
            ...RenderDeploymentConfiguration::databaseErrors(config('database')),
        ];

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            $connection = DB::connection('pgsql');
            $version = (int) $connection->scalar("select current_setting('server_version_num')");
            if (! RenderDeploymentConfiguration::supportsPostgres18($version)) {
                $this->error('PostgreSQL 18 is required.');

                return self::FAILURE;
            }

            $identity = $connection->table('runtime_identity')->where('id', 1)->first();
            if (! $identity || $identity->mode !== 'cloud' || $identity->identity !== 'cloud') {
                $this->error('The database is not initialized for the Ingress cloud runtime.');

                return self::FAILURE;
            }
        } catch (Throwable) {
            $this->error('Could not verify the deployed cloud database. Confirm that Render startup completed.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Administrator name'));
        $email = strtolower(trim((string) $this->ask('Administrator email')));
        $password = $this->secret('Administrator password, at least 12 characters');
        $confirmation = $this->secret('Confirm administrator password');

        if ($name === '' || mb_strlen($name) > 100
            || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! is_string($password) || strlen($password) < 12 || strlen($password) > 72
            || ! is_string($confirmation) || ! hash_equals($password, $confirmation)) {
            $this->error('Enter a valid name and email, and matching passwords between 12 and 72 bytes.');

            return self::FAILURE;
        }

        try {
            $created = $migrationLock->run($connection, function () use ($name, $email, $password): void {
                if (User::on('pgsql')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                    throw new \RuntimeException('The administrator email is already in use.');
                }

                $user = new User;
                $user->setConnection('pgsql');
                $user->name = $name;
                $user->email = $email;
                $user->role = User::ROLE_ADMIN;
                $user->email_verified_at = now();
                $user->password = Hash::make($password);
                $user->save();
            }, 30);
        } catch (Throwable) {
            $this->error('Administrator creation failed. Check the deployment logs and database state.');

            return self::FAILURE;
        }

        if (! $created) {
            $this->error('The database is busy with a deployment migration. Retry after the deployment finishes.');

            return self::FAILURE;
        }

        $this->info('Administrator account created.');

        return self::SUCCESS;
    }
}
