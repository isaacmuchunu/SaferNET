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

];
