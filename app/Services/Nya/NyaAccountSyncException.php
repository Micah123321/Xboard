<?php

namespace App\Services\Nya;

use RuntimeException;

class NyaAccountSyncException extends RuntimeException
{
    public function __construct(public readonly string $accountName, public readonly string $reasonCode)
    {
        parent::__construct('NYA_ACCOUNT_ERROR');
    }
}
