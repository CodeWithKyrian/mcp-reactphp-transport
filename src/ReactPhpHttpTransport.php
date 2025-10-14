<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CodeWithKyrian\McpReactPhpTransport;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Transport\BaseTransport;
use Mcp\Server\Transport\TransportInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Http\HttpServer;
use React\Http\Message\Response as HttpResponse;
use React\Socket\SocketServer;
use React\Stream\ThroughStream;
use Symfony\Component\Uid\Uuid;

/**
 * An asynchronous, multi-session, event-driven MCP transport using the ReactPHP ecosystem.
 *
 * @implements TransportInterface<int>
 */
class ReactPhpHttpTransport extends BaseTransport implements TransportInterface
{
    private const FIBER_STATUS_AWAITING_RESPONSE = 'awaiting_response';
    private const FIBER_STATUS_AWAITING_NOTIFICATION_RESUME = 'awaiting_notification_resume';

    private LoopInterface $loop;
    private ?SocketServer $socket = null;

    /** @var array<string, array{fiber: \Fiber, status: string}> */
    private array $managedFibers = [];

    /** @var array<string, ThroughStream> */
    private array $activeSseStreams = [];

    private ?string $immediateResponse = null;
    private ?int $immediateStatusCode = null;
    private ?TimerInterface $tickTimer = null;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8080,
        LoggerInterface $logger = new NullLogger(),
        ?LoopInterface $loop = null,
    ) {
        parent::__construct($logger);
        $this->loop = $loop ?? Loop::get();
    }

    public function listen(): int
    {
        $status = 0;
        $this->logger->info("Starting ReactPHP server on {$this->host}:{$this->port}...");

        $http = new HttpServer($this->loop, $this->handleHttpRequest(...));
        $this->socket = new SocketServer("{$this->host}:{$this->port}", [], $this->loop);
        $http->listen($this->socket);

        $this->socket->on('error', function (\Throwable $error) use (&$status) {
            $this->logger->error('ReactPHP socket error.', ['error' => $error->getMessage()]);
            $status = 1;
        });

        $this->logger->info('Server is listening. Running event loop.');
        $this->loop->run();

        return $status;
    }

    public function send(string $data, array $context): void
    {
        $this->immediateResponse = $data;
        $this->immediateStatusCode = $context['status_code'] ?? 500;
    }

    public function close(): void
    {
        $this->logger->info('Closing ReactPHP transport...');
        if ($this->tickTimer) {
            $this->loop->cancelTimer($this->tickTimer);
            $this->tickTimer = null;
        }
        foreach ($this->activeSseStreams as $stream) {
            $stream->end();
        }
        $this->activeSseStreams = [];
        $this->managedFibers = [];
        $this->socket?->close();
        $this->loop->stop();
    }

    public function attachFiberToSession(\Fiber $fiber, Uuid $sessionId): void
    {
        $sessionIdStr = $sessionId->toRfc4122();
        
        $this->managedFibers[$sessionIdStr] = ['fiber' => $fiber, 'status' => null];
        
        if (null === $this->tickTimer) {
            $this->logger->info('First managed fiber detected. Starting master tick timer.');
            $this->tickTimer = $this->loop->addPeriodicTimer(0.1, $this->tick(...));
        }
    }

    private function handleHttpRequest(ServerRequestInterface $request): HttpResponse
    {
        $this->immediateResponse = null;
        $this->immediateStatusCode = null;
        $this->sessionId = null;

        $sessionIdHeader = $request->getHeaderLine('Mcp-Session-Id');
        if ($sessionIdHeader) {
            try {
                $this->sessionId = Uuid::fromString($sessionIdHeader);
            } catch (\Throwable) {
                return $this->createErrorResponse(Error::forInvalidRequest('Invalid Mcp-Session-Id header format.'), 400);
            }
        }

        $corsHeaders = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Mcp-Session-Id, Last-Event-ID, Authorization, Accept',
        ];

        if ('OPTIONS' === $request->getMethod()) {
            return new HttpResponse(204, $corsHeaders);
        }

        $response = match ($request->getMethod()) {
            'POST' => $this->handlePostRequest($request),
            'DELETE' => $this->handleDeleteRequest(),
            default => $this->createErrorResponse(Error::forInvalidRequest('Method Not Allowed'), 405),
        };

        foreach ($corsHeaders as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private function handlePostRequest(ServerRequestInterface $request): HttpResponse
    {
        $body = $request->getBody()->getContents();
        $this->handleMessage($body, $this->sessionId);

        if (null !== $this->immediateResponse) {
            return $this->createJsonResponse($this->immediateResponse, $this->immediateStatusCode ?? 500);
        }

        if (null === $this->sessionId) {
            return $this->createErrorResponse(Error::forInternalError('Session could not be established.'), 500);
        }
        
        $sessionIdStr = $this->sessionId->toRfc4122();

        $outgoingMessages = $this->getOutgoingMessages($this->sessionId);

        if (empty($outgoingMessages)) {
            $this->logger->debug('No outgoing messages. Acknowledging with 202.', ['sessionId' => $sessionIdStr]);

            return new HttpResponse(202, ['Mcp-Session-Id' => $sessionIdStr]);
        }
        if (isset($this->managedFibers[$sessionIdStr])) {
            $this->logger->debug('Request has outgoing messages and a managed fiber. Opening SSE stream.', ['sessionId' => $sessionIdStr]);
            $stream = new ThroughStream();
            $this->activeSseStreams[$sessionIdStr] = $stream;
            $stream->on('close', fn () => $this->cleanupSseStream($sessionIdStr));
            $this->loop->futureTick(fn () => $this->processOutgoingMessages($sessionIdStr, $stream, $outgoingMessages));

            return new HttpResponse(200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
                'Mcp-Session-Id' => $sessionIdStr,
            ], $stream);
        }
        $this->logger->debug('Request has outgoing messages but no managed fiber. Replying with JSON.', ['sessionId' => $sessionIdStr]);
        $messages = array_column($outgoingMessages, 'message');
        $responseBody = 1 === \count($messages) ? $messages[0] : '['.implode(',', $messages).']';

        return $this->createJsonResponse($responseBody, 200, ['Mcp-Session-Id' => $sessionIdStr]);
    }

    private function handleDeleteRequest(): HttpResponse
    {
        if (!$this->sessionId) {
            return $this->createErrorResponse(Error::forInvalidRequest('Mcp-Session-Id header is required.'), 400);
        }
        $sessionIdStr = $this->sessionId->toRfc4122();
        $this->logger->info('Received DELETE for session.', ['sessionId' => $sessionIdStr]);
        $this->cleanupSseStream($sessionIdStr);
        unset($this->managedFibers[$sessionIdStr]);
        $this->handleSessionEnd($this->sessionId);

        return new HttpResponse(204);
    }

    private function cleanupSseStream(string $sessionIdStr): void
    {
        $this->logger->info('Cleaning up SSE stream resources.', ['sessionId' => $sessionIdStr]);
        if (isset($this->activeSseStreams[$sessionIdStr])) {
            $this->activeSseStreams[$sessionIdStr]->end();
            unset($this->activeSseStreams[$sessionIdStr]);
        }
    }

    private function tick(): void
    {
        foreach ($this->activeSseStreams as $sessionIdStr => $stream) {
            if (!$stream->isWritable()) {
                continue;
            }
            $sessionId = Uuid::fromString($sessionIdStr);
            $messages = $this->getOutgoingMessages($sessionId);
            $this->processOutgoingMessages($sessionIdStr, $stream, $messages);
        }
        foreach ($this->managedFibers as $sessionIdStr => $state) {
            $sessionId = Uuid::fromString($sessionIdStr);
            $this->processManagedFiber($state['fiber'], $state['status'], $sessionId);
        }
        if (empty($this->managedFibers) && $this->tickTimer) {
            $this->logger->info('No active managed fibers. Stopping master tick timer.');
            $this->loop->cancelTimer($this->tickTimer);
            $this->tickTimer = null;
        }
    }

    private function processOutgoingMessages(string $sessionIdStr, ThroughStream $stream, array $messages): void
    {
        foreach ($messages as $message) {
            if (isset($this->managedFibers[$sessionIdStr])) {
                $messageType = $message['context']['type'] ?? null;
                if ('request' === $messageType) {
                    $this->managedFibers[$sessionIdStr]['status'] = self::FIBER_STATUS_AWAITING_RESPONSE;
                } elseif ('notification' === $messageType) {
                    $this->managedFibers[$sessionIdStr]['status'] = self::FIBER_STATUS_AWAITING_NOTIFICATION_RESUME;
                }
            }
            $this->writeSseEvent($stream, $message['message']);
        }
    }

    private function processManagedFiber(\Fiber $fiber, ?string $status, Uuid $sessionId): void
    {
        $sessionIdStr = $sessionId->toRfc4122();
        if ($fiber->isTerminated()) {
            $this->handleFiberTermination($fiber, $sessionId);

            return;
        }
        if (!$fiber->isSuspended()) {
            return;
        }
        if (self::FIBER_STATUS_AWAITING_NOTIFICATION_RESUME === $status) {
            $yielded = $fiber->resume();
            $this->managedFibers[$sessionIdStr]['status'] = null;
            $this->handleFiberYield($yielded, $sessionId);

            return;
        }
        if (self::FIBER_STATUS_AWAITING_RESPONSE === $status) {
            foreach ($this->getPendingRequests($sessionId) as $pending) {
                $response = $this->checkForResponse($pending['request_id'], $sessionId);
                if (null !== $response) {
                    $yielded = $fiber->resume($response);
                    $this->managedFibers[$sessionIdStr]['status'] = null;
                    $this->handleFiberYield($yielded, $sessionId);
                    break;
                }
                $timeout = $pending['timeout'] ?? 120;
                if (time() - $pending['timestamp'] >= $timeout) {
                    $error = Error::forInternalError("Request timed out after {$timeout} seconds", $pending['request_id']);
                    $yielded = $fiber->resume($error);
                    $this->managedFibers[$sessionIdStr]['status'] = null;
                    $this->handleFiberYield($yielded, $sessionId);
                    break;
                }
            }
        }
    }

    private function handleFiberTermination(\Fiber $fiber, Uuid $sessionId): void
    {
        $sessionIdStr = $sessionId->toRfc4122();
        $finalResult = $fiber->getReturn();
        if (null !== $finalResult && isset($this->activeSseStreams[$sessionIdStr])) {
            try {
                $encoded = json_encode($finalResult, \JSON_THROW_ON_ERROR);
                $stream = $this->activeSseStreams[$sessionIdStr];
                if ($stream->isWritable()) {
                    $this->writeSseEvent($stream, $encoded);
                }
            } catch (\JsonException $e) {
                $this->logger->error('Failed to encode final Fiber result.', ['exception' => $e]);
            }
        }
        unset($this->managedFibers[$sessionIdStr]);
        $this->cleanupSseStream($sessionIdStr);
    }

    private function createJsonResponse(string $json, int $statusCode, array $headers = []): HttpResponse
    {
        $headers['Content-Type'] = 'application/json';

        return new HttpResponse($statusCode, $headers, $json);
    }

    private function createErrorResponse(Error $error, int $statusCode): HttpResponse
    {
        return $this->createJsonResponse(json_encode($error), $statusCode);
    }

    private function writeSseEvent(ThroughStream $stream, string $data): void
    {
        $frame = "event: message\n";
        foreach (explode("\n", $data) as $line) {
            $frame .= "data: {$line}\n";
        }
        $frame .= "\n";
        $stream->write($frame);
    }
}
