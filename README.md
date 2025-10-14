# MCP ReactPHP Transport

<!-- [![Latest Version on Packagist](https://img.shields.io/packagist/v/codewithkyrian/mcp-reactphp-transport.svg?style=flat-square)](https://packagist.org/packages/codewithkyrian/mcp-reactphp-transport)
[![Total Downloads](https://img.shields.io/packagist/dt/codewithkyrian/mcp-reactphp-transport.svg?style=flat-square)](https://packagist.org/packages/codewithkyrian/mcp-reactphp-transport)
[![License](https://img.shields.io/packagist/l/codewithkyrian/mcp-reactphp-transport.svg?style=flat-square)](LICENSE) -->

This package provides a high-performance, asynchronous, event-driven HTTP transport for the [official PHP MCP SDK](https://github.com/modelcontextprotocol/php-sdk).

It runs as a standalone, single process server, capable of handling multiple concurrent sessions and long-running, multi-yield tool calls without blocking. This makes it an ideal choice for high-concurrency environments or for developers who prefer an asynchronous programming model.

## Key Features

*   **Asynchronous & Non-Blocking:** Built on the ReactPHP event loop for superior I/O performance.
*   **Single Process:** Runs as a persistent, standalone server. No web server (like Nginx or Apache) is required.
*   **Multi-Session Support:** Correctly manages state and Fiber lifecycles for multiple concurrent client sessions.
*   **Community-Driven:** A robust transport option provided by the community.

## Installation

```bash
composer require codewithkyrian/mcp-reactphp-transport
```

## Quick Start

Create your MCP server script. This single script will start a persistent process that listens for HTTP requests.

**`server.php`**
```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Mcp\Server;
use CodeWithKyrian\McpReactPhpTransport\ReactPhpHttpTransport;

$server = Server::builder()
    ->setServerInfo('ReactPHP Async Server', '1.0.0')
    ->setDiscovery(__DIR__, ['src']) // Discover tools from a local src/ dir
    ->build();

$transport = new ReactPhpHttpTransport('127.0.0.1', 8080);

$server->run($transport);
```

### Running the Server

Start the server from your terminal:

```bash
php server.php
```

Your MCP server is now running and can be accessed by any MCP client configured to connect to `http://127.0.0.1:8080`.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.