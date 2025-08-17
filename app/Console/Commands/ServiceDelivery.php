<?php

namespace App\Console\Commands;

use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\PaymentHistoryService;
use Illuminate\Console\Command;

class ServiceDelivery extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'service:delivery';

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
        ServiceOrder::where('status', 'delivered')
            ->where('updated_at', '>', now()->addHour(48))
            ->chunk(100, function ($orders) {
                foreach ($orders as $order) {
                    $order->update([
                        'success' => 'success'
                    ]);

                    if ($orders->commission_type == 'flat') {
                        $amount = $orders->commission_amount;
                    } else {
                        $amount =  ($orders->amount / 100) * $orders->commission_amount;
                    }

                    User::find($order->vendor_id)->increment(['balance' => ($order->amount - $amount)]);
                    PaymentHistoryService::store(uniqid(), ($order->amount - $amount), 'My wallet', 'Service sell', '+', '', $order->vendor_id);
                }
            });
    }
}
