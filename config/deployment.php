<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Component freshness
    |--------------------------------------------------------------------------
    |
    | A component is only as healthy as its last contact. These thresholds turn
    | silence into a status: past `stale_after_minutes` a component is degraded,
    | and past `offline_after_minutes` it is offline regardless of the health it
    | last claimed. Without them, a device that stopped checking in keeps
    | contributing to the healthy total forever.
    |
    | They are applied when the console reads, so the answer is correct between
    | scheduled runs, and written back by `safernet:expire-stale-components`.
    |
    */

    'stale_after_minutes' => (int) env('DEPLOYMENT_STALE_AFTER_MINUTES', 30),

    'offline_after_minutes' => (int) env('DEPLOYMENT_OFFLINE_AFTER_MINUTES', 180),

    /*
    |--------------------------------------------------------------------------
    | Policy freshness
    |--------------------------------------------------------------------------
    |
    | How long a component may go without successfully installing a policy
    | before it stops counting as healthy. A browser that reaches the API but
    | fails every sync is not enforcing the current policy, however reliably its
    | heartbeat arrives.
    |
    */

    'policy_stale_after_minutes' => (int) env('DEPLOYMENT_POLICY_STALE_AFTER_MINUTES', 120),

];
