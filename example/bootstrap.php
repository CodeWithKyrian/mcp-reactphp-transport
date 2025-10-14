<?php

use Mcp\Capability\Registry\Container;
use Psr\Log\LoggerInterface;
use Psr\Log\AbstractLogger;

require_once dirname(__DIR__).'/vendor/autoload.php';

function logger(): LoggerInterface
{
    return new FileLogger(__DIR__.'/dev.log');
}

function container(): Container
{
    $container = new Container();
    $container->set(LoggerInterface::class, logger());

    return $container;
}

class FileLogger extends AbstractLogger
{
    private $stream;

    public function __construct(string $logFile)
    {
        $dir = \dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        if (!file_exists($logFile)) {
            touch($logFile);
            chmod($logFile, 0666);
        }

        $this->stream = fopen($logFile, 'a');
        if (false === $this->stream) {
            throw new \RuntimeException("Could not open log file: $logFile");
        }
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $formatted = \sprintf(
            "[%s][%s] %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            empty($context) ? '' : json_encode($context)
        );

        fwrite($this->stream, $formatted);
    }

    public function __destruct()
    {
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}