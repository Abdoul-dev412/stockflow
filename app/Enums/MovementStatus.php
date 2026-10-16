<?php

namespace App\Enums;

enum MovementStatus: string
{
    case Applied = 'applied';
    case Conflict = 'conflict';
    case PendingValidation = 'pending_validation';
    case Rejected = 'rejected';
}