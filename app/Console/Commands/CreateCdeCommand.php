<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('safernet:create-cde {email} {--name=County Director of Education} {--password=}')]
#[Description('Create or update the root County Director of Education account')]
class CreateCdeCommand extends Command
{
    public function handle(): int
    {
        $password = $this->option('password') ?: $this->secret('Password (minimum 12 characters)');
        $data = [
            'email' => $this->argument('email'),
            'name' => $this->option('name'),
            'password' => $password,
        ];
        $validator = Validator::make($data, [
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        User::query()->updateOrCreate(
            ['email' => $data['email']],
            [
                'name' => $data['name'],
                'password' => $data['password'],
                'role' => UserRole::Cde,
                'status' => 'active',
                'subcounty_id' => null,
                'institution_id' => null,
            ],
        );

        $this->components->info('CDE account is ready.');

        return self::SUCCESS;
    }
}
