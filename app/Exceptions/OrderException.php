<?php

namespace App\Exceptions;

use Exception;

class OrderException extends Exception
{
    public function __construct(string $message = 'Order operation failed.')
    {
        parent::__construct($message);
    }
}
