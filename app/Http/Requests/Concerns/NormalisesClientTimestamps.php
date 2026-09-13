<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Carbon;

/**
 * Brings a client's timestamps into the application's timezone before they are
 * stored.
 *
 * Every timestamp column here is `timestamp without time zone`, and Eloquent
 * reads one back in the application timezone. A client that reports in UTC —
 * the browser extension sends `toISOString()`, the Windows agent sends
 * `DateTimeOffset.UtcNow` — therefore has its wall-clock written verbatim and
 * re-read as local time, putting every event three hours in the past on an
 * East African deployment.
 *
 * The consequences are not cosmetic: a workstation never appears to be
 * reporting, `last_activity_at` stops advancing because the incoming time
 * always looks older, the focus-score window excludes everything real, incident
 * thresholds measure the wrong interval, and "today" on the dashboard is
 * shifted by the offset.
 *
 * A value that arrives without an offset is left alone: Carbon already reads it
 * as application-local, which is what an unqualified local time means.
 */
trait NormalisesClientTimestamps
{
    /**
     * @param  list<string>  $fields
     */
    protected function normaliseTimestamps(array $fields): void
    {
        $normalised = [];

        foreach ($fields as $field) {
            $value = $this->input($field);

            if (! is_string($value) || $value === '') {
                continue;
            }

            try {
                $normalised[$field] = Carbon::parse($value)
                    ->setTimezone(config('app.timezone'))
                    ->toDateTimeString();
            } catch (\Throwable) {
                // Leave it for the validator to reject as a malformed date.
            }
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }
}
