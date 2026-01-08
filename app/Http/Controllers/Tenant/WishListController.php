<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WishList;

class WishListController extends Controller
{
    public function addToWishlist(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);
        $wishlist = WishList::create([
            'user_id' => auth()->user()->id,
            'product_id' => $request->product_id,
            'tenant_id' => $request->tenant_id ?? null,
        ]);
        return response()->json([
            'message' => 'Product added to wishlist',
            'success' => true,
        ]);
    }
    public function wishlist(Request $request)
    {
        $wishlist = WishList::where('user_id', auth()->user()->id)->get();
        return response()->json(
            [
                'message' => 'Wishlist fetched successfully',
                'success' => true,
                'wishlist' => $wishlist,
            ]
        );
    }
    public function deleteWishlist(Request $request)
    {
        $wishlist = WishList::where('user_id', auth()->user()->id)->where('product_id', $request->product_id)->delete();
        return response()->json([
            'message' => 'Wishlist deleted successfully',
            'success' => true,
        ]);
    }
}
