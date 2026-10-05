<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Domain\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'platform:create-admin {email} {--name=Administrador de plataforma}';

    protected $description = 'Crea un administrador de la plataforma SaaS (la contraseña se pide por consola)';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $password = (string) $this->secret('Contraseña (mínimo 12 caracteres)');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email', 'unique:platform_admins,email'], 'password' => ['required', 'min:12']]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        PlatformAdmin::create([
            'name' => $this->option('name'),
            'email' => $email,
            'password' => $password,
        ]);

        $this->info("Administrador de plataforma creado: {$email}");

        return self::SUCCESS;
    }
}
