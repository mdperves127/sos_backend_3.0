<?php

namespace App\Service\Admin;

use App\Enums\SupportBoxTicketStatus;
use App\Models\AdminAdvertise;
use App\Models\Coupon;
use App\Models\CouponRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductDetails;
use App\Models\ServiceOrder;
use App\Models\Subscription;
use App\Models\SupportBox;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\VendorService;
use App\Models\Withdraw;
use App\Services\CrossTenantQueryService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminStatisticsService {

    private const STATUS_COLORS = [
        'active'    => 'bg-emerald-500',
        'pending'   => 'bg-amber-500',
        'blocked'   => 'bg-red-500',
        'edit'      => 'bg-blue-500',
        'rejected'  => 'bg-red-500',
        'expired'   => 'bg-neutral-400',
        'hold'      => 'bg-orange-500',
        'received'  => 'bg-blue-500',
        'processing'=> 'bg-indigo-500',
        'delivered' => 'bg-emerald-500',
        'return'    => 'bg-purple-500',
        'cancelled' => 'bg-red-500',
        'deactivate'=> 'bg-neutral-400',
        'progress'  => 'bg-blue-500',
        'completed' => 'bg-emerald-500',
        'running'   => 'bg-emerald-500',
        'paused'    => 'bg-blue-500',
        'request'   => 'bg-amber-500',
        'answered'  => 'bg-blue-500',
        'closed'    => 'bg-emerald-500',
        'approved'  => 'bg-emerald-500',
        'trial'     => 'bg-blue-500',
    ];

    private function statusCount( string $label, int $count, string $colorKey ): array {
        return [
            'label' => $label,
            'count' => $count,
            'color' => self::STATUS_COLORS[$colorKey] ?? 'bg-neutral-400',
        ];
    }

    private function percentChange( int|float $current, int|float $previous ): array {
        if ( $previous == 0 ) {
            return [
                'change' => $current > 0 ? '+100%' : '0%',
                'trend'  => $current >= $previous ? 'up' : 'down',
            ];
        }

        $pct  = ( ( $current - $previous ) / $previous ) * 100;
        $sign = $pct >= 0 ? '+' : '-';

        return [
            'change' => sprintf( '%s%.1f%%', $sign, abs( $pct ) ),
            'trend'  => $pct >= 0 ? 'up' : 'down',
        ];
    }

    private function todayDeltaChange( int $today, string $unit ): array {
        $sign  = $today >= 0 ? '+' : '';
        $trend = $today >= 0 ? 'up' : 'down';

        return [
            'change' => sprintf( '%s%d %s today', $sign, abs( $today ), $unit ),
            'trend'  => $trend,
        ];
    }

    private function moduleCard( string $id, string $title, int|float $total, array $trend ): array {
        return [
            'id'     => $id,
            'title'  => $title,
            'total'  => $total,
            'change' => $trend['change'],
            'trend'  => $trend['trend'],
        ];
    }

    private function countCreatedBetween( $query, Carbon $start, Carbon $end ): int {
        return (int) ( clone $query )->whereBetween( 'created_at', [$start, $end] )->count();
    }

    private function allProducts(): Collection {
        return CrossTenantQueryService::queryAllTenants( Product::class, fn( $query ) => $query );
    }

    private function allOrders(): Collection {
        return CrossTenantQueryService::queryAllTenants( Order::class, fn( $query ) => $query );
    }

    private function allProductDetails(): Collection {
        return CrossTenantQueryService::queryAllDropshipperTenants( ProductDetails::class, fn( $query ) => $query );
    }

    private function editedProductCount(): int {
        return CrossTenantQueryService::queryAllTenants(
            Product::class,
            function ( $query ) {
                $query->leftJoin( 'pending_products', 'products.id', '=', 'pending_products.product_id' )
                    ->whereNotNull( 'pending_products.id' )
                    ->select( 'products.*' )
                    ->groupBy( 'products.id' );
            }
        )->count();
    }

    private function tenantStatusCounts( string $type ): array {
        $base = Tenant::query()->where( 'type', $type );

        return [
            'total'   => (int) ( clone $base )->count(),
            'active'  => (int) ( clone $base )->where( 'status', 'active' )->count(),
            'pending' => (int) ( clone $base )->where( 'status', 'pending' )->count(),
            'blocked' => (int) ( clone $base )->where( 'status', 'blocked' )->count(),
        ];
    }

    private function userStatusCounts(): array {
        $base = User::query()->where( 'role_as', 4 );

        return [
            'total'   => (int) ( clone $base )->count(),
            'active'  => (int) ( clone $base )->where( 'status', 'active' )->count(),
            'pending' => (int) ( clone $base )->where( 'status', 'pending' )->count(),
            'blocked' => (int) ( clone $base )->where( 'status', 'blocked' )->count(),
        ];
    }

    public function moduleOverview(): array {
        $now            = Carbon::now();
        $monthStart     = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd   = $now->copy()->subMonth()->endOfMonth();
        $today          = Carbon::today();

        $merchant = $this->tenantStatusCounts( 'merchant' );
        $dropship = $this->tenantStatusCounts( 'dropshipper' );
        $users    = $this->userStatusCounts();
        $userTotal = $merchant['total'] + $dropship['total'] + $users['total'];

        $userThisMonth = Tenant::query()->whereIn( 'type', ['merchant', 'dropshipper'] )
            ->where( 'created_at', '>=', $monthStart )->count()
            + User::query()->where( 'role_as', 4 )->where( 'created_at', '>=', $monthStart )->count();
        $userLastMonth = Tenant::query()->whereIn( 'type', ['merchant', 'dropshipper'] )
            ->whereBetween( 'created_at', [$lastMonthStart, $lastMonthEnd] )->count()
            + User::query()->where( 'role_as', 4 )->whereBetween( 'created_at', [$lastMonthStart, $lastMonthEnd] )->count();

        $products     = $this->allProducts();
        $productTotal = $products->count();
        $productThis  = $products->filter( fn( $item ) => Carbon::parse( $item->created_at )->gte( $monthStart ) )->count();
        $productLast  = $products->filter( fn( $item ) => Carbon::parse( $item->created_at )->between( $lastMonthStart, $lastMonthEnd ) )->count();

        $requests     = $this->allProductDetails();
        $requestTotal = $requests->count();
        $requestToday = $requests->filter( fn( $item ) => Carbon::parse( $item->created_at )->isSameDay( $today ) )->count();

        $orders     = $this->allOrders();
        $orderTotal = $orders->count();
        $orderThis  = $orders->filter( fn( $item ) => Carbon::parse( $item->created_at )->gte( $monthStart ) )->count();
        $orderLast  = $orders->filter( fn( $item ) => Carbon::parse( $item->created_at )->between( $lastMonthStart, $lastMonthEnd ) )->count();

        $serviceBase  = VendorService::query();
        $serviceTotal = (int) ( clone $serviceBase )->count();
        $serviceThis  = $this->countCreatedBetween( $serviceBase, $monthStart, $now );
        $serviceLast  = $this->countCreatedBetween( $serviceBase, $lastMonthStart, $lastMonthEnd );

        $advertiseBase  = AdminAdvertise::query()->where( 'is_paid', 1 );
        $advertiseTotal = (int) ( clone $advertiseBase )->count();
        $advertiseThis  = $this->countCreatedBetween( $advertiseBase, $monthStart, $now );
        $advertiseLast  = $this->countCreatedBetween( $advertiseBase, $lastMonthStart, $lastMonthEnd );

        $couponTotal = (int) Coupon::query()->count() + (int) CouponRequest::query()->count();
        $couponThis  = Coupon::query()->where( 'created_at', '>=', $monthStart )->count()
            + CouponRequest::query()->where( 'created_at', '>=', $monthStart )->count();
        $couponLast  = Coupon::query()->whereBetween( 'created_at', [$lastMonthStart, $lastMonthEnd] )->count()
            + CouponRequest::query()->whereBetween( 'created_at', [$lastMonthStart, $lastMonthEnd] )->count();

        $membershipBase  = UserSubscription::query();
        $membershipTotal = (int) ( clone $membershipBase )->count();
        $membershipThis  = $this->countCreatedBetween( $membershipBase, $monthStart, $now );
        $membershipLast  = $this->countCreatedBetween( $membershipBase, $lastMonthStart, $lastMonthEnd );

        $subscriptionBase  = UserSubscription::query()
            ->whereHas( 'subscription', fn( $q ) => $q->where( 'plan_type', '!=', 'freemium' )->orWhereNull( 'plan_type' ) );
        $subscriptionTotal = (int) ( clone $subscriptionBase )->count();
        $subscriptionThis  = $this->countCreatedBetween( $subscriptionBase, $monthStart, $now );
        $subscriptionLast  = $this->countCreatedBetween( $subscriptionBase, $lastMonthStart, $lastMonthEnd );

        $supportBase  = SupportBox::query();
        $supportTotal = (int) ( clone $supportBase )->count();
        $supportThis  = $this->countCreatedBetween( $supportBase, $monthStart, $now );
        $supportLast  = $this->countCreatedBetween( $supportBase, $lastMonthStart, $lastMonthEnd );

        $withdrawBase  = Withdraw::query();
        $withdrawTotal = (int) ( clone $withdrawBase )->count();
        $withdrawThis  = $this->countCreatedBetween( $withdrawBase, $monthStart, $now );
        $withdrawLast  = $this->countCreatedBetween( $withdrawBase, $lastMonthStart, $lastMonthEnd );

        return [
            $this->moduleCard( 'users', 'Users', $userTotal, $this->percentChange( $userThisMonth, $userLastMonth ) ),
            $this->moduleCard( 'products', 'Products', $productTotal, $this->percentChange( $productThis, $productLast ) ),
            $this->moduleCard( 'requests', 'Requests', $requestTotal, $this->todayDeltaChange( $requestToday, 'requests' ) ),
            $this->moduleCard( 'orders', 'Orders', $orderTotal, $this->percentChange( $orderThis, $orderLast ) ),
            $this->moduleCard( 'services', 'Services', $serviceTotal, $this->percentChange( $serviceThis, $serviceLast ) ),
            $this->moduleCard( 'advertise', 'Advertise', $advertiseTotal, $this->percentChange( $advertiseThis, $advertiseLast ) ),
            $this->moduleCard( 'coupons', 'Coupons', $couponTotal, $this->percentChange( $couponThis, $couponLast ) ),
            $this->moduleCard( 'membership', 'Membership', $membershipTotal, $this->percentChange( $membershipThis, $membershipLast ) ),
            $this->moduleCard( 'subscription', 'Subscription', $subscriptionTotal, $this->percentChange( $subscriptionThis, $subscriptionLast ) ),
            $this->moduleCard( 'support', 'Support', $supportTotal, $this->percentChange( $supportThis, $supportLast ) ),
            $this->moduleCard( 'withdraw', 'Withdraw', $withdrawTotal, $this->percentChange( $withdrawThis, $withdrawLast ) ),
        ];
    }

    private function totalUsersCount(): int {
        $merchant = $this->tenantStatusCounts( 'merchant' );
        $dropship = $this->tenantStatusCounts( 'dropshipper' );
        $users    = $this->userStatusCounts();

        return $merchant['total'] + $dropship['total'] + $users['total'];
    }

    public function registrationTrend( int $months = 12 ): array {
        $now   = Carbon::now();
        $trend = [];

        for ( $i = $months - 1; $i >= 0; $i-- ) {
            $monthStart = $now->copy()->subMonths( $i )->startOfMonth();
            $monthEnd   = $now->copy()->subMonths( $i )->endOfMonth();

            $tenantCount = (int) Tenant::query()
                ->whereIn( 'type', ['merchant', 'dropshipper'] )
                ->whereBetween( 'created_at', [$monthStart, $monthEnd] )
                ->count();

            $userCount = (int) User::query()
                ->where( 'role_as', 4 )
                ->whereBetween( 'created_at', [$monthStart, $monthEnd] )
                ->count();

            $trend[] = [
                'month' => $monthStart->format( 'M' ),
                'value' => $tenantCount + $userCount,
            ];
        }

        return $trend;
    }

    public function usersStatistics(): array {
        return [
            'total'              => $this->totalUsersCount(),
            'userTypeGroups'     => $this->userTypeGroups(),
            'registrationTrend'  => $this->registrationTrend(),
        ];
    }

    public function userTypeGroups(): array {
        $merchant = $this->tenantStatusCounts( 'merchant' );
        $dropship = $this->tenantStatusCounts( 'dropshipper' );
        $users    = $this->userStatusCounts();

        return [
            [
                'type'     => 'Merchant',
                'total'    => $merchant['total'],
                'statuses' => [
                    $this->statusCount( 'Active', $merchant['active'], 'active' ),
                    $this->statusCount( 'Pending', $merchant['pending'], 'pending' ),
                    $this->statusCount( 'Blocked', $merchant['blocked'], 'blocked' ),
                ],
            ],
            [
                'type'     => 'Dropshipper',
                'total'    => $dropship['total'],
                'statuses' => [
                    $this->statusCount( 'Active', $dropship['active'], 'active' ),
                    $this->statusCount( 'Pending', $dropship['pending'], 'pending' ),
                    $this->statusCount( 'Blocked', $dropship['blocked'], 'blocked' ),
                ],
            ],
            [
                'type'     => 'User',
                'total'    => $users['total'],
                'statuses' => [
                    $this->statusCount( 'Active', $users['active'], 'active' ),
                    $this->statusCount( 'Pending', $users['pending'], 'pending' ),
                    $this->statusCount( 'Blocked', $users['blocked'], 'blocked' ),
                ],
            ],
        ];
    }

    public function productStatuses(): array {
        $products = $this->allProducts();

        return [
            $this->statusCount( 'Active', $products->where( 'status', 'active' )->count(), 'active' ),
            $this->statusCount( 'Pending', $products->where( 'status', 'pending' )->count(), 'pending' ),
            $this->statusCount( 'Edit', $this->editedProductCount(), 'edit' ),
            $this->statusCount( 'Rejected', $products->where( 'status', 'rejected' )->count(), 'rejected' ),
        ];
    }

    public function requestStatuses(): array {
        $requests = $this->allProductDetails();
        $now      = Carbon::now();

        $expiredTenantIds = UserSubscription::query()
            ->where( 'expire_date', '<', $now )
            ->whereNotNull( 'tenant_id' )
            ->pluck( 'tenant_id' )
            ->unique()
            ->all();

        $expired = $requests->where( 'status', 1 )
            ->filter( fn( $item ) => !empty( $item->tenant_id ) && in_array( $item->tenant_id, $expiredTenantIds, true ) )
            ->count();

        return [
            $this->statusCount( 'Active', $requests->where( 'status', 1 )->count(), 'active' ),
            $this->statusCount( 'Pending', $requests->where( 'status', 2 )->count(), 'pending' ),
            $this->statusCount( 'Rejected', $requests->where( 'status', 3 )->count(), 'rejected' ),
            $this->statusCount( 'Expired', $expired, 'expired' ),
        ];
    }

    public function orderStatuses(): array {
        $orders = $this->allOrders();

        return [
            $this->statusCount( 'Pending', $orders->where( 'status', 'pending' )->count(), 'pending' ),
            $this->statusCount( 'Hold', $orders->where( 'status', 'hold' )->count(), 'hold' ),
            $this->statusCount( 'Received', $orders->where( 'status', 'received' )->count(), 'received' ),
            $this->statusCount( 'Processing', $orders->whereIn( 'status', ['progress', 'processing'] )->count(), 'processing' ),
            $this->statusCount( 'Delivered', $orders->where( 'status', 'delivered' )->count(), 'delivered' ),
            $this->statusCount( 'Return', $orders->where( 'status', 'return' )->count(), 'return' ),
            $this->statusCount( 'Cancelled', $orders->whereIn( 'status', ['cancel', 'cancelled'] )->count(), 'cancelled' ),
        ];
    }

    public function serviceStatuses(): array {
        $base = VendorService::query();

        return [
            $this->statusCount( 'Active', (int) ( clone $base )->where( 'status', 'active' )->count(), 'active' ),
            $this->statusCount( 'Pending', (int) ( clone $base )->where( 'status', 'pending' )->count(), 'pending' ),
            $this->statusCount( 'Deactivate', (int) ( clone $base )->onlyTrashed()->count() + (int) ( clone $base )->where( 'status', 'rejected' )->count(), 'deactivate' ),
        ];
    }

    public function serviceOrderStatuses(): array {
        $base = ServiceOrder::query()->where( 'is_paid', 1 );

        return [
            $this->statusCount( 'Pending', (int) ( clone $base )->where( 'status', 'pending' )->count(), 'pending' ),
            $this->statusCount( 'Progress', (int) ( clone $base )->where( 'status', 'progress' )->count(), 'progress' ),
            $this->statusCount( 'Hold', (int) ( clone $base )->where( 'status', 'hold' )->count(), 'hold' ),
            $this->statusCount( 'Completed', (int) ( clone $base )->whereIn( 'status', ['success', 'delivered', 'completed'] )->count(), 'completed' ),
            $this->statusCount( 'Cancelled', (int) ( clone $base )->whereIn( 'status', ['canceled', 'cancel'] )->count(), 'cancelled' ),
        ];
    }

    public function advertiseStatuses(): array {
        $now  = Carbon::now();
        $base = AdminAdvertise::query()->where( 'is_paid', 1 );

        $running = (int) ( clone $base )->where( 'status', 'progress' )
            ->where( function ( $q ) use ( $now ) {
                $q->whereNull( 'end_date' )->orWhere( 'end_date', '>=', $now );
            } )->count();

        $paused = (int) ( clone $base )->where( 'status', 'progress' )
            ->whereNotNull( 'end_date' )
            ->where( 'end_date', '<', $now )
            ->count();

        return [
            $this->statusCount( 'Running', $running, 'running' ),
            $this->statusCount( 'Pending', (int) ( clone $base )->where( 'status', 'pending' )->count(), 'pending' ),
            $this->statusCount( 'Paused', $paused, 'paused' ),
            $this->statusCount( 'Rejected', (int) ( clone $base )->where( 'status', 'cancel' )->count(), 'rejected' ),
        ];
    }

    public function couponStatuses(): array {
        return [
            $this->statusCount( 'Active', (int) Coupon::query()->where( 'status', 'active' )->count(), 'active' ),
            $this->statusCount( 'Request', (int) CouponRequest::query()->where( 'status', 'pending' )->count(), 'request' ),
            $this->statusCount( 'Rejected', (int) CouponRequest::query()->where( 'status', 'reject' )->count(), 'rejected' ),
        ];
    }

    public function membershipStatuses(): array {
        $now  = Carbon::now();
        $base = UserSubscription::query();

        $active = (int) ( clone $base )->where( 'expire_date', '>=', $now )->count();
        $expired = (int) ( clone $base )->where( 'expire_date', '<', $now )->count();
        $pending = (int) Tenant::query()->whereIn( 'type', ['merchant', 'dropshipper'] )->where( 'status', 'pending' )->count();

        return [
            $this->statusCount( 'Active', $active, 'active' ),
            $this->statusCount( 'Expired', $expired, 'expired' ),
            $this->statusCount( 'Pending', $pending, 'pending' ),
        ];
    }

    public function membershipTierBreakdown(): array {
        $tierLabels = [
            'freemium' => 'Basic',
            'basic'    => 'Standard',
            'premium'  => 'Premium',
            'vip'      => 'Enterprise',
        ];

        $rows = UserSubscription::query()
            ->join( 'subscriptions', 'user_subscriptions.subscription_id', '=', 'subscriptions.id' )
            ->where( 'user_subscriptions.expire_date', '>=', Carbon::now() )
            ->select( 'subscriptions.plan_type', DB::raw( 'COUNT(*) as total' ) )
            ->groupBy( 'subscriptions.plan_type' )
            ->get();

        $breakdown = [];
        foreach ( $tierLabels as $planType => $label ) {
            $row = $rows->firstWhere( 'plan_type', $planType );
            $breakdown[] = [
                'tier'  => $label,
                'count' => (int) ( $row->total ?? 0 ),
            ];
        }

        return $breakdown;
    }

    public function subscriptionStatuses(): array {
        $now = Carbon::now();

        $active = (int) UserSubscription::query()->where( 'expire_date', '>=', $now )
            ->whereHas( 'subscription', fn( $q ) => $q->where( 'plan_type', '!=', 'freemium' )->orWhereNull( 'plan_type' ) )
            ->count();

        $expired = (int) UserSubscription::query()->where( 'expire_date', '<', $now )
            ->whereHas( 'subscription', fn( $q ) => $q->where( 'plan_type', '!=', 'freemium' )->orWhereNull( 'plan_type' ) )
            ->count();

        $trial = (int) UserSubscription::query()->where( 'expire_date', '>=', $now )
            ->whereHas( 'subscription', fn( $q ) => $q->where( 'plan_type', 'freemium' ) )
            ->count();

        return [
            $this->statusCount( 'Active', $active, 'active' ),
            $this->statusCount( 'Expired', $expired, 'expired' ),
            $this->statusCount( 'Trial', $trial, 'trial' ),
        ];
    }

    public function subscriptionPlanBreakdown(): array {
        $rows = UserSubscription::query()
            ->join( 'subscriptions', 'user_subscriptions.subscription_id', '=', 'subscriptions.id' )
            ->join( 'tenants', 'user_subscriptions.tenant_id', '=', 'tenants.id' )
            ->where( 'user_subscriptions.expire_date', '>=', Carbon::now() )
            ->select(
                'subscriptions.card_heading',
                'tenants.type',
                DB::raw( 'COUNT(*) as total' )
            )
            ->groupBy( 'subscriptions.card_heading', 'tenants.type' )
            ->get();

        $grouped = [];
        foreach ( $rows as $row ) {
            $plan = $row->card_heading ?: 'Unnamed Plan';
            if ( !isset( $grouped[$plan] ) ) {
                $grouped[$plan] = [
                    'plan'          => $plan,
                    'merchants'     => 0,
                    'dropshippers'  => 0,
                ];
            }

            if ( $row->type === 'merchant' ) {
                $grouped[$plan]['merchants'] += (int) $row->total;
            } elseif ( $row->type === 'dropshipper' ) {
                $grouped[$plan]['dropshippers'] += (int) $row->total;
            }
        }

        return array_values( $grouped );
    }

    public function supportStatuses(): array {
        $pending = (int) SupportBox::query()->where( 'status', SupportBoxTicketStatus::NewTicket->value )->count();
        $answered = (int) SupportBox::query()->whereIn( 'status', [
            SupportBoxTicketStatus::Answered->value,
            SupportBoxTicketStatus::Replied->value,
        ] )->count();
        $closed = (int) SupportBox::query()->where( 'status', SupportBoxTicketStatus::Closed->value )->count();

        return [
            $this->statusCount( 'Pending', $pending, 'pending' ),
            $this->statusCount( 'Answered', $answered, 'answered' ),
            $this->statusCount( 'Closed', $closed, 'closed' ),
        ];
    }

    public function withdrawStatuses(): array {
        $base = Withdraw::query();

        return [
            $this->statusCount( 'Pending', (int) ( clone $base )->where( 'status', 'pending' )->count(), 'pending' ),
            $this->statusCount( 'Approved', (int) ( clone $base )->where( 'status', 'success' )->count(), 'approved' ),
            $this->statusCount( 'Rejected', (int) ( clone $base )->where( 'status', 'reject' )->count(), 'rejected' ),
        ];
    }

    public function withdrawRecent( int $limit = 4 ): array {
        return Withdraw::query()
            ->with( 'tenant:id,company_name', 'user:id,name' )
            ->latest()
            ->limit( $limit )
            ->get()
            ->map( function ( Withdraw $withdraw ) {
                $status = match ( $withdraw->status ) {
                    'success' => 'Approved',
                    'reject'  => 'Rejected',
                    default   => 'Pending',
                };

                return [
                    'id'     => $withdraw->uniqid ?: ( 'WDR-' . str_pad( (string) $withdraw->id, 3, '0', STR_PAD_LEFT ) ),
                    'name'   => $withdraw->tenant?->company_name ?? $withdraw->user?->name ?? 'Unknown',
                    'status' => $status,
                    'amount' => '৳ ' . number_format( (float) $withdraw->amount, 0 ),
                    'date'   => $withdraw->created_at?->format( 'M j, Y' ) ?? '',
                ];
            } )
            ->values()
            ->all();
    }
}
