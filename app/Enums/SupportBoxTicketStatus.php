<?php

namespace App\Enums;

/**
 * Support ticket lifecycle on central `support_boxes.status`.
 */
enum SupportBoxTicketStatus: string {
    case NewTicket = 'new_ticket';
    case Answered  = 'answered';
    case Replied   = 'replied';
    case Closed    = 'closed';
}
