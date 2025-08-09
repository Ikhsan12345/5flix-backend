<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $username = env('ADMIN_USERNAME');
        $password = env('ADMIN_PASSWORD');

        // Hapus admin lama
        User::where('username', $username)->delete();

        // Buat admin baru
        User::create([
            'username' => $username,
            'password' => Hash::make($password),
            'role'     => 'admin'
        ]);

    }
}