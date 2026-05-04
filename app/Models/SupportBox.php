<?php

namespace App\Models;

use App\Enums\SupportBoxTicketStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportBox extends Model
{
    use HasFactory, SoftDeletes;

    /** Central SOS data; not stored on tenant databases. */
    protected $connection = 'mysql';

    protected $guarded = [];

    /**
     * Avoid ValueError on empty/legacy DB values; normalize to {@see SupportBoxTicketStatus::NewTicket}.
     */
    protected function status(): Attribute {
        return Attribute::make(
            get: static function ( ?string $value ): SupportBoxTicketStatus {
                $v = $value === null ? '' : trim( $value );
                if ( $v === '' ) {
                    return SupportBoxTicketStatus::NewTicket;
                }

                return SupportBoxTicketStatus::tryFrom( $v ) ?? SupportBoxTicketStatus::NewTicket;
            },
            set: static function ( SupportBoxTicketStatus|string|null $value ): string {
                if ( $value instanceof SupportBoxTicketStatus ) {
                    return $value->value;
                }
                $v = $value === null ? '' : trim( (string) $value );
                if ( $v === '' ) {
                    return SupportBoxTicketStatus::NewTicket->value;
                }

                return ( SupportBoxTicketStatus::tryFrom( $v ) ?? SupportBoxTicketStatus::NewTicket )->value;
            },
        );
    }

    function user()
    {
        return $this->belongsTo(User::class);
    }

    function category(){
        return $this->belongsTo(SupportBoxCategory::class,'support_box_category_id');
    }

    function problem_topic(){
        return $this->belongsTo(SupportProblemTopic::class,'support_problem_topic_id');
    }

    function ticketreplay()
    {
        return $this->hasMany(TicketReply::class);
    }
    function latestTicketreplay(){
        return $this->hasOne(TicketReply::class,'support_box_id')->latestOfMany();
    }

    function supportassigned()
    {
        return $this->hasOne(SupportAssigned::class);
    }

}
