<?php

namespace App\Services\MlbbDraft;

use RuntimeException;

/** No community template with that id (built-ins are read-only). */
class TemplateNotFound extends RuntimeException {}
