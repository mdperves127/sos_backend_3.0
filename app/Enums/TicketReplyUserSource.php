<?php

namespace App\Enums;

/**
 * Which database {@see TicketReply::$user_id} refers to.
 */
enum TicketReplyUserSource: string {
    case Tenant = 'tenant';
    case Admin  = 'admin';
}
