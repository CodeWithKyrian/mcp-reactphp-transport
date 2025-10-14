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

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\ClientGateway;
use Psr\Log\LoggerInterface;

class TextAnalysisTool
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Analyze text using LLM sampling (demonstrates bidirectional communication).
     *
     * @return array{analysis: string, model: string, original_text: string, text_length: int}
     */
    public function __invoke(string $text, ClientGateway $client): array
    {
        $this->logger->info('[TOOL] Starting text analysis with LLM sampling');

        $this->logger->info('[TOOL] Sending sampling request to client');

        $response = $client->sample(
            prompt: "Analyze the following text and provide insights: {$text}",
            maxTokens: 500,
            options: ['temperature' => 0.7]
        );

        $this->logger->info('[TOOL] Received LLM response, processing result', ['response' => $response]);

        if ($response instanceof Error) {
            throw new \RuntimeException(\sprintf('Sampling failed (%d): %s', $response->code, $response->message));
        }

        $result = $response->result;

        $analysisContent = $result->content;
        $analysisText = $analysisContent instanceof TextContent ? trim((string) $analysisContent->text) : '';
        $analysisText = '' !== $analysisText ? $analysisText : 'No response';

        return [
            'original_text' => $text,
            'text_length' => \strlen($text),
            'analysis' => $analysisText,
            'model' => $result->model,
        ];
    }
}
