<?php

namespace Radianz\Laravel;

use RuntimeException;

final class IngestionException extends RuntimeException
{
    public function shouldPause(): bool
    {
        return in_array($this->getCode(), [401, 403, 408, 429], true) || $this->getCode() >= 500;
    }
}
