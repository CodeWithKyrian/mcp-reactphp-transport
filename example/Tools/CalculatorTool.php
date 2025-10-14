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

use Psr\Log\LoggerInterface;

class CalculatorTool
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Perform a calculation (demonstrates simple tool without bidirectional communication).
     *
     * @return array{operation: string, operands: array{int, int}, result: int, note: string}
     */
    public function __invoke(int $a, int $b, string $operation = 'add'): array
    {
        $result = match ($operation) {
            'add' => $a + $b,
            'subtract' => $a - $b,
            'multiply' => $a * $b,
            'divide' => 0 != $b ? $a / $b : throw new \InvalidArgumentException('Cannot divide by zero'),
            default => throw new \InvalidArgumentException("Unknown operation: {$operation}"),
        };

        return [
            'operation' => $operation,
            'operands' => [$a, $b],
            'result' => $result,
            'note' => 'This tool does not use bidirectional communication',
        ];
    }
}
