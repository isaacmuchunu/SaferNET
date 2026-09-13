<?php

namespace App\Enums;

enum EnforcementAction: string
{
    case Allow = 'allow';
    case Block = 'block';
    case Restrict = 'restrict';
    case Warn = 'warn';
}
