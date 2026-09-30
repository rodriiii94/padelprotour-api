<?php

use App\Mcp\Servers\PadelProTourServer;
use Laravel\Mcp\Facades\Mcp;

// Mismo token de Sanctum que usa la app: `Authorization: Bearer <token>`.
Mcp::web('/mcp', PadelProTourServer::class)->middleware(['auth:sanctum', 'throttle:60,1']);
