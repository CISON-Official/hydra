<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminUser extends Command
{
    // The console command name and signature
    protected $signature = 'make:admin';

    // The console command description
    protected $description = 'Creates a new system administrator user interactivly';

    public function handle()
    {
        $name = $this->ask('Enter Admin Name');
        $email = $this->ask('Enter Admin Email');

        if (User::where('email', $email)->exists()) {
            $user = User::where('email', $email)->first();
            $this->error('A user with this email already exists!');
            $this->warn("User with $user->get('email') found in the database.");
            $this->info("This is your api key" . $user->get('api_key_hash'));
            return Command::SUCCESS;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'is_admin' => true,
            'role' => 'admin'
        ]);

        $user = User::where('email', $email)->first();
        $this->info("Admin user {$email} created successfully!");
        $this->info("This is your api key" . $user->get('api_key_hash'));
        return Command::SUCCESS;
    }
}