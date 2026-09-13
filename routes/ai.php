<?php

use App\Mcp\Servers\SaferNetServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('safernet', SaferNetServer::class);
