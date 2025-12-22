<?php

namespace App\Services\Admin;

use App\Enums\Status;
use App\Models\AdminAdvertise;
use App\Models\AdvertiseAudienceFile;
use App\Models\DollerRate;
use App\Models\LocationFile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AamarPayService;
use App\Services\PaymentHistoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Class AdminAdvertiseService.
 */
class AdminAdvertiseService {
    public static function create( $validatData ) {
        // return $validatData;
        $trxid                                = uniqid();
        $adminadvaertise                      = new AdminAdvertise();
        $adminadvaertise->setConnection('mysql');
        $adminadvaertise->trxid               = $trxid;
        $adminadvaertise->campaign_objective  = $validatData['campaign_objective'];
        $adminadvaertise->user_id             = userid() ?? 0;
        $adminadvaertise->campaign_name       = $validatData['campaign_name'];
        $adminadvaertise->conversion_location = $validatData['conversion_location'];
        $adminadvaertise->performance_goal    = $validatData['performance_goal'];
        $adminadvaertise->budget_amount       = $validatData['budget_amount'];
        $adminadvaertise->start_date          = $validatData['start_date'];
        $adminadvaertise->end_date            = $validatData['end_date'];
        $adminadvaertise->age                 = $validatData['age'];
        $adminadvaertise->ageto               = $validatData['ageto'];
        $adminadvaertise->gender              = $validatData['gender'];
        $adminadvaertise->detail_targeting    = $validatData['detail_targeting'];
        $adminadvaertise->country             = $validatData['country'];
        $adminadvaertise->city                = $validatData['city'];
        $adminadvaertise->device              = $validatData['device'];
        $adminadvaertise->platform            = $validatData['platform'];
        $adminadvaertise->inventory           = $validatData['inventory'];
        $adminadvaertise->format              = $validatData['format'];
        $adminadvaertise->ad_creative         = $validatData['ad_creative'];
        $adminadvaertise->budget              = $validatData['budget'];
        $adminadvaertise->placements          = $validatData['placements'];

        $start_date = Carbon::parse( $validatData['start_date'] );
        $end_date   = Carbon::parse( $validatData['end_date'] );

        $interval   = $start_date->diffInDays( $end_date );
        $total_days = $interval + 1;

        $adminadvaertise->destination      = request( 'destination' );
        $adminadvaertise->tracking         = request( 'tracking' );
        $adminadvaertise->url_perimeter    = request( 'url_perimeter' );
        $adminadvaertise->number           = request( 'number' );
        $adminadvaertise->last_description = request( 'last_description' );
        $adminadvaertise->status           = Status::Pending->value;
        $adminadvaertise->audience         = request( 'audience' );
        $adminadvaertise->unique_id        = uniqid();
        $adminadvaertise->tenant_id        = tenant()->id ?? 0;

        $adminadvaertise->save();

        if ( request()->hasFile( 'advertise_audience_files' ) ) {
            foreach ( request( 'advertise_audience_files' ) as $file ) {
                $data                                = uploadany_file( $file, 'uploads/advertise_audience_files/' );
                $AdvertiseAudienceFile               = new AdvertiseAudienceFile();
                $AdvertiseAudienceFile->setConnection('mysql');
                $AdvertiseAudienceFile->advertise_id = $adminadvaertise->id;
                $AdvertiseAudienceFile->file         = $data;
                $AdvertiseAudienceFile->save();
            }
        }

        if ( request()->hasFile( 'location_files' ) ) {
            foreach ( request( 'location_files' ) as $file ) {
                $data                    = uploadany_file( $file, 'uploads/location_files/' );
                $locatFile               = new LocationFile();
                $locatFile->setConnection('mysql');
                $locatFile->advertise_id = $adminadvaertise->id;
                $locatFile->file         = $data;
                $locatFile->save();
            }
        }

        $dollerRate = DollerRate::on('mysql')->first()?->amount;

        $totalprice = ( $validatData['budget_amount'] * $dollerRate ) * $total_days;

        // $settings = Settings::select( 'extra_charge_status', 'extra_charge' )->first(); //For extra charge

        //For Extra charge
        // if ( $settings->extra_charge_status == "on" ) {
        //     $charge     = extraCharge( $totalprice, $settings->extra_charge );
        //     $totalprice = $totalprice + $charge;
        // } else {
        //     $totalprice = $totalprice;
        // }

        if ( request( 'paymethod' ) == 'my-wallet' ) {

            $user = null;
            // if (request('user_type') == 'user') {
            if (auth()->check()) {
                $user = User::on('mysql')->where('id', auth()->user()->id )->first();
            } else {
                $user = Tenant::on('mysql')->where('id', tenant()->id ?? '' )->first();
            }

            // Check if user exists
            if ( !$user ) {
                return responsejson( 'User not found!', 'fail' );
            }

            // Validate balance
            if ( $user->balance < $totalprice ) {
                return responsejson( 'Insufficient balance!', 'fail' );
            }

            $user->decrement( 'balance', $totalprice );
            $adminadvaertise->is_paid = 1;
            $adminadvaertise->save();
            PaymentHistoryService::store( $trxid, $totalprice, 'My wallet', 'Advertise', '-', '', $user->id );
            return responsejson( 'Successfull!' );
        } else {

            $successurl = url( 'api/user/aaparpay/advertise-success' );
            return AamarPayService::gateway( $totalprice, $trxid, 'advertise', $successurl, 'user' );
        }
    }

