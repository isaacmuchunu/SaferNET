<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * This model id will never work again — it was retired or never existed. A 429
 * or a 503 is load and will pass; only this should burn a model id.
 */
class ModelUnavailable extends RuntimeException {}
