<?php

declare(strict_types=1);

namespace Tests\Unit\Sentinel;

use InvalidArgumentException;
use Lab104\Laravel\Redis\Sentinel\ResolverInterface;
use Lab104\Laravel\Redis\Sentinel\SentinelConnector;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SentinelConnectorTest extends TestCase
{
    #[Test]
    public function masterResolverClosureDoesNotInvalidateOnFirstCall(): void
    {
        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->never())->method('invalidate');
        $resolver->expects($this->once())
            ->method('resolveMaster')
            ->with('mymaster')
            ->willReturn(['host' => '10.0.0.1', 'port' => 6379]);

        $closure = SentinelConnector::makeMasterResolverClosure($resolver, 'mymaster');
        $this->assertSame(['host' => '10.0.0.1', 'port' => 6379], $closure());
    }

    #[Test]
    public function masterResolverClosureInvalidatesOnSubsequentCalls(): void
    {
        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))
            ->method('invalidate')
            ->with('mymaster');
        $resolver->expects($this->exactly(3))
            ->method('resolveMaster')
            ->with('mymaster')
            ->willReturn(['host' => '10.0.0.1', 'port' => 6379]);

        $closure = SentinelConnector::makeMasterResolverClosure($resolver, 'mymaster');
        $closure();  // 第一次：不 invalidate
        $closure();  // 第二次：invalidate
        $closure();  // 第三次：invalidate
    }

    #[Test]
    public function eachClosureTracksAttemptsIndependently(): void
    {
        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->method('resolveMaster')->willReturn(['host' => '10.0.0.1', 'port' => 6379]);

        // 每個 closure 各自管自己的 attempts 計數，不會互相污染：即使共用同一個 resolver，
        // 不同 service 的 closure 第一次呼叫都不該觸發 invalidate。
        $resolver->expects($this->never())->method('invalidate');

        $closureA = SentinelConnector::makeMasterResolverClosure($resolver, 'mymaster');
        $closureB = SentinelConnector::makeMasterResolverClosure($resolver, 'replica');
        $closureA();
        $closureB();
    }

    #[Test]
    public function connectRejectsMissingService(): void
    {
        $connector = new SentinelConnector();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'service'");

        $connector->connect(
            ['sentinels' => [['host' => '10.0.0.1', 'port' => 26379]]],
            []
        );
    }

    #[Test]
    public function connectRejectsMissingSentinels(): void
    {
        $connector = new SentinelConnector();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'sentinels'");

        $connector->connect(['service' => 'mymaster'], []);
    }

    #[Test]
    public function connectRejectsEmptySentinels(): void
    {
        $connector = new SentinelConnector();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sentinel host list must not be empty');

        $connector->connect(['service' => 'mymaster', 'sentinels' => []], []);
    }

    #[Test]
    public function connectReportsBadSentinelEntryWithIndex(): void
    {
        $connector = new SentinelConnector();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sentinels[1]');

        $connector->connect(
            [
                'service' => 'mymaster',
                'sentinels' => [
                    ['host' => 'sentinel-a', 'port' => 26379],
                    ['port' => 26379],  // 缺 host
                ],
            ],
            []
        );
    }
}
