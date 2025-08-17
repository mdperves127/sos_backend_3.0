<?php
namespace App\Enums;

enum Status: string {
case Active     = "active";
case Pending    = "pending";
case Admin      = "admin";
case Vendor     = "vendor";
case Rejected   = "rejected";
case Progress   = "progress";
case Processing = "processing";
case Ready      = "ready";
case Delivered  = "delivered";
case Cancel     = "cancel";
case Hold       = 'hold';
case Success    = 'success';
case Deactivate = "deactivate";
case Completed  = "completed";
case Return     = "return";
}