    static function update( $validatData, $id ) {
        $adminadvaertise                      = AdminAdvertise::on('mysql')->find( $id );
        $adminadvaertise->campaign_objective  = $validatData['campaign_objective'];
        $adminadvaertise->campaign_name       = $validatData['campaign_name'];
        $adminadvaertise->conversion_location = $validatData['conversion_location'];
        $adminadvaertise->performance_goal    = $validatData['performance_goal'];
        $adminadvaertise->budget_amount       = $validatData['budget_amount'];
        $adminadvaertise->start_date          = $validatData['start_date'];
        $adminadvaertise->end_date            = $validatData['end_date'];
        $adminadvaertise->age                 = $validatData['age'];
        $adminadvaertise->ageto               = $validatData['ageto'];
        $adminadvaertise->gender              = $validatData['gender'];
        $adminadvaertise->detail_targeting    = $validatData['detail_targeting'];
        $adminadvaertise->country             = $validatData['country'];
        $adminadvaertise->city                = $validatData['city'];
        $adminadvaertise->device              = $validatData['device'];
        $adminadvaertise->platform            = $validatData['platform'];
        $adminadvaertise->inventory           = $validatData['inventory'];
        $adminadvaertise->format              = $validatData['format'];
        $adminadvaertise->ad_creative         = $validatData['ad_creative'];
        $adminadvaertise->budget              = $validatData['budget'];
        $adminadvaertise->placements          = $validatData['placements'];

        $adminadvaertise->destination      = $validatData['destination'];
        $adminadvaertise->tracking         = $validatData['tracking'];
        $adminadvaertise->url_perimeter    = $validatData['url_perimeter'];
        $adminadvaertise->number           = $validatData['number'];
        $adminadvaertise->last_description = $validatData['last_description'];
        // $adminadvaertise->status   =  Status::Pending->value;
        $adminadvaertise->audience = request( 'audience' );
        $adminadvaertise->save();

        if ( request()->hasFile( 'advertise_audience_files' ) ) {
            foreach ( request( 'advertise_audience_files' ) as $file ) {
                $data                                = uploadany_file( $file, 'uploads/advertise_audience_files/' );
                $AdvertiseAudienceFile               = new AdvertiseAudienceFile();
                $AdvertiseAudienceFile->setConnection('mysql');
                $AdvertiseAudienceFile->advertise_id = $adminadvaertise->id;
                $AdvertiseAudienceFile->file         = $data;
                $AdvertiseAudienceFile->save();
            }
        }

        if ( request()->hasFile( 'location_files' ) ) {
            foreach ( request( 'location_files' ) as $file ) {
                $data                    = uploadany_file( $file, 'uploads/location_files/' );
                $locatFile               = new LocationFile();
                $locatFile->setConnection('mysql');
                $locatFile->advertise_id = $adminadvaertise->id;
                $locatFile->file         = $data;
                $locatFile->save();
            }
        }

        return true;
    }
}
