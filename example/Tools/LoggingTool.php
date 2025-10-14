<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CodeWithKyrian\McpReactPhpTransport\Example\Tools;

use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Server\ClientGateway;
use Psr\Log\LoggerInterface;

class LoggingTool
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Send logging notifications to the client (demonstrates bidirectional communication).
     *
     * @return array{message: string, status: string, notifications_sent: int, note: string}
     */
    public function __invoke(string $message, ClientGateway $client): array
    {
        $this->logger->info('[TOOL] Starting logging demonstration');

        $this->logger->info('[TOOL] Sending log notification to client');

        $client->log(
            LoggingLevel::Info,
            [
                'message' => 'Processing logging request',
                'timestamp' => date('Y-m-d H:i:s'),
                'tool' => 'logging_demo',
            ]
        );

        usleep(100000); // 0.1 seconds

        $this->logger->info('[TOOL] Sending completion log notification');

        $client->log(
            LoggingLevel::Info,
            [
                'message' => 'Logging demonstration completed',
                'timestamp' => date('Y-m-d H:i:s'),
                'original_message' => $message,
                'status' => 'success',
            ]
        );

        $this->logger->info('[TOOL] Logging demonstration finished');

        return [
            'message' => $message,
            'status' => 'completed',
            'notifications_sent' => 2,
            'note' => 'This tool demonstrates sending logging notifications to the client via SSE',
        ];
    }
}
