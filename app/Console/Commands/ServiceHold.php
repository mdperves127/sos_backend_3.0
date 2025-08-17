<?php

namespace App\Console\Commands;

use App\Models\ServiceOrder;
use Illuminate\Console\Command;

class ServiceHold extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'service:hold';

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
        ServiceOrder::where('is_rejected', '1')
        ->where('updated_at', '>', now()->addHour(48))
        ->chunk(100, function ($orders) {
            foreach($orders as $order){
                $order->update([
                    'status' => 'holde'
                ]);
            }

        });

    }
}
