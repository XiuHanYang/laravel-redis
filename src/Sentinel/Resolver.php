<?php

declare(strict_types=1);

namespace Lab104\Laravel\Redis\Sentinel;

use Closure;

class Resolver implements ResolverInterface
{
    /**
     * Sentinel master flag 代表「該節點不可用」的 token。
     *  - s_down：本 Sentinel 認為 down
     *  - o_down：多數 Sentinel 同意 down（已成真實 down）
     *  - disconnected：Sentinel 與該節點失聯
     * 採 token-based 比對避免「子字串包含 down」的誤判（例：未來新增 *_countdown / shutdown_pending）。
     */
    private const MASTER_DOWN_FLAGS = ['s_down', 'o_down', 'disconnected'];

    /**
     * Sentinel slave 多了 `master_link_down`：表示此 slave 與 master 連線斷，資料可能落後，
     * 不該被當作可讀節點。
     */
    private const SLAVE_DOWN_FLAGS = ['s_down', 'o_down', 'disconnected', 'master_link_down'];

    /**
     * @var Closure(): SentinelClientInterface
     */
    private Closure $clientBuilder;

    private ?SentinelClientInterface $client = null;

    /**
     * @var array<string, array{host: string, port: int}>
     */
    private array $masterCache = [];

    /**
     * @var array<string, array<int, array{host: string, port: int}>>
     */
    private array $slavesCache = [];

    /**
     * @param Closure(): SentinelClientInterface $clientBuilder
     */
    public function __construct(Closure $clientBuilder)
    {
        $this->clientBuilder = $clientBuilder;
    }

    public function resolveMaster(string $service): array
    {
        if (isset($this->masterCache[$service])) {
            return $this->masterCache[$service];
        }

        return $this->masterCache[$service] = $this->fetchMaster($service);
    }

    public function resolveSlaves(string $service): array
    {
        if (isset($this->slavesCache[$service])) {
            return $this->slavesCache[$service];
        }

        return $this->slavesCache[$service] = $this->fetchSlaves($service);
    }

    public function invalidate(?string $service = null): void
    {
        if ($service === null) {
            $this->masterCache = [];
            $this->slavesCache = [];
            $this->client = null;

            return;
        }

        unset($this->masterCache[$service], $this->slavesCache[$service]);
    }

    /**
     * @return array{host: string, port: int}
     */
    private function fetchMaster(string $service): array
    {
        $info = $this->client()->master($service);

        if (!is_array($info) || $info === []) {
            throw SentinelException::masterNotFound($service);
        }

        $flags = (string) ($info['flags'] ?? '');
        if (self::flagsContainAnyOf($flags, self::MASTER_DOWN_FLAGS)) {
            throw SentinelException::masterDown($service, $flags);
        }

        $role = (string) ($info['role-reported'] ?? '');
        if ($role !== 'master') {
            throw SentinelException::masterRoleMismatch($service, $role);
        }

        return [
            'host' => (string) $info['ip'],
            'port' => (int) $info['port'],
        ];
    }

    /**
     * @return array<int, array{host: string, port: int}>
     */
    private function fetchSlaves(string $service): array
    {
        $slaves = $this->client()->slaves($service);
        if (!is_array($slaves)) {
            $slaves = [];
        }

        $list = [];
        foreach ($slaves as $slave) {
            if (!is_array($slave)) {
                continue;
            }
            $flags = (string) ($slave['flags'] ?? '');
            if (self::flagsContainAnyOf($flags, self::SLAVE_DOWN_FLAGS)) {
                continue;
            }
            $list[] = [
                'host' => (string) $slave['ip'],
                'port' => (int) $slave['port'],
            ];
        }

        // 沒有可用 slave 時把 master 加入清單，避免讀流量無處可去（dev/staging 可能只有 master，
        // 或 prod failover 期間所有 slave 都暫時 down 的邊界情境）。
        // 副作用：透過 resolveMaster() 而非 fetchMaster()，會把 master 寫進 masterCache，
        // 確保 fallback 用的 master ip 與下一次 resolveMaster() 視角一致。
        if (count($list) === 0) {
            $list[] = $this->resolveMaster($service);
        }

        return $list;
    }

    /**
     * Sentinel 的 flags 欄位是逗號分隔 token。本 helper 將其拆開後與 $downFlags 做集合相交。
     *
     * @param array<int, string> $downFlags
     */
    private static function flagsContainAnyOf(string $flags, array $downFlags): bool
    {
        $tokens = array_filter(array_map('trim', explode(',', $flags)));
        return array_intersect($downFlags, $tokens) !== [];
    }

    private function client(): SentinelClientInterface
    {
        if ($this->client === null) {
            $this->client = ($this->clientBuilder)();
        }

        return $this->client;
    }
}
