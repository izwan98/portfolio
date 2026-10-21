<?php

namespace App\Services\MlbbDraft;

use RuntimeException;

/** The caller doesn't hold the edit key for this template. */
class TemplateForbidden extends RuntimeException {}
