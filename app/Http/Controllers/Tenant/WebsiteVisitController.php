<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TenantInstalledAddon;
use App\Models\UserSubscription;

class WebsiteVisitController extends Controller
{
    public function websiteVisit()
    {
        $userSubscription = UserSubscription::on( 'mysql' )
            ->where( 'tenant_id', tenant()->id )
            ->first();

        if ( ! $userSubscription ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Subscription not found for this tenant.',
            ], 404 );
        }

        $addonVisitBonus = $this->activeWebsiteVisitAddonBonus( (string) tenant()->id );
        $hasVisitAddon   = $addonVisitBonus['has_addon'];
        $bonusVisits     = $addonVisitBonus['bonus_visits'];

        $canTrackVisits = $userSubscription->has_website === 'yes' || $hasVisitAddon;

        if ( $canTrackVisits ) {
            $userSubscription->already_visits++;
            $userSubscription->save();
        }

        $website_visits = (int) $userSubscription->website_visits + $bonusVisits;
        $already_visits = (int) $userSubscription->already_visits;

        return response()->json( [
            'status'         => 200,
            'message'        => $canTrackVisits
                ? 'Website visit added successfully'
                : 'Website visit not counted (no website package or visit addon).',
            'website_visits' => $website_visits,
            'already_visits' => $already_visits,
        ] );
    }

    /**
     * Sum numeric website-visit feature values from active tenant addons.
     * Recognized feature keys: website_visits, website_visit, visits.
     *
     * @return array{has_addon: bool, bonus_visits: int}
     */
    private function activeWebsiteVisitAddonBonus( string $tenantId ): array
    {
        $installations = TenantInstalledAddon::on( 'mysql' )
            ->with( [ 'addon.features' ] )
            ->where( 'tenant_id', $tenantId )
            ->where( 'status', 'active' )
            ->get();

        $hasAddon     = false;
        $bonusVisits  = 0;
        $allowedKeys  = [ 'website_visits', 'website_visit', 'visits' ];

        foreach ( $installations as $installation ) {
            $features = $installation->addon?->features ?? collect();

            foreach ( $features as $feature ) {
                $key = strtolower( trim( (string) $feature->key ) );

                if ( ! in_array( $key, $allowedKeys, true ) ) {
                    continue;
                }

                $hasAddon = true;
                $value    = trim( (string) $feature->value );

                if ( is_numeric( $value ) ) {
                    $bonusVisits += max( 0, (int) $value );
                }
            }
        }

        return [
            'has_addon'     => $hasAddon,
            'bonus_visits'  => $bonusVisits,
        ];
    }
}
