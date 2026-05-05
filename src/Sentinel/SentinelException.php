<?php

declare(strict_types=1);

namespace Lab104\Laravel\Redis\Sentinel;

use RuntimeException;

class SentinelException extends RuntimeException
{
    public const RESOLVE_ERROR = 100;
    public const CONNECTION_ERROR = 200;

    public static function masterNotFound(string $service): self
    {
        return new self("Sentinel master group [{$service}] not found", self::RESOLVE_ERROR);
    }

    public static function masterDown(string $service, string $flags): self
    {
        return new self(
            "Sentinel master group [{$service}] reported down (flags: {$flags})",
            self::RESOLVE_ERROR
        );
    }

    public static function masterRoleMismatch(string $service, string $role): self
    {
        return new self(
            "Sentinel master group [{$service}] role must be 'master' but got '{$role}'",
            self::RESOLVE_ERROR
        );
    }
}
