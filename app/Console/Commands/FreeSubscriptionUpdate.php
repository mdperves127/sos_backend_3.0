<?php

namespace App\Console\Commands;

use App\Models\UserSubscription;
use Illuminate\Console\Command;

class FreeSubscriptionUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'freesubscription:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        UserSubscription::query()
        ->where('subscription_price', 0)
        ->whereDate('expire_date', '<', now()->addDay(12))
        ->chunk(300, function ($subscriptions) {
            foreach ($subscriptions as $subscription) {
                $subscription->update([
                    'expire_date' => now()->addMonth(2)
                ]);
            }
        });

    }
}
