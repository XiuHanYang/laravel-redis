<?php

declare(strict_types=1);

namespace Lab104\Laravel\Redis\Sentinel;

interface SentinelClientInterface
{
    /**
     * @return array{ip: string, port: int|string, flags: string, role-reported: string}|false
     */
    public function master(string $service): array|false;

    /**
     * @return array<int, array{ip: string, port: int|string, flags: string}>|false
     */
    public function slaves(string $service): array|false;
}
