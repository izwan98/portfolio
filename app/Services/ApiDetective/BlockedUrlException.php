<?php

namespace App\Services\ApiDetective;

use RuntimeException;

/** A URL the request guard refuses to fetch (bad scheme, private address, too large…). */
class BlockedUrlException extends RuntimeException {}
