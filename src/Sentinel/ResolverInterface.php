<?php

declare(strict_types=1);

namespace Lab104\Laravel\Redis\Sentinel;

interface ResolverInterface
{
    /**
     * @return array{host: string, port: int}
     */
    public function resolveMaster(string $service): array;

    /**
     * @return array<int, array{host: string, port: int}>
     */
    public function resolveSlaves(string $service): array;

    /**
     * 失效 master / slaves 快取。
     *
     * 不帶參數會清掉全部 service 並丟棄底層 Sentinel client（適用 Sentinel 入口失聯的 hard reset）；
     * 帶 service 名只清該 service 的快取，其他 service 的解析結果保留。
     */
    public function invalidate(?string $service = null): void;
}
