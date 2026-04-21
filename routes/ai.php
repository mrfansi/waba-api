<?php

use Binaryk\LaravelRestify\MCP\RestifyServer;
use Laravel\Mcp\Server\Facades\Mcp;

// Restify MCP Server - provides AI agents access to your Restify repositories
// Mcp::web('restify', RestifyServer::class)
//     ->middleware(['auth:sanctum']); // Available at /mcp/restify

// Mcp::local('restify', RestifyServer::class); // Start with ./artisan mcp:start restify

// Example custom servers:
// Mcp::web('demo', \App\Mcp\Servers\PublicServer::class); // Available at /mcp/demo
// Mcp::local('demo', \App\Mcp\Servers\LocalServer::class); // Start with ./artisan mcp:start demo
