<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\TenantProductReviewRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductRating;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProductReviewController extends Controller {

    private function reviewSummary( int $productId, ?string $connection = null ): array {
        $query = visibleProductRatingsQuery( $connection )->where( 'product_id', $productId );

        return [
            'average_rating' => round( (float) $query->avg( 'rating' ), 1 ),
            'total_reviews'  => $query->count(),
        ];
    }

    public function forProduct( $slugOrId ) {
        $product = Product::query()
            ->when( is_numeric( $slugOrId ), fn ( $q ) => $q->where( 'id', $slugOrId ) )
            ->when( !is_numeric( $slugOrId ), fn ( $q ) => $q->where( 'slug', $slugOrId ) )
            ->first();

        if ( !$product ) {
            return response()->json( ['status' => 404, 'message' => 'Product not found'], 404 );
        }

        $reviews = visibleProductRatingsQuery()
            ->where( 'product_id', $product->id )
            ->with( 'user:id,name' )
            ->latest()
            ->paginate( request( 'per_page', 10 ) );

        $summary = $this->reviewSummary( $product->id );

        return response()->json( [
            'status'         => 200,
            'product_id'     => $product->id,
            'reviews'        => $reviews,
            'average_rating' => $summary['average_rating'],
            'total_reviews'  => $summary['total_reviews'],
        ] );
    }

    public function store( TenantProductReviewRequest $request ) {
        $order = Order::findOrFail( $request->order_id );

        ProductRating::create( [
            'order_id'   => $order->id,
            'product_id' => $order->product_id,
            'user_id'    => Auth::id(),
            'rating'     => $request->rating,
            'comment'    => $request->comment,
            'is_visible' => false,
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Review submitted successfully. It will appear on the storefront after admin approval.',
        ] );
    }

    public function eligibleOrders( $productId ) {
        $alreadyReviewed = ProductRating::where( 'user_id', Auth::id() )
            ->where( 'product_id', $productId )
            ->exists();

        if ( $alreadyReviewed ) {
            return response()->json( [
                'status'  => 200,
                'message' => 'You have already reviewed this product.',
                'orders'  => [],
            ] );
        }

        $orders = Order::where( 'user_id', Auth::id() )
            ->where( 'product_id', $productId )
            ->whereIn( 'status', TenantProductReviewRequest::PURCHASE_STATUSES )
            ->doesntHave( 'productrating' )
            ->select( 'id', 'order_id', 'product_id', 'status', 'created_at' )
            ->latest()
            ->get();

        return response()->json( [
            'status' => 200,
            'orders' => $orders,
        ] );
    }

    public function myReviews() {
        $reviews = ProductRating::where( 'user_id', Auth::id() )
            ->with( 'product:id,name,slug' )
            ->latest()
            ->get( ['id', 'product_id', 'rating', 'comment', 'is_visible', 'created_at'] );

        return response()->json( [
            'status'  => 200,
            'reviews' => $reviews,
        ] );
    }

    public function adminIndex() {
        if ( !isTenantAdmin() ) {
            return response()->json( ['status' => 403, 'message' => 'Access denied.'], 403 );
        }

        $query = ProductRating::with( ['user:id,name,email', 'product:id,name,slug'] )->latest();

        if ( request( 'visibility' ) === 'pending' ) {
            $query->where( 'is_visible', false );
        } elseif ( request( 'visibility' ) === 'approved' ) {
            $query->where( 'is_visible', true );
        }

        $reviews = $query->paginate( request( 'per_page', 20 ) );
        $reviews->getCollection()->transform( fn ( ProductRating $review ) => $this->formatAdminReview( $review ) );

        return response()->json( [
            'status'         => 200,
            'reviews'        => $reviews,
            'pending_count'  => ProductRating::where( 'is_visible', false )->count(),
            'approved_count' => ProductRating::where( 'is_visible', true )->count(),
        ] );
    }

    public function updateStatus( Request $request, $id ) {
        if ( !isTenantAdmin() ) {
            return response()->json( ['status' => 403, 'message' => 'Access denied.'], 403 );
        }

        $request->validate( [
            'status' => ['required', Rule::in( ['approved', 'pending', 'hidden'] )],
        ] );

        $review = ProductRating::findOrFail( $id );
        $review->update( [
            'is_visible' => $request->input( 'status' ) === 'approved',
        ] );

        $message = match ( $request->input( 'status' ) ) {
            'approved' => 'Review is now visible on the storefront.',
            'hidden'   => 'Review hidden from the storefront.',
            default    => 'Review moved to pending approval.',
        };

        return response()->json( [
            'status'  => 200,
            'message' => $message,
            'review'  => $this->formatAdminReview(
                $review->fresh( ['user:id,name,email', 'product:id,name,slug'] )
            ),
        ] );
    }

    private function formatAdminReview( ProductRating $review ): ProductRating {
        $review->setAttribute( 'status', $review->is_visible ? 'approved' : 'pending' );

        return $review;
    }

    public function approve( $id ) {
        if ( !isTenantAdmin() ) {
            return response()->json( ['status' => 403, 'message' => 'Access denied.'], 403 );
        }

        $review = ProductRating::findOrFail( $id );
        $review->update( ['is_visible' => true] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Review is now visible on the storefront.',
            'review'  => $this->formatAdminReview(
                $review->fresh( ['user:id,name,email', 'product:id,name,slug'] )
            ),
        ] );
    }

    public function hide( $id ) {
        if ( !isTenantAdmin() ) {
            return response()->json( ['status' => 403, 'message' => 'Access denied.'], 403 );
        }

        $review = ProductRating::findOrFail( $id );
        $review->update( ['is_visible' => false] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Review hidden from the storefront.',
            'review'  => $this->formatAdminReview(
                $review->fresh( ['user:id,name,email', 'product:id,name,slug'] )
            ),
        ] );
    }

    public function destroy( $id ) {
        if ( !isTenantAdmin() ) {
            return response()->json( ['status' => 403, 'message' => 'Access denied.'], 403 );
        }

        ProductRating::findOrFail( $id )->delete();

        return response()->json( [
            'status'  => 200,
            'message' => 'Review deleted successfully.',
        ] );
    }
}
