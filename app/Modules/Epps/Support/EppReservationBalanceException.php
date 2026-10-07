<?php

namespace App\Modules\Epps\Support;

use InvalidArgumentException;

final class EppReservationBalanceException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly array $snapshot)
    {
        parent::__construct($message);
    }
}
