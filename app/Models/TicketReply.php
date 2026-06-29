<?php

namespace App\Models;

use App\Enums\SupportBoxTicketStatus;
use App\Services\CrossTenantQueryService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketReply extends Model {
    use HasFactory, SoftDeletes;

    /** Central SOS data; keep off the tenant default connection when middleware sets DB to tenant. */
    protected $connection = 'mysql';

    protected $fillable = ['support_box_id', 'description', 'user_id', 'status', 'read_status'];

    function file() {
        return $this->morphOne( File::class, 'filetable' );
    }

    function user() {
        return $this->belongsTo( User::class, 'user_id' );

    }

    public function supportBox() {
        return $this->belongsTo( SupportBox::class );
    }

    /**
     * Admin replies use status "answered"; tenant replies use "replied" (same as central vendor flow).
     */
    public function isAdminReply( ?SupportBox $supportBox = null ): bool {
        if ( $this->status === SupportBoxTicketStatus::Answered->value ) {
            return true;
        }

        if ( $this->status === SupportBoxTicketStatus::Replied->value ) {
            return false;
        }

        $box = $supportBox ?? ( $this->relationLoaded( 'supportBox' ) ? $this->supportBox : null );

        return $box && (int) $this->user_id !== (int) $box->user_id;
    }

    public function resolveAuthor( ?SupportBox $supportBox = null ): ?User {
        if ( ! $this->user_id ) {
            return null;
        }

        if ( $this->isAdminReply( $supportBox ) ) {
            return User::on( 'mysql' )->withoutGlobalScopes()->find( $this->user_id );
        }

        $box = $supportBox ?? ( $this->relationLoaded( 'supportBox' ) ? $this->supportBox : null );

        if ( $box && $box->tenant_id ) {
            $tenantUser = CrossTenantQueryService::getTenantUserById( $box->tenant_id, (int) $this->user_id );
            if ( $tenantUser ) {
                return $tenantUser;
            }
        }

        return User::on( 'mysql' )->withoutGlobalScopes()->find( $this->user_id );
    }
}
