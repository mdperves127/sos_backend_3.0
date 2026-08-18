<?php

namespace App\Http\Controllers\API\User;

use App\Http\Controllers\Controller;
use App\Models\CampaignCategory;
use App\Models\Category;
use App\Models\ConversionLocation;
use App\Models\PerfomanceGoal;
use App\Models\Placement;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller {
    public function index() {
        $strring = DB::table( 'settings' )->where( 'deleted_at', null )->first();
        return $this->response( $strring );
    }

    public function companion() {
        $companions = DB::table( 'companions' )->where( 'deleted_at', null )->take( 3 )->get();
        return $this->response( $companions );
    }

    public function faq() {
        $faqs = DB::table( 'faqs' )->whereNull( 'deleted_at' )->get();
        return $this->response( $faqs );
    }

    public function seo() {
        $pageUrl = request( 'page_url' );

        if ( $pageUrl ) {
            $seo = DB::table( 'seo' )->where( 'page_url', $pageUrl )->get();

            if ( ! $seo ) {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'No SEO data found for this page.',
                ], 404 );
            }

            return response()->json( [
                'status'  => 200,
                'data'    => $seo,
                'message' => $seo,
            ] );
        }

        $seos = DB::table( 'seo' )->get();

        return response()->json( [
            'status'  => 200,
            'data'    => $seos,
            'message' => $seos,
        ] );
    }

    public function fottermedia() {
        $footermedia = DB::table( 'footer_media' )->where( 'deleted_at', null )->take( 8 )->get();
        return $this->response( $footermedia );
    }

    public function members() {
        $members = DB::table( 'members' )->take( 8 )->where( 'deleted_at', null )->get();
        return $this->response( $members );
    }

    public function mission() {
        $mission = DB::table( 'missions' )->where( 'deleted_at', null )->take( 4 )->get();

        return $this->response( $mission );
    }

    public function orgOne() {
        $organizations = DB::table( 'organizations' )->where( 'deleted_at', null )->take( 4 )->get();
        return $this->response( $organizations );
    }

    public function orgTwo() {
        $organizationtwos = DB::table( 'organization_twos' )->where( 'deleted_at', null )->take( 4 )->get();
        return $this->response( $organizationtwos );
    }

    public function service() {
        $service = DB::table( 'our_services' )->take( 5 )->where( 'deleted_at', null )->get();
        return $this->response( $service );
    }
    public function Itservice() {
        $service = DB::table( 'itservices' )->take( 6 )->where( 'deleted_at', null )->get();
        return $this->response( $service );
    }

    public function partner() {
        $partners = DB::table( 'partners' )->where( 'deleted_at', null )->get();
        return $this->response( $partners );
    }

    public function testimonial() {
        $testimonial = DB::table( 'testimonials' )->where( 'deleted_at', null )->get();
        return $this->response( $testimonial );
    }

    public function campaignCategory() {
        $categories = CampaignCategory::where( 'status', 'active' )->get();
        return $this->response( $categories );
    }

    public function campaignConverstionLocation( $id ) {

        $locations = ConversionLocation::where( 'campaign_category_id', $id )
            ->where( 'status', 'active' )
            ->get();

        return $this->response( $locations );

    }

    public function campaignPerformanceGoal( $id ) {
        $goals = PerfomanceGoal::where( 'campaign_category_id', $id )
            ->where( 'status', 'active' )
            ->get();

        return $this->response( $goals );
    }

    public function campaignDynamicData( $colum, $categoryid = null ) {
        $data = Placement::select( 'id', $colum )
            ->where( $colum, '!=', '' )
            ->where( 'status', 'active' )
            ->latest()
            ->when( $categoryid, function ( $query ) use ( $categoryid ) {
                $query->where( 'campaign_category_id', $categoryid );
            } )
            ->get();
        return $this->response( $data );
    }

    public function getCategory() {
        $categories = Category::whereStatus( 'active' )->latest()->select( 'id', 'name' )->get();
        return response()->json( [
            'status'     => 200,
            'categories' => $categories,
        ] );
    }
}
