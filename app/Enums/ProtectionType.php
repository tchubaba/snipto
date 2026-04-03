<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Enumeration representing different types of protection mechanisms.
 */
enum ProtectionType: int
{
    case Plaintext = 0;
    case Secret    = 1;
    case Password  = 2;
}
