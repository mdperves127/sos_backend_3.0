<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Notifications\LowStockNotification;

class LowStockNotificationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:low-stock';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Low stock notification successfully send !';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $lowStockProducts = Product::where('qty', '<', 1)
        ->whereHas('product_details', function ($query) {
            $query->where('status', 1);
        })
        ->get();

        return $lowStockProducts;

        foreach ($lowStockProducts as $product) {
            $users = $product->product_details()->with('user')->get()->pluck('user')->unique();

            foreach ($users as $user) {
                $user->notify(new LowStockNotification($product , $user));
            }
        }

        $this->info('Low stock notifications sent successfully.');
    }
}
