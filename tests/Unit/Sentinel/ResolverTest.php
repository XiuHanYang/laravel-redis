<?php

declare(strict_types=1);

namespace Tests\Unit\Sentinel;

use Lab104\Laravel\Redis\Sentinel\Resolver;
use Lab104\Laravel\Redis\Sentinel\SentinelClientInterface;
use Lab104\Laravel\Redis\Sentinel\SentinelException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolverTest extends TestCase
{
    /**
     * @return SentinelClientInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeClient(): SentinelClientInterface
    {
        return $this->createMock(SentinelClientInterface::class);
    }

    /**
     * 建立一個 Resolver；其 lazy 建立的 client 固定為傳入的 mock。
     * `$builderCalls` 用來檢查 lazy 建立行為（第幾次呼叫才實際建立 client）。
     */
    private function makeResolver(SentinelClientInterface $client, ?int &$builderCalls = null): Resolver
    {
        $builderCalls = 0;
        return new Resolver(function () use ($client, &$builderCalls): SentinelClientInterface {
            $builderCalls++;
            return $client;
        });
    }

    #[Test]
    public function itResolvesMasterWithCorrectHostAndPort(): void
    {
        $client = $this->makeClient();
        $client->expects($this->once())
            ->method('master')
            ->with('mymaster')
            ->willReturn([
                'ip' => '10.0.0.1',
                'port' => '6379',
                'flags' => 'master',
                'role-reported' => 'master',
            ]);

        $resolver = $this->makeResolver($client);

        $this->assertSame(
            ['host' => '10.0.0.1', 'port' => 6379],
            $resolver->resolveMaster('mymaster')
        );
    }

    #[Test]
    public function itCachesMasterResolutionOnSubsequentCalls(): void
    {
        $client = $this->makeClient();
        $client->expects($this->once())
            ->method('master')
            ->willReturn([
                'ip' => '10.0.0.1',
                'port' => 6379,
                'flags' => 'master',
                'role-reported' => 'master',
            ]);

        $resolver = $this->makeResolver($client);
        $resolver->resolveMaster('mymaster');
        $resolver->resolveMaster('mymaster');
    }

    #[Test]
    public function itResolvesSlavesWithCorrectHostAndPort(): void
    {
        $client = $this->makeClient();
        $client->method('slaves')
            ->with('mymaster')
            ->willReturn([
                ['ip' => '10.0.0.2', 'port' => '6379', 'flags' => 'slave'],
                ['ip' => '10.0.0.3', 'port' => 6380, 'flags' => 'slave'],
            ]);

        $resolver = $this->makeResolver($client);

        $this->assertSame(
            [
                ['host' => '10.0.0.2', 'port' => 6379],
                ['host' => '10.0.0.3', 'port' => 6380],
            ],
            $resolver->resolveSlaves('mymaster')
        );
    }

    #[Test]
    public function itFiltersOutDownSlaves(): void
    {
        $client = $this->makeClient();
        $client->method('slaves')->willReturn([
            ['ip' => '10.0.0.2', 'port' => 6379, 'flags' => 'slave'],
            ['ip' => '10.0.0.3', 'port' => 6379, 'flags' => 's_down,slave'],
            ['ip' => '10.0.0.4', 'port' => 6379, 'flags' => 'slave,o_down'],
        ]);

        $resolver = $this->makeResolver($client);

        $this->assertSame(
            [['host' => '10.0.0.2', 'port' => 6379]],
            $resolver->resolveSlaves('mymaster')
        );
    }

    #[Test]
    public function itFallsBackToMasterWhenSlaveListIsEmpty(): void
    {
        $client = $this->makeClient();
        $client->method('master')->willReturn([
            'ip' => '10.0.0.1',
            'port' => 6379,
            'flags' => 'master',
            'role-reported' => 'master',
        ]);
        $client->method('slaves')->willReturn([]);

        $resolver = $this->makeResolver($client);

        $this->assertSame(
            [['host' => '10.0.0.1', 'port' => 6379]],
            $resolver->resolveSlaves('mymaster')
        );
    }

    #[Test]
    public function itFallsBackToMasterWhenAllSlavesAreDown(): void
    {
        $client = $this->makeClient();
        $client->method('master')->willReturn([
            'ip' => '10.0.0.1',
            'port' => 6379,
            'flags' => 'master',
            'role-reported' => 'master',
        ]);
        $client->method('slaves')->willReturn([
            ['ip' => '10.0.0.2', 'port' => 6379, 'flags' => 's_down'],
            ['ip' => '10.0.0.3', 'port' => 6379, 'flags' => 'o_down'],
        ]);

        $resolver = $this->makeResolver($client);

        $this->assertSame(
            [['host' => '10.0.0.1', 'port' => 6379]],
            $resolver->resolveSlaves('mymaster')
        );
    }

    #[Test]
    public function itFallsBackToMasterWhenSentinelReturnsFalseForSlaves(): void
    {
        $client = $this->makeClient();
        $client->method('master')->willReturn([
            'ip' => '10.0.0.1',
            'port' => 6379,
            'flags' => 'master',
            'role-reported' => 'master',
        ]);
        $client->method('slaves')->willReturn(false);

        $resolver = $this->makeResolver($client);

        $this->assertSame(
            [['host' => '10.0.0.1', 'port' => 6379]],
            $resolver->resolveSlaves('mymaster')
        );
    }

    #[Test]
    public function itThrowsWhenMasterNotFound(): void
    {
        $client = $this->makeClient();
        $client->method('master')->willReturn(false);

        $resolver = $this->makeResolver($client);

        $this->expectException(SentinelException::class);
        $this->expectExceptionMessage('master group [mymaster] not found');

        $resolver->resolveMaster('mymaster');
    }

    #[Test]
    public function itThrowsWhenMasterFlaggedDown(): void
    {
        $client = $this->makeClient();
        $client->method('master')->willReturn([
            'ip' => '10.0.0.1',
            'port' => 6379,
            'flags' => 'master,s_down,o_down',
            'role-reported' => 'master',
        ]);

        $resolver = $this->makeResolver($client);

        $this->expectException(SentinelException::class);
        $this->expectExceptionMessage('reported down');

        $resolver->resolveMaster('mymaster');
    }

    #[Test]
    public function itThrowsWhenMasterIsDisconnected(): void
    {
        $client = $this->makeClient();
        $client->method('master')->willReturn([
            'ip' => '10.0.0.1',
            'port' => 6379,
            'flags' => 'master,disconnected',
            'role-reported' => 'master',
        ]);

        $resolver = $this->makeResolver($client);

        $this->expectException(SentinelException::class);
        $this->expectExceptionMessage('reported down');

        $resolver->resolveMaster('mymaster');
    }

    #[Test]
    public function itDoesNotMistakeUnrelatedDownSubstringFlagsAsMasterDown(): void
    {
        // 假想未來 Sentinel 加入 'shutdown_pending' / 'xxx_countdown' 等含 down 子字串但非 master down 的 flag；
        // token-based 比對應該不誤判為 down。
        $client = $this->makeClient();
        $client->method('master')->willReturn([
            'ip' => '10.0.0.1',
            'port' => 6379,
            'flags' => 'master,shutdown_pending,countdown_42',
            'role-reported' => 'master',
        ]);

        $resolver = $this->makeResolver($client);

        $this->assertSame(
            ['host' => '10.0.0.1', 'port' => 6379],
            $resolver->resolveMaster('mymaster')
        );
    }

    #[Test]
    public function itFiltersOutSlavesWithMasterLinkDown(): void
    {
        // master_link_down 表示 slave 與 master 連線斷、資料可能落後，不該被當作可讀節點。
        $client = $this->makeClient();
        $client->method('slaves')->willReturn([
            ['ip' => '10.0.0.2', 'port' => 6379, 'flags' => 'slave'],
            ['ip' => '10.0.0.3', 'port' => 6379, 'flags' => 'slave,master_link_down'],
        ]);

        $resolver = $this->makeResolver($client);

        $this->assertSame(
            [['host' => '10.0.0.2', 'port' => 6379]],
            $resolver->resolveSlaves('mymaster')
        );
    }

    #[Test]
    public function itThrowsWhenMasterRoleIsNotMaster(): void
    {
        $client = $this->makeClient();
        $client->method('master')->willReturn([
            'ip' => '10.0.0.1',
            'port' => 6379,
            'flags' => 'master',
            'role-reported' => 'slave',
        ]);

        $resolver = $this->makeResolver($client);

        $this->expectException(SentinelException::class);
        $this->expectExceptionMessage("role must be 'master'");

        $resolver->resolveMaster('mymaster');
    }

    #[Test]
    public function itInvalidatesMasterCacheForSpecificService(): void
    {
        $client = $this->makeClient();
        $client->expects($this->exactly(2))
            ->method('master')
            ->willReturn([
                'ip' => '10.0.0.1',
                'port' => 6379,
                'flags' => 'master',
                'role-reported' => 'master',
            ]);

        $resolver = $this->makeResolver($client);
        $resolver->resolveMaster('mymaster');
        $resolver->invalidate('mymaster');
        $resolver->resolveMaster('mymaster');
    }

    #[Test]
    public function itInvalidatesAllCaches(): void
    {
        $client = $this->makeClient();
        $client->expects($this->exactly(2))
            ->method('master')
            ->willReturn([
                'ip' => '10.0.0.1',
                'port' => 6379,
                'flags' => 'master',
                'role-reported' => 'master',
            ]);

        $resolver = $this->makeResolver($client);
        $resolver->resolveMaster('mymaster');
        $resolver->invalidate();
        $resolver->resolveMaster('mymaster');
    }

    #[Test]
    public function itCachesSlavesResolution(): void
    {
        $client = $this->makeClient();
        $client->expects($this->once())
            ->method('slaves')
            ->willReturn([
                ['ip' => '10.0.0.2', 'port' => 6379, 'flags' => 'slave'],
            ]);

        $resolver = $this->makeResolver($client);
        $resolver->resolveSlaves('mymaster');
        $resolver->resolveSlaves('mymaster');
    }

    #[Test]
    public function itInvalidatesSlavesCacheForSpecificService(): void
    {
        $client = $this->makeClient();
        $client->expects($this->exactly(2))
            ->method('slaves')
            ->willReturn([
                ['ip' => '10.0.0.2', 'port' => 6379, 'flags' => 'slave'],
            ]);

        $resolver = $this->makeResolver($client);
        $resolver->resolveSlaves('mymaster');
        $resolver->invalidate('mymaster');
        $resolver->resolveSlaves('mymaster');
    }

    #[Test]
    public function itKeepsOtherServiceCacheWhenInvalidatingOne(): void
    {
        $masterInfo = [
            'mymaster' => [
                'ip' => '10.0.0.1',
                'port' => 6379,
                'flags' => 'master',
                'role-reported' => 'master',
            ],
            'replica' => [
                'ip' => '10.0.0.5',
                'port' => 6379,
                'flags' => 'master',
                'role-reported' => 'master',
            ],
        ];

        $observed = [];
        $client = $this->makeClient();
        $client->method('master')->willReturnCallback(function (string $service) use ($masterInfo, &$observed) {
            $observed[] = $service;
            return $masterInfo[$service];
        });

        $resolver = $this->makeResolver($client);
        $resolver->resolveMaster('mymaster');
        $resolver->resolveMaster('replica');
        $resolver->invalidate('mymaster');
        $resolver->resolveMaster('mymaster');  // 重新取
        $resolver->resolveMaster('replica');  // 快取仍有效

        $this->assertSame(['mymaster', 'replica', 'mymaster'], $observed);
    }

    #[Test]
    public function itLazilyCreatesSentinelClient(): void
    {
        $client = $this->makeClient();
        $client->method('master')->willReturn([
            'ip' => '10.0.0.1',
            'port' => 6379,
            'flags' => 'master',
            'role-reported' => 'master',
        ]);

        $builderCalls = 0;
        $resolver = $this->makeResolver($client, $builderCalls);

        $this->assertSame(0, $builderCalls);
        $resolver->resolveMaster('mymaster');
        $this->assertSame(1, $builderCalls);
        $resolver->resolveMaster('mymaster');
        $this->assertSame(1, $builderCalls);
    }

    #[Test]
    public function itRecreatesSentinelClientAfterFullInvalidateWhenCacheWasUsed(): void
    {
        $client = $this->makeClient();
        $client->method('master')->willReturn([
            'ip' => '10.0.0.1',
            'port' => 6379,
            'flags' => 'master',
            'role-reported' => 'master',
        ]);

        $builderCalls = 0;
        $resolver = $this->makeResolver($client, $builderCalls);
        $resolver->resolveMaster('mymaster');

        $this->assertSame(1, $builderCalls);

        $resolver->invalidate();
        $resolver->resolveMaster('mymaster');

        // 不帶參數的 invalidate 同時丟棄底層 sentinel client，讓下一次呼叫重新建立連線；
        // 用於 Sentinel 入口發生短暫失聯時的 hard reset 情境。
        $this->assertSame(2, $builderCalls);
    }

    #[Test]
    public function itAllowsRetryWhenClientBuilderThrowsInitially(): void
    {
        // 「啟動時 Sentinel 暫時不通、稍後恢復」情境：第一次 builder 拋例外、Resolver 不應 cache 失敗狀態，
        // 下次呼叫該重新嘗試 builder。
        $attempts = 0;
        $client = $this->makeClient();
        $client->method('master')->willReturn([
            'ip' => '10.0.0.1',
            'port' => 6379,
            'flags' => 'master',
            'role-reported' => 'master',
        ]);

        $resolver = new Resolver(function () use (&$attempts, $client): SentinelClientInterface {
            $attempts++;
            if ($attempts === 1) {
                throw new SentinelException('first attempt fail', SentinelException::CONNECTION_ERROR);
            }
            return $client;
        });

        try {
            $resolver->resolveMaster('mymaster');
            $this->fail('first call should throw');
        } catch (SentinelException $e) {
            $this->assertSame('first attempt fail', $e->getMessage());
        }

        $this->assertSame(
            ['host' => '10.0.0.1', 'port' => 6379],
            $resolver->resolveMaster('mymaster')
        );
        $this->assertSame(2, $attempts);
    }
}
