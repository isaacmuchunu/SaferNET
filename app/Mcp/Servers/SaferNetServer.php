<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ProtectionSummaryTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('SAFERNET Operations')]
#[Version('1.0.0')]
#[Instructions('Read-only operational summaries for authorized SAFERNET support and administration. Never infer physical learner identity from device assignment alone.')]
class SaferNetServer extends Server
{
    protected array $tools = [
        ProtectionSummaryTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
