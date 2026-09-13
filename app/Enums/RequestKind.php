<?php

namespace App\Enums;

enum RequestKind: string
{
    case TopLevel = 'top_level';
    case Background = 'background';
}
