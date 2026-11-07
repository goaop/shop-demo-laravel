<?php

declare(strict_types=1);

namespace App\Domain;

enum CustomerTier: int
{
    case Regular = 0;
    case Silver = 5;
    case Gold = 10;
}
