<?php

namespace Database\Seeders;

use App\Models\Subscription;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PlanTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Subscription::query()->whereIn('id',[1,5,9])->update([
            'plan_type'=>'freemium'
        ]);
        Subscription::query()->whereIn('id',[13,17,21])->update([
            'plan_type'=>'freemium'
        ]);



        Subscription::query()->whereIn('id',[2,6,10])->update([
            'plan_type'=>'basic'
        ]);
        Subscription::query()->whereIn('id',[14,18,22])->update([
            'plan_type'=>'basic'
        ]);


        Subscription::query()->whereIn('id',[3,7,11])->update([
            'plan_type'=>'premium'
        ]);
        Subscription::query()->whereIn('id',[15,19,23])->update([
            'plan_type'=>'premium'
        ]);



        Subscription::query()->whereIn('id',[4,8,12])->update([
            'plan_type'=>'vip'
        ]);
        Subscription::query()->whereIn('id',[16,20,24])->update([
            'plan_type'=>'vip'
        ]);


    }
}
