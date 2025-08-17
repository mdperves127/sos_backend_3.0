<?php
namespace App\Enums;

enum Coupon :string
{
    case Flat = "flat";
    case Percentage = "percentage";
}
