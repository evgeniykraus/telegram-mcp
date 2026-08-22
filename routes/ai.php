<?php

use App\Mcp\Servers\AnalyticsServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('analytics', AnalyticsServer::class);
