<?php

declare(strict_types=1);

namespace Lab104\Laravel\Redis\Sentinel;

use Closure;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * 自訂 Laravel Redis Connector：建立連線前先透過 Sentinel 解析當前 master，再委派給 phpredis。
 *
 * Laravel `PhpRedisConnection::command()` 在連線中斷錯誤（Connection lost / went away …）時會呼叫
 * connector closure 重建 client；本 Connector 在該 closure 中觸發 resolver invalidate，使 reconnect
 * 自動重 query Sentinel 取得新 master，達成對呼叫端透明的 failover。
 */
class SentinelConnector extends PhpRedisConnector
{
    /**
     * @var (Closure(array<int, array{host: string, port?: int|string}>, array<string, mixed>): ResolverInterface)|null
     */
    private ?Closure $resolverFactory;

    /**
     * @param (Closure(
     *     array<int, array{host: string, port?: int|string}>,
     *     array<string, mixed>
     * ): ResolverInterface)|null $resolverFactory  建立 Resolver 的 factory；預設使用 phpredis \RedisSentinel
     *     為底的實作。測試時可注入 mock resolver。
     */
    public function __construct(?Closure $resolverFactory = null)
    {
        $this->resolverFactory = $resolverFactory;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options
     */
    public function connect(array $config, array $options): PhpRedisConnection
    {
        $service = $config['service'] ?? null;
        if (!is_string($service) || $service === '') {
            throw new InvalidArgumentException("Sentinel connection config requires a non-empty 'service' key");
        }

        $sentinels = $config['sentinels'] ?? null;
        if (!is_array($sentinels)) {
            throw new InvalidArgumentException("Sentinel connection config requires a non-empty 'sentinels' array");
        }
        // 與 PhpRedisSentinelClient 共用同一份結構驗證，確保訊息一致；提早到 Connector 邊界 fail fast。
        PhpRedisSentinelClient::assertSentinelsShape($sentinels);

        $resolver = $this->buildResolver($sentinels, $config);

        $upstreamConfig = $this->stripSentinelKeys($config);

        $formattedOptions = Arr::pull($upstreamConfig, 'options', []);
        if (isset($upstreamConfig['prefix'])) {
            $formattedOptions['prefix'] = $upstreamConfig['prefix'];
        }

        $masterResolver = self::makeMasterResolverClosure($resolver, $service);

        // 將 master host/port 注入 phpredis config 後委派 PhpRedisConnector::createClient()，
        // 這樣 Laravel 既有的 phpredis 設定（auth、database、prefix、timeout …）都會自動生效。
        $clientConnector = function () use ($masterResolver, $upstreamConfig, $options, $formattedOptions) {
            $master = $masterResolver();

            // Sentinel 解析的 master host/port 是動態決定，必須具最高優先；
            // 放在 array_merge 最末位以覆蓋 $options / $formattedOptions 中可能誤設的 host/port。
            return $this->createClient(array_merge(
                $upstreamConfig,
                $options,
                $formattedOptions,
                ['host' => $master['host'], 'port' => $master['port']]
            ));
        };

        return new PhpRedisConnection($clientConnector(), $clientConnector, $upstreamConfig);
    }

    /**
     * 產生「解析 master + reconnect 時失效快取」的 closure。
     *
     * 第一次呼叫直接走快取解析；第二次起呼叫會先 invalidate，迫使 Resolver 重 query Sentinel。
     * 抽成 static helper 是為了讓 failover 行為（不含真連 Redis）可獨立做單元測試。
     *
     * @return Closure(): array{host: string, port: int}
     */
    public static function makeMasterResolverClosure(ResolverInterface $resolver, string $service): Closure
    {
        $attempts = 0;

        return function () use ($resolver, $service, &$attempts): array {
            if ($attempts > 0) {
                // Reconnect 路徑：丟掉快取的 topology，讓下一次解析重 query Sentinel 取得新 master。
                $resolver->invalidate($service);
            }
            $attempts++;

            return $resolver->resolveMaster($service);
        };
    }

    /**
     * @param array<int, array{host: string, port?: int|string}> $sentinels
     * @param array<string, mixed> $config
     */
    private function buildResolver(array $sentinels, array $config): ResolverInterface
    {
        $factory = $this->resolverFactory ?? self::defaultResolverFactory();

        return $factory($sentinels, $config);
    }

    /**
     * @return Closure(array<int, array{host: string, port?: int|string}>, array<string, mixed>): ResolverInterface
     */
    private static function defaultResolverFactory(): Closure
    {
        return function (array $sentinels, array $config): ResolverInterface {
            // sentinel_connect_timeout / sentinel_read_timeout 各自獨立；sentinel_timeout 為 fallback alias。
            // 區分 connect 與 read 是因為「Sentinel 失聯偵測快不快」與「Sentinel 查詢回應慢不慢」屬不同特性。
            $sharedTimeout = isset($config['sentinel_timeout']) ? (float) $config['sentinel_timeout'] : null;
            $connectTimeout = isset($config['sentinel_connect_timeout'])
                ? (float) $config['sentinel_connect_timeout']
                : $sharedTimeout;
            $readTimeout = isset($config['sentinel_read_timeout'])
                ? (float) $config['sentinel_read_timeout']
                : $sharedTimeout;

            $clientOptions = array_filter([
                'connectTimeout' => $connectTimeout,
                'readTimeout' => $readTimeout,
                'auth' => $config['sentinel_password'] ?? null,
                'persistent' => $config['sentinel_persistent'] ?? null,
            ], static fn ($value) => $value !== null);

            return new Resolver(static fn (): SentinelClientInterface => new PhpRedisSentinelClient(
                $sentinels,
                $clientOptions
            ));
        };
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function stripSentinelKeys(array $config): array
    {
        unset(
            $config['sentinels'],
            $config['service'],
            $config['sentinel_timeout'],
            $config['sentinel_connect_timeout'],
            $config['sentinel_read_timeout'],
            $config['sentinel_password'],
            $config['sentinel_persistent']
        );

        return $config;
    }
}
