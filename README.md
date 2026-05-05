# Helper for Laravel Redis

![tests](https://github.com/104lab/laravel-redis/workflows/tests/badge.svg)

## Requirement

* PHP 8.1 ~ 8.4
* Laravel 10 ~ 12
* ext-redis 5.3 ~ 6.2 (Test covered)
* Redis 6 ~ 7 (Test covered)
* Predis ^2.0.3
* `Lab104\Laravel\Redis\Sentinel` namespace 需要 ext-redis >= 6.0（提供 `\RedisSentinel` array constructor）

## Installation

Use Composer for install.

```
composer require 104lab/laravel-redis
```

## Usage

### `KeysByScan`

Redis [`KEYS`](https://redis.io/docs/latest/commands/keys/) method is like full-table scan, so maybe use [`SCAN`](https://redis.io/docs/latest/commands/scan/) is good idea.

```php
$connection = Redis::connection();

# Before
$keys = $connection->keys('foo:*');

# After
$keys = (new KeysByScan($connection))('foo:*');

# Use chunk limit
$keys = (new KeysByScan($connection))('foo:*', 100);

# Use usleep, default is 10
$keys = (new KeysByScan($connection))('foo:*', 100, 10);
```

### `Sentinel` driver（Redis 高可用）

`Lab104\Laravel\Redis\Sentinel` 提供以 phpredis `\RedisSentinel` 為底的 Laravel Redis driver，
透過 Sentinel 集群入口（單一 LB endpoint 或多個 sentinel host）解析當前 master，連線失敗時自動重 query Sentinel 取得新 master。

> 完整機制、failover 時序、`slave=0` 邊界、設定範例、故障排除等，請見 [`doc/sentinel.md`](doc/sentinel.md)。

#### 註冊 driver

`SentinelServiceProvider` 透過 composer auto-discover 自動載入；無需手動 require。
若禁用 auto-discover，請在應用的 `config/app.php` 加上：

```php
'providers' => [
    // ...
    Lab104\Laravel\Redis\Sentinel\SentinelServiceProvider::class,
],
```

#### `config/database.php` 設定

```php
'redis' => [
    'client' => 'phpredis',

    'default' => [
        'driver'             => 'phpredis-sentinel',
        'service'            => env('REDIS_SENTINEL_SERVICE', 'mymaster'),
        'sentinels'          => array_map(
            fn (string $hp) => ['host' => explode(':', $hp)[0], 'port' => (int) (explode(':', $hp)[1] ?? 26379)],
            explode(',', env('REDIS_SENTINELS', 'sentinel.example.com:26379'))
        ),
        // sentinel_connect_timeout / sentinel_read_timeout 各自獨立；不設則 fallback 到 sentinel_timeout
        'sentinel_connect_timeout' => env('REDIS_SENTINEL_CONNECT_TIMEOUT'),
        'sentinel_read_timeout'    => env('REDIS_SENTINEL_READ_TIMEOUT'),
        'sentinel_timeout'         => env('REDIS_SENTINEL_TIMEOUT', 0.5),
        'sentinel_password'        => env('REDIS_SENTINEL_PASSWORD'),

        // 下列欄位為一般 phpredis driver 設定，會在透過 Sentinel 解析到 master 後一併套用
        'password'           => env('REDIS_PASSWORD'),
        'database'           => env('REDIS_DB', 0),
        'read_write_timeout' => env('REDIS_READ_WRITE_TIMEOUT', 0),
    ],
],
```

| 設定鍵 | 必填 | 說明 |
|---|---|---|
| `service` | ✅ | Sentinel master group 名稱（例：`mymaster`） |
| `sentinels` | ✅ | Sentinel 入口清單（單一 LB endpoint 或多個 Sentinel host）；按順序輪試直到成功 |
| `sentinel_connect_timeout` | | 連 Sentinel 的 connect timeout（秒）；不設則 fallback 到 `sentinel_timeout` |
| `sentinel_read_timeout` | | 連 Sentinel 的 read timeout（秒）；不設則 fallback 到 `sentinel_timeout` |
| `sentinel_timeout` | | `sentinel_connect_timeout` / `sentinel_read_timeout` 的共用 fallback（預設 0.5） |
| `sentinel_password` | | Sentinel 端 ACL 密碼（若啟用認證） |
| `sentinel_persistent` | | 是否使用 persistent 連線（預設關閉） |

> **建議**：「Sentinel 失聯偵測」與「Sentinel 查詢回應」是兩種特性，failover 演練時可分別調整 `sentinel_connect_timeout`（短）與 `sentinel_read_timeout`（略長）。一般情境用 `sentinel_timeout` 即可。

實際 master / slave 的 `password` / `database` / `prefix` / `read_write_timeout` 等沿用 Laravel `phpredis` driver 既有設定鍵。

#### Connection cache 失效策略

* `Resolver` 將每個 `service` 的 master / slaves 結果快取在 process in-memory；同一 service 後續解析直接 hit cache，不再 query Sentinel。
* 在連線失敗時（`PhpRedisConnection` 偵測到 `Connection lost` / `went away` / socket 錯誤等），Connector 會在重建 client 前呼叫 `Resolver::invalidate($service)` 清掉該 service 的快取，下次解析會重新 query Sentinel 取得新 master。
* 呼叫 `Resolver::invalidate()` 不帶參數時清空所有 service 並丟棄底層 `\RedisSentinel` client，用於 Sentinel 入口發生短暫失聯時的 hard reset 情境。

#### `slave list = 0` 邊界處理

當 Sentinel 回傳的 slave list 為 0（或全為 down）時，`Resolver::resolveSlaves()` 會自動把 master 加入 slave 清單，避免讀流量無處可去。

#### 進階：讀流量分流（optional）

本套件預設 `Redis::connection()` 走 master（保持 Laravel single-endpoint 慣例）。
若 service 端要讀 slave、含 slave=0 fallback master，可呼叫 `Resolver::resolveSlaves()` helper：

```php
use Lab104\Laravel\Redis\Sentinel\Resolver;

/** @var Resolver $resolver — 由 service 端建構或從 SentinelConnector 取得 */
$slaves = $resolver->resolveSlaves('mymaster');
// $slaves 為 [['host' => '...', 'port' => 6379], ...]
// Slave list 為 0 或全 down 時自動回 [master]

$node = $slaves[array_rand($slaves)];
$client = new \Redis();
try {
    $client->connect($node['host'], $node['port']);
    $value = $client->get('cache:foo');
} catch (\RedisException $e) {
    // Slave 連線失敗 fallback 到 master（透過既有 Laravel connection）
    $value = Redis::connection('default')->get('cache:foo');
}
```

業務模式：
- 有 Slave：優先用 Slave 讀
- 沒 Slave：清單只有 master、自動 fallback master
- Slave 連線失敗：catch 後 fallback master
- master / slave 連線失敗：Laravel `PhpRedisConnection` 機制觸發 connector closure 重 query Sentinel

#### Failover 預期行為

1. Sentinel 偵測到 master down → 觸發 failover → 推選新 master。
2. 應用既有 connection 對 master 的下一個指令會收到 `Connection lost` 等錯誤。
3. Laravel 的 `PhpRedisConnection::command()` 偵測到該錯誤後重建 client → 觸發本套件的 connector closure。
4. closure 呼叫 `Resolver::invalidate($service)` 後重 query Sentinel，拿到新 master 並重新建立 phpredis 連線。
5. 後續指令打到新 master，呼叫端透明（命令本身不會自動重試，需由業務層或 framework 端處理重送）。

> 此 driver 僅以單元測試覆蓋邊界邏輯（slave=0、cache 行為、reconnect 時 invalidate 等）。
> 實機 failover 演練、connection cache 與 long-running worker（如 Octane）的整合驗證請於應用端進行。

## License

The MIT License (MIT). Please see [License](LICENSE) File for more information.
