<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
       $user = new User();
       $user->name = 'admin';
       $user->email = 'admin@gmail.com';
       $user->password = bcrypt('password');
       $user->role_as = 1;
       $user->status = 'active';
       $user->save();

       $user = new User();
       $user->name = 'vendor';
       $user->email = 'vendor@gmail.com';
       $user->password = bcrypt('password');
       $user->role_as = 2;
       $user->status = 'active';
       $user->save();

       $user = new User();
       $user->name = 'affiliate';
       $user->email = 'affiliate@gmail.com';
       $user->password = bcrypt('password');
       $user->role_as = 3;
       $user->status = 'active';
       $user->save();


       $user = new User();
       $user->name = 'User';
       $user->email = 'user@gmail.com';
       $user->password = bcrypt('password');
       $user->role_as = 4;
       $user->status = 'active';
       $user->save();

    }
}
