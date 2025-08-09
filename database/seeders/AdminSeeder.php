<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $username = env('ADMIN_USERNAME', 'admin');
        $password = env('ADMIN_PASSWORD', 'admin5flix');

        // Pastikan username tidak null atau empty
        if (empty($username)) {
            $username = 'admin';
        }

        if (empty($password)) {
            $password = 'admin5flix';
        }

        // Hapus admin lama jika ada
        User::where('username', $username)->delete();

        // Buat admin baru
        User::create([
            'username' => $username,
            'password' => Hash::make($password),
            'role' => 'admin'
        ]);

        $this->command->info("Admin user created: {$username}");
    }
}