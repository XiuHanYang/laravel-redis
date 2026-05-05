<?php

declare(strict_types=1);

namespace Lab104\Laravel\Redis\Sentinel;

use InvalidArgumentException;
use RedisSentinel;
use Throwable;

/**
 * 以 phpredis 的 \RedisSentinel 為底，按設定順序輪試 sentinel host。
 * 抽出此薄封裝是為了讓 Resolver 介面保持精簡且可純單元測試（不需依賴 ext-redis 與實機 Sentinel）。
 */
class PhpRedisSentinelClient implements SentinelClientInterface
{
    /**
     * @var array<int, array{host: string, port?: int|string}>
     */
    private array $sentinels;

    /**
     * @var array<string, mixed>
     */
    private array $options;

    private ?RedisSentinel $client = null;

    /**
     * @param array<int, array{host: string, port?: int|string}> $sentinels
     * @param array{
     *     connectTimeout?: float,
     *     readTimeout?: float,
     *     auth?: string|array<int, string>|null,
     *     persistent?: bool,
     *     retryInterval?: int,
     * } $options
     */
    public function __construct(array $sentinels, array $options = [])
    {
        self::assertSentinelsShape($sentinels);

        $this->sentinels = array_values($sentinels);
        $this->options = $options;
    }

    /**
     * 驗證 sentinel 陣列結構。Connector 與 Client 兩個入口共用，避免邏輯重複且訊息一致。
     *
     * @param array<int, array{host?: mixed, port?: mixed}>|array<mixed> $sentinels
     */
    public static function assertSentinelsShape(array $sentinels): void
    {
        if ($sentinels === []) {
            throw new InvalidArgumentException('Sentinel host list must not be empty');
        }

        foreach ($sentinels as $idx => $entry) {
            if (!is_array($entry) || !isset($entry['host']) || !is_string($entry['host']) || $entry['host'] === '') {
                throw new InvalidArgumentException(
                    "sentinels[{$idx}] must be an array with a non-empty 'host' key"
                );
            }
        }
    }

    /**
     * @return array{ip: string, port: int|string, flags: string, role-reported: string}|false
     */
    public function master(string $service): array|false
    {
        return $this->client()->master($service);
    }

    /**
     * @return array<int, array{ip: string, port: int|string, flags: string}>|false
     */
    public function slaves(string $service): array|false
    {
        return $this->client()->slaves($service);
    }

    private function client(): RedisSentinel
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $lastError = null;
        foreach ($this->sentinels as $sentinel) {
            try {
                return $this->client = $this->createClient($sentinel);
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        throw new SentinelException(
            'Unable to connect to any Sentinel host: ' . ($lastError?->getMessage() ?? 'unknown error'),
            SentinelException::CONNECTION_ERROR,
            $lastError
        );
    }

    /**
     * 抽為 protected 以便測試覆寫 — 真實環境會建立 \RedisSentinel；測試可注入 fake/throw。
     *
     * @param array{host: string, port?: int|string} $sentinel
     */
    protected function createClient(array $sentinel): RedisSentinel
    {
        $config = ['host' => $sentinel['host'], 'port' => (int) ($sentinel['port'] ?? 26379)];

        foreach (['connectTimeout', 'readTimeout', 'persistent', 'retryInterval', 'auth'] as $key) {
            if (array_key_exists($key, $this->options) && $this->options[$key] !== null) {
                $config[$key] = $this->options[$key];
            }
        }

        return new RedisSentinel($config);
    }
}
