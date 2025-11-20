<?php

namespace OGame\Exceptions;

use Exception;

class QuotaExceededException extends Exception
{
    public string $limitType;

    public function __construct(string $limitType, string $message = '')
    {
        $this->limitType = $limitType;
        parent::__construct($message ?: "Quota exceeded: {$limitType}");
    }
}
