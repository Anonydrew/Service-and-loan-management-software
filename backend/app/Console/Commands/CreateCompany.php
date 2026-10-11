<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CompanyProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateCompany extends Command
{
    protected $signature = 'app:create-company {name : Company name} {--admin-name=} {--admin-email=}';

    protected $description = 'Provision a new company with a head office, default roles, chart of accounts and an administrator';

    public function handle(CompanyProvisioner $provisioner): int
    {
        $adminName = $this->option('admin-name') ?: $this->ask('Administrator name');
        $adminEmail = $this->option('admin-email') ?: $this->ask('Administrator email');
        $password = $this->secret('Administrator password (min 10 chars, mixed case, a number)');

        $validator = Validator::make(
            ['email' => $adminEmail, 'password' => $password],
            ['email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', Password::min(10)->mixedCase()->numbers()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $result = $provisioner->provision($this->argument('name'), $adminName, $adminEmail, $password);
        $this->info("Created company #{$result['company']->id} ({$result['company']->name}). Sign in as {$result['admin']->email}.");

        return self::SUCCESS;
    }
}
