<?php

namespace App\Console\Commands;

use App\Actions\BindRuntimeDatabase;
use App\Models\NodeOperator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('ingress:node-operator:create')]
#[Description('Create the initial browser operator account for this Event Node.')]
class CreateNodeOperator extends Command
{
    public function handle(BindRuntimeDatabase $runtime): int
    {
        if (config('ingress.mode') !== 'node') {
            $this->error('Operator accounts can only be created on an Event Node.');

            return self::FAILURE;
        }

        try {
            $runtime->execute();
        } catch (Throwable) {
            $this->error('Could not verify this Event Node database. Check its runtime configuration and database state.');

            return self::FAILURE;
        }

        if (NodeOperator::query()->exists()) {
            $this->error('An Event Node operator is already provisioned.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Operator name'));
        $email = mb_strtolower(trim((string) $this->ask('Operator email')));
        $password = $this->secret('Password, at least 12 characters');
        $confirmation = $this->secret('Confirm password');

        if ($name === '' || mb_strlen($name) > 100
            || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! is_string($password) || strlen($password) < 12 || strlen($password) > 72
            || ! is_string($confirmation) || ! hash_equals($password, $confirmation)) {
            $this->error('Enter a valid name and email, and matching passwords between 12 and 72 bytes.');

            return self::FAILURE;
        }

        try {
            $created = DB::transaction(function () use ($name, $email, $password): bool {
                $identity = DB::table('runtime_identity')->where('id', 1)->lockForUpdate()->first();
                if (! $identity || $identity->mode !== 'node' || $identity->identity !== config('ingress.node_id')) {
                    throw new \RuntimeException('The database is not bound to this Event Node.');
                }

                if (NodeOperator::query()->exists()) {
                    return false;
                }

                NodeOperator::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'is_active' => true,
                ]);

                return true;
            });
        } catch (Throwable) {
            $this->error('Operator account creation failed. Check the node database and retry.');

            return self::FAILURE;
        }

        if (! $created) {
            $this->error('An Event Node operator is already provisioned.');

            return self::FAILURE;
        }

        $this->info('Event Node operator account created.');

        return self::SUCCESS;
    }
}
