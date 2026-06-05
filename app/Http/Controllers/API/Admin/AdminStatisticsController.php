<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Service\Admin\AdminStatisticsService;

class AdminStatisticsController extends Controller {

    public function __construct(
        private readonly AdminStatisticsService $statistics
    ) {
    }

    public function moduleOverview() {
        return $this->responseData( [
            'adminModuleOverview' => $this->statistics->moduleOverview(),
        ] );
    }

    public function users() {
        return $this->responseData( [
            'userTypeGroups' => $this->statistics->userTypeGroups(),
        ] );
    }

    public function products() {
        return $this->responseData( [
            'productStatuses' => $this->statistics->productStatuses(),
        ] );
    }

    public function requests() {
        return $this->responseData( [
            'requestStatuses' => $this->statistics->requestStatuses(),
        ] );
    }

    public function orders() {
        return $this->responseData( [
            'orderStatuses' => $this->statistics->orderStatuses(),
        ] );
    }

    public function services() {
        return $this->responseData( [
            'serviceStatuses' => $this->statistics->serviceStatuses(),
        ] );
    }

    public function serviceOrders() {
        return $this->responseData( [
            'serviceOrderStatuses' => $this->statistics->serviceOrderStatuses(),
        ] );
    }

    public function advertise() {
        return $this->responseData( [
            'advertiseStatuses' => $this->statistics->advertiseStatuses(),
        ] );
    }

    public function coupons() {
        return $this->responseData( [
            'couponStatuses' => $this->statistics->couponStatuses(),
        ] );
    }

    public function membership() {
        return $this->responseData( [
            'membershipStatuses'      => $this->statistics->membershipStatuses(),
            'membershipTierBreakdown' => $this->statistics->membershipTierBreakdown(),
        ] );
    }

    public function subscription() {
        return $this->responseData( [
            'subscriptionStatuses'     => $this->statistics->subscriptionStatuses(),
            'subscriptionPlanBreakdown' => $this->statistics->subscriptionPlanBreakdown(),
        ] );
    }

    public function support() {
        return $this->responseData( [
            'supportStatuses' => $this->statistics->supportStatuses(),
        ] );
    }

    public function withdraw() {
        return $this->responseData( [
            'withdrawStatuses' => $this->statistics->withdrawStatuses(),
            'withdrawRecent'   => $this->statistics->withdrawRecent(),
        ] );
    }
}
