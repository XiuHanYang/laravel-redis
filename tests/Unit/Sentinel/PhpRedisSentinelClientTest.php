<?php

declare(strict_types=1);

namespace Tests\Unit\Sentinel;

use InvalidArgumentException;
use Lab104\Laravel\Redis\Sentinel\PhpRedisSentinelClient;
use Lab104\Laravel\Redis\Sentinel\SentinelException;
use PHPUnit\Framework\Attributes\Test;
use RedisSentinel;
use RuntimeException;
use Tests\TestCase;

class PhpRedisSentinelClientTest extends TestCase
{
    #[Test]
    public function itRejectsEmptySentinelList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sentinel host list must not be empty');

        new PhpRedisSentinelClient([]);
    }

    #[Test]
    public function itRejectsSentinelEntryWithoutHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("sentinels[0]");

        new PhpRedisSentinelClient([['port' => 26379]]);
    }

    #[Test]
    public function itRejectsSentinelEntryWithEmptyHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("sentinels[0]");

        new PhpRedisSentinelClient([['host' => '', 'port' => 26379]]);
    }

    #[Test]
    public function itReportsIndexInValidationErrorMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("sentinels[1]");

        new PhpRedisSentinelClient([
            ['host' => 'sentinel-a', 'port' => 26379],
            ['port' => 26379],  // 缺 host
        ]);
    }

    #[Test]
    public function itTriesAllSentinelsAndChainsLastErrorWhenAllFail(): void
    {
        // 所有 sentinels 全失聯時要丟出明確錯誤、不 hang；previous 保留最後一次 underlying error。
        $client = new class ([
            ['host' => 'sentinel-a', 'port' => 26379],
            ['host' => 'sentinel-b', 'port' => 26379],
            ['host' => 'sentinel-c', 'port' => 26379],
        ]) extends PhpRedisSentinelClient {
            /** @var array<int, string> */
            public array $attempted = [];

            protected function createClient(array $sentinel): RedisSentinel
            {
                $this->attempted[] = $sentinel['host'];
                throw new RuntimeException("cannot connect to {$sentinel['host']}");
            }
        };

        try {
            $client->master('mymaster');
            $this->fail('expected SentinelException');
        } catch (SentinelException $e) {
            $this->assertSame(SentinelException::CONNECTION_ERROR, $e->getCode());
            $this->assertStringContainsString('cannot connect to sentinel-c', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
            $this->assertSame(
                ['sentinel-a', 'sentinel-b', 'sentinel-c'],
                $client->attempted,
                'should iterate sentinels in order until all fail'
            );
        }
    }

    #[Test]
    public function itReturnsFirstReachableSentinelClient(): void
    {
        $stubClient = $this->createMock(RedisSentinel::class);
        $stubClient->method('master')->willReturn([
            'ip' => '10.0.0.1', 'port' => 6379, 'flags' => 'master', 'role-reported' => 'master',
        ]);

        $client = new class (
            [
                ['host' => 'sentinel-a', 'port' => 26379],
                ['host' => 'sentinel-b', 'port' => 26379],
            ],
            [],
            $stubClient
        ) extends PhpRedisSentinelClient {
            /** @var array<int, string> */
            public array $attempted = [];
            private RedisSentinel $stub;

            public function __construct(array $sentinels, array $options, RedisSentinel $stub)
            {
                parent::__construct($sentinels, $options);
                $this->stub = $stub;
            }

            protected function createClient(array $sentinel): RedisSentinel
            {
                $this->attempted[] = $sentinel['host'];
                if ($sentinel['host'] === 'sentinel-a') {
                    throw new RuntimeException('first sentinel down');
                }
                return $this->stub;
            }
        };

        $info = $client->master('mymaster');

        $this->assertIsArray($info);
        $this->assertSame('10.0.0.1', $info['ip']);
        $this->assertSame(['sentinel-a', 'sentinel-b'], $client->attempted);
    }
}
