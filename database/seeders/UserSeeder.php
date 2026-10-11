<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name' => 'System Admin',
            'staff_id' => 'ADMIN001',
            'password' => 'Abcd1234', // auto-hashed by the model's 'hashed' cast
            'is_admin' => true,
        ]);

        $staff = [
            ['name' => 'lgairport1', 'staff_id' => 'lgairport1'],
            ['name' => 'lgairport2', 'staff_id' => 'lgairport2'],
            ['name' => 'lgairport3', 'staff_id' => 'lgairport3'],
            ['name' => 'lgairport4', 'staff_id' => 'lgairport4'],
            ['name' => 'lgairport5', 'staff_id' => 'lgairport5'],
            ['name' => 'lgairport6', 'staff_id' => 'lgairport6'],
            ['name' => 'lgairport7', 'staff_id' => 'lgairport7'],
            ['name' => 'lgairport8', 'staff_id' => 'lgairport8'],
            ['name' => 'lgairport9', 'staff_id' => 'lgairport9'],
            ['name' => 'lgairport10', 'staff_id' => 'lgairport10'],
        ];

        foreach ($staff as $person) {
            User::create([
                'name' => $person['name'],
                'staff_id' => $person['staff_id'],
                'password' => 'password123',
                'is_admin' => false,
            ]);
        }
    }
}