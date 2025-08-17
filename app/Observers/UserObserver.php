<?php

namespace App\Observers;

use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;

class UserObserver
{
    /**
     * Handle the User "created" event.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function created(User $user)
    {
        // if($user->role_as == 2){
        //     $vendorsubscription = Subscription::find(1);
        //     SubscriptionService::store($vendorsubscription,$user);
        // }
        // if($user->role_as == 3){
        //     $affiliatesubscription = Subscription::find(13);
        //     SubscriptionService::store($affiliatesubscription,$user);
        // }
    }

    /**
     * Handle the User "updated" event.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function updated(User $user)
    {
        //
    }

    /**
     * Handle the User "deleted" event.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function deleted(User $user)
    {
        //
    }

    /**
     * Handle the User "restored" event.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function restored(User $user)
    {
        //
    }

    /**
     * Handle the User "force deleted" event.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function forceDeleted(User $user)
    {
        //
    }
}
