<?php

namespace App\Models;

/**
 * School is a domain model alias for Institution, representing K-12 academic
 * schools and institutions registered within the county.
 */
class School extends Institution
{
    protected $table = 'institutions';
}
