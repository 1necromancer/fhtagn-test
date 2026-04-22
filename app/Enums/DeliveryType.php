<?php

namespace App\Enums;

enum DeliveryType: string
{
    case Pickup = 'pickup';
    case Address = 'address';
}
