<?php

namespace App\Services\ApiDetective;

use RuntimeException;

/** Discovery found nothing usable; carries the step log so the UI can show what was tried. */
class DiscoveryFailed extends RuntimeException
{
    public function __construct(string $message, public readonly array $log = [])
    {
        parent::__construct($message);
    }
}
