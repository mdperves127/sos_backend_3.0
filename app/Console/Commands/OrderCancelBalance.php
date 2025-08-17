<?php

namespace App\Console\Commands;

use App\Models\CancelOrderBalance;
use App\Models\User;
use App\Services\PaymentHistoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class OrderCancelBalance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'order:cancel';

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
        CancelOrderBalance::where('created_at', '<', now()->subHour(12))->chunk(100, function ($datas) {
            foreach ($datas as $data) {
                $trxid = uniqid();
                PaymentHistoryService::store($trxid, $data->amount, 'My wallet', 'Cancel product order', '+', '', $data->user_id);
                User::find($data->user_id)->increment('balance', $data->balance);
                $data->delete();
            }
        });
    }
}
