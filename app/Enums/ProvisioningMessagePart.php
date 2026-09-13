<?php

namespace App\Enums;

enum ProvisioningMessagePart: string
{
    case Welcome = 'welcome';
    case Username = 'username';
    case TemporaryPassword = 'temporary_password';
}
