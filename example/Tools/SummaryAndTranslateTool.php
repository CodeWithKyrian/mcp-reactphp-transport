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

class SummaryAndTranslateTool
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Summarize and translate text (demonstrates multiple sequential sampling requests).
     *
     * @return array{original_length: int, summary: string, translation: string, target_language: string}
     */
    public function __invoke(string $text, string $targetLanguage, ClientGateway $client): array
    {
        $this->logger->info('[TOOL] Starting summarize and translate');

        $this->logger->info('[TOOL] Requesting summary');

        $summaryResponse = $client->sample(
            prompt: "Summarize the following text in 2-3 sentences: {$text}",
            maxTokens: 200
        );

        if ($summaryResponse instanceof Error) {
            throw new \RuntimeException(\sprintf('Summary request failed (%d): %s', $summaryResponse->code, $summaryResponse->message));
        }

        $summaryResult = $summaryResponse->result;
        $summaryContent = $summaryResult->content;
        $summary = $summaryContent instanceof TextContent ? trim((string) $summaryContent->text) : '';

        $this->logger->info('[TOOL] Got summary, now translating');

        $this->logger->info('[TOOL] Requesting translation');

        $translationResponse = $client->sample(
            prompt: "Translate the following text to {$targetLanguage}: {$summary}",
            maxTokens: 300
        );

        $this->logger->info('[TOOL] Translation complete');

        if ($translationResponse instanceof Error) {
            throw new \RuntimeException(\sprintf('Translation request failed (%d): %s', $translationResponse->code, $translationResponse->message));
        }

        $translationResult = $translationResponse->result;
        $translationContent = $translationResult->content;
        $translation = $translationContent instanceof TextContent ? trim((string) $translationContent->text) : '';

        return [
            'original_length' => \strlen($text),
            'summary' => $summary,
            'translation' => $translation,
            'target_language' => $targetLanguage,
        ];
    }
}
