<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Live monitor
    |--------------------------------------------------------------------------
    |
    | The live classroom page polls while a lesson is in progress, so every
    | query behind it is bounded deliberately.
    |
    | `tile_limit` caps how many workstations are rendered at once. The focus
    | score is counted over `focus_window_minutes` rather than the whole
    | session: a live monitor should describe the lesson happening now, and an
    | all-time count both drifts and grows without limit.
    |
    */

    'tile_limit' => (int) env('CLASSROOM_TILE_LIMIT', 48),

    'focus_window_minutes' => (int) env('CLASSROOM_FOCUS_WINDOW_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Reporting freshness
    |--------------------------------------------------------------------------
    |
    | How recently a workstation must have reported activity to count as live.
    | A tile that has gone quiet for longer shows as not reporting: the page it
    | last displayed is the last thing that was *seen*, not necessarily what is
    | on screen now, and a monitor that cannot tell those apart lets a quiet
    | room read as a compliant one.
    |
    | Kept a little above the extension's 30-second command poll so an ordinary
    | gap between events does not flicker the indicator.
    |
    */

    'reporting_within_seconds' => (int) env('CLASSROOM_REPORTING_WITHIN_SECONDS', 120),

];
