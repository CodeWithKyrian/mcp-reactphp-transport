<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once __DIR__.'/bootstrap.php';

use CodeWithKyrian\McpReactPhpTransport\Example\Tools\CalculatorTool;
use CodeWithKyrian\McpReactPhpTransport\Example\Tools\LoggingTool;
use CodeWithKyrian\McpReactPhpTransport\Example\Tools\SummaryAndTranslateTool;
use CodeWithKyrian\McpReactPhpTransport\Example\Tools\TextAnalysisTool;
use CodeWithKyrian\McpReactPhpTransport\ReactPhpHttpTransport;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server;

$capabilities = new ServerCapabilities(logging: true, tools: true, resources: false, prompts: false);

$server = Server::builder()
    ->setServerInfo('HTTP Sampling Demo', '1.0.0')
    ->setLogger(logger())
    ->setContainer(container())
    ->setCapabilities($capabilities)
    ->addTool(TextAnalysisTool::class, 'analyze_text')
    ->addTool(SummaryAndTranslateTool::class, 'summarize_and_translate')
    ->addTool(LoggingTool::class, 'logging_demo')
    ->addTool(CalculatorTool::class, 'calculator')
    ->build();

$transport = new ReactPhpHttpTransport(logger: logger());

$status = $server->run($transport);

exit($status);
