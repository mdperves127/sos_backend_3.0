<?php

namespace App\Models;

use App\Enums\SupportBoxTicketStatus;
use App\Enums\TicketReplyUserSource;
use App\Services\CrossTenantQueryService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketReply extends Model {
    use HasFactory, SoftDeletes;

    /** Central SOS data; keep off the tenant default connection when middleware sets DB to tenant. */
    protected $connection = 'mysql';

    protected $fillable = [
        'support_box_id',
        'description',
        'user_id',
        'user_source',
        'status',
        'read_status',
    ];

    function file() {
        return $this->morphOne( File::class, 'filetable' );
    }

    function user() {
        return $this->belongsTo( User::class, 'user_id' );
    }

    public function supportBox() {
        return $this->belongsTo( SupportBox::class );
    }

    public function isFromAdminDatabase( ?SupportBox $supportBox = null ): bool {
        if ( $this->user_source === TicketReplyUserSource::Admin->value ) {
            return true;
        }

        if ( $this->user_source === TicketReplyUserSource::Tenant->value ) {
            return false;
        }

        if ( $this->status === SupportBoxTicketStatus::Answered->value ) {
            return true;
        }

        if ( $this->status === SupportBoxTicketStatus::Replied->value ) {
            return false;
        }

        $box = $supportBox ?? ( $this->relationLoaded( 'supportBox' ) ? $this->supportBox : null );

        if ( $box && $box->tenant_id ) {
            return (int) $this->user_id !== (int) $box->user_id;
        }

        return true;
    }

    public function isFromTenantDatabase( ?SupportBox $supportBox = null ): bool {
        return ! $this->isFromAdminDatabase( $supportBox );
    }

    public function resolveAuthor( ?SupportBox $supportBox = null ): ?User {
        if ( ! $this->user_id ) {
            return null;
        }

        if ( $this->isFromAdminDatabase( $supportBox ) ) {
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
