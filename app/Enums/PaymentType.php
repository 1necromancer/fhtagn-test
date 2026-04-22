<?php

namespace App\Enums;

enum PaymentType: string
{
    case Card = 'card';
    case Credit = 'credit';
}
