<?php

use App\Mcp\Servers\AnalyticsServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/analytics', AnalyticsServer::class);
