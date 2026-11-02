<?php

namespace Radianz\Laravel;

use RuntimeException;

final class RadianzTestException extends RuntimeException
{
    public ?string $acceptedId = null;
    public ?string $failure = null;
}
