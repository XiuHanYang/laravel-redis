<?php

declare(strict_types=1);

namespace Lab104\Laravel\Redis\Sentinel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;

/**
 * 在 Laravel Redis Manager 註冊 'phpredis-sentinel' driver，讓 service 端只要在
 * config/database.php 寫 `'driver' => 'phpredis-sentinel'` 即可啟用 Sentinel 模式。
 *
 * 透過 composer.json 的 "extra.laravel.providers" 自動載入，service 端無需手動註冊 Provider。
 */
class SentinelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving('redis', static function ($redis, Container $app): void {
            if ($redis instanceof RedisManager) {
                $redis->extend('phpredis-sentinel', static fn () => new SentinelConnector());
            }
        });
    }
}
