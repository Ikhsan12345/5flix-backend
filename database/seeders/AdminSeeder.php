<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Delete existing admin if exists
        User::where('username', 'admin')->delete();

        // Create new admin user
        User::create([
            'username' => 'admin',
            'password' => Hash::make('admin5flix'),
            'role' => 'admin'
        ]);

        echo "Admin user created successfully!\n";
        echo "Username: admin\n";
        echo "Password: admin5flix\n";
    }
}