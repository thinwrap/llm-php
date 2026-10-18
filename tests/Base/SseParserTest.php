<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Tests\Base;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Thinwrap\Llm\Base\BaseConnector;
use Thinwrap\Llm\Enum\ProviderCode;
use Thinwrap\Llm\Exception\ConnectorError;
use Thinwrap\Llm\Tests\Support\ChunkedStream;

/** Exposes the protected SSE parser for direct testing. */
final class SseHarness extends BaseConnector
{
    /**
     * @return list<array<mixed>>
     */
    public function drain(StreamInterface $body): array
    {
        return iterator_to_array($this->parseSSEStream($body), false);
    }

    /**
     * @return \Generator<int,array<mixed>>
     */
    public function open(StreamInterface $body): \Generator
    {
        return $this->parseSSEStream($body);
    }
}

/** A stream that records whether {@see close()} was called, delegating the rest. */
final class ClosableStream implements StreamInterface
{
    public bool $closed = false;
    private StreamInterface $inner;

    public function __construct(string $content)
    {
        $this->inner = (new Psr17Factory())->createStream($content);
    }

    public function close(): void
    {
        $this->closed = true;
        $this->inner->close();
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }

    public function write(string $string): int
    {
        return $this->inner->write($string);
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function read(int $length): string
    {
        return $this->inner->read($length);
    }

    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }
}

/**
 * A stream that emits one chunk then throws on the next read — a mid-stream TCP
 * reset / broken PSR-7 body.
 */
final class ThrowingStream implements StreamInterface
{
    private int $reads = 0;

    public function read(int $length): string
    {
        if ($this->reads++ === 0) {
            return "data: {\"n\":1}\n\n";
        }

        throw new \RuntimeException('connection reset by peer');
    }

    public function eof(): bool
    {
        return false;
    }

    public function close(): void {}

    public function __toString(): string
    {
        return '';
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void {}

    public function rewind(): void {}

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        return 0;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata(?string $key = null)
    {
        return null;
    }
}

final class SseParserTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function testMidStreamReadFailureBecomesConnectorError(): void
    {
        $seen = [];
        try {
            foreach ((new SseHarness())->open(new ThrowingStream()) as $item) {
                $seen[] = $item;
            }
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            self::assertSame([['n' => 1]], $seen); // the good event before the drop surfaced
        }
    }

    public function testSplitsOnCrOnlyAndMixedTerminators(): void
    {
        // \r\r (CR-only) and \n\r\n (mixed) boundaries
        $sse = "data: {\"n\":1}\r\rdata: {\"n\":2}\n\r\ndata: [DONE]\r\r";
        $out = (new SseHarness())->drain($this->factory->createStream($sse));

        $this->assertSame([['n' => 1], ['n' => 2]], $out);
    }

    public function testYieldsJsonPayloadsAndStopsAtDone(): void
    {
        $sse = "data: {\"n\":1}\n\ndata: {\"n\":2}\n\ndata: [DONE]\n\ndata: {\"n\":3}\n\n";
        $out = (new SseHarness())->drain($this->factory->createStream($sse));

        $this->assertSame([['n' => 1], ['n' => 2]], $out);
    }

    public function testFlushesResidualTailWithoutTrailingBoundary(): void
    {
        $sse = "data: {\"n\":1}\n\ndata: {\"n\":2}";
        $out = (new SseHarness())->drain($this->factory->createStream($sse));

        $this->assertSame([['n' => 1], ['n' => 2]], $out);
    }

    public function testThrowsWhenSingleEventExceedsBufferCap(): void
    {
        $huge = 'data: ' . str_repeat('x', 16 * 1024 * 1024 + 100);

        $this->expectException(ConnectorError::class);
        (new SseHarness())->drain($this->factory->createStream($huge));
    }

    public function testClosesBodyAfterFullDrain(): void
    {
        $stream = new ClosableStream("data: {\"n\":1}\n\ndata: {\"n\":2}\n\ndata: [DONE]\n\n");
        (new SseHarness())->drain($stream);

        $this->assertTrue($stream->closed);
    }

    public function testClosesBodyOnEarlyBreak(): void
    {
        $stream = new ClosableStream("data: {\"n\":1}\n\ndata: {\"n\":2}\n\ndata: {\"n\":3}\n\n");

        $seen = [];
        $gen = (new SseHarness())->open($stream);
        foreach ($gen as $item) {
            $seen[] = $item;

            break; // abandon the stream after the first event
        }
        unset($gen); // drop the last reference → the generator's finally runs

        $this->assertSame([['n' => 1]], $seen);
        $this->assertTrue($stream->closed);
    }

    public function testSingleCrlfInsideAnEventIsNotABoundary(): void
    {
        // One event, two `data:` lines joined by a single CRLF: the payload is the
        // lines joined with "\n". Treating that CRLF as "CR, then LF" split the
        // event into two halves that are not JSON, and both were dropped.
        $sse = "data: {\"a\":\r\ndata: 1}\r\n\r\ndata: {\"n\":2}\r\n\r\n";
        $out = (new SseHarness())->drain($this->factory->createStream($sse));

        $this->assertSame([['a' => 1], ['n' => 2]], $out);
    }

    public function testEventsSplitAcrossReadsAreReassembled(): void
    {
        $sse = "data: {\"a\":\r\ndata: 1}\r\n\r\ndata: {\"n\":2}\n\ndata: {\"n\":3}\r\rdata: [DONE]\n\n";
        foreach ([1, 2, 3, 5, 7] as $size) {
            $out = (new SseHarness())->drain(new ChunkedStream($sse, $size));
            $this->assertSame([['a' => 1], ['n' => 2], ['n' => 3]], $out, "chunk size {$size}");
        }
    }

    public function testReturnValueReportsWhetherTheStreamEndedOnDone(): void
    {
        $cases = [
            "data: {\"n\":1}\n\ndata: [DONE]\n\n" => true,
            "data: {\"n\":1}\n\ndata: [DONE]" => true,
            "data: {\"n\":1}\n\n" => false,
            '' => false,
        ];
        foreach ($cases as $sse => $expected) {
            $gen = (new SseHarness())->open(new ChunkedStream($sse, 3));
            foreach ($gen as $_) {
            }
            $this->assertSame($expected, $gen->getReturn(), json_encode($sse, JSON_THROW_ON_ERROR));
        }
    }
}
