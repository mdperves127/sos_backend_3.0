<?php

namespace App\Observers;

use App\Models\AdminAdvertise;
use App\Models\DollerRate;
use App\Models\PaymentHistory;
use App\Models\User;
use App\Services\PaymentHistoryService;

class AdminAdvertiseObserver
{
    /**
     * Handle the AdminAdvertise "created" event.
     *
     * @param  \App\Models\AdminAdvertise  $adminAdvertise
     * @return void
     */
    public function created(AdminAdvertise $adminAdvertise)
    {
        if(request('paymethod') == 'my-wallet'){
            // $user = User::find(userid());
            // $dollerRate  =  DollerRate::first()?->amount;


            // $user->decrement('balance',(request('budget_amount') * $dollerRate));
            // $adminAdvertise->update([
            //     'is_paid'=>1
            // ]);

            // PaymentHistoryService::store($adminAdvertise->trxid,($adminAdvertise->budget_amount * $dollerRate),'My Wallet','Advertise','-','',$adminAdvertise->user_id);
        }
    }

    /**
     * Handle the AdminAdvertise "updated" event.
     *
     * @param  \App\Models\AdminAdvertise  $adminAdvertise
     * @return void
     */
    public function updated(AdminAdvertise $adminAdvertise)
    {
        //
    }

    /**
     * Handle the AdminAdvertise "deleted" event.
     *
     * @param  \App\Models\AdminAdvertise  $adminAdvertise
     * @return void
     */
    public function deleted(AdminAdvertise $adminAdvertise)
    {
        //
    }

    /**
     * Handle the AdminAdvertise "restored" event.
     *
     * @param  \App\Models\AdminAdvertise  $adminAdvertise
     * @return void
     */
    public function restored(AdminAdvertise $adminAdvertise)
    {
        //
    }

    /**
     * Handle the AdminAdvertise "force deleted" event.
     *
     * @param  \App\Models\AdminAdvertise  $adminAdvertise
     * @return void
     */
    public function forceDeleted(AdminAdvertise $adminAdvertise)
    {
        //
    }
}
