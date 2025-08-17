<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\Auth;

class ConversationController extends Controller {
    public function index() {
        $user = User::find( vendorId() );

        // $converstion_user = UserSubscription::where('chat_access','yes')
        // ->with('user:id,name,image,role_as,is_employee,vendor_id','product_details')
        // ->whereHas('product_details')
        // ->where('user_id','!=',vendorId())
        // // ->orWhere('user_id','=','product_details.vendor_id')
        // ->get();

        $converstion_user = User::whereHas( 'usersubscription', function ( $query ) {
            $query->where( 'chat_access', 'yes' );
        } )
            ->whereHas( 'productDetails', function ( $query ) {
                if ( Auth::user()->role_as == 3 ) {
                    $query->where( 'user_id', Auth::id() );
                } elseif ( Auth::user()->role_as == 2 ) {
                    $query->where( 'vendor_id', vendorId() );
                }

            } )
            ->get();

        if ( !$user->usersubscription ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! It seems you are not eligible to access this feature. Please contact the administrator for assistance.',
            ] );
        }

        if ( $user?->usersubscription?->chat_access == null ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! This service is not available with your current subscription. Please contact the administrator for assistance.',
            ] );
        }

        $conversations = Conversation::where( 'sender_id', auth()->id() )
            ->orWhere( 'receiver_id', auth()->id() )
            ->get();

        return response()->json( [
            'status'        => 200,
            'conversations' => $converstion_user,
        ] );
    }
}
