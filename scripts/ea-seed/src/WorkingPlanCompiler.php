<?php

namespace EaSeed;

/**
 * Compiles the sessions a vendor operates into the single start/end plus breaks
 * that EasyAppointments stores per weekday.
 *
 * EA allows one contiguous range per day, so a vendor working two separated
 * blocks is expressed as the outer span with the gap carved out as a break.
 * This is what lets an evening block coexist with a 00:00 session.
 */
final class WorkingPlanCompiler
{
    /**
     * @param list<array{start: string, end: string}> $sessions Validated, sorted ascending by start.
     * @return array{start: string, end: string, breaks: list<array{start: string, end: string}>}|null
     *         Null when the vendor is closed that day.
     */
    public static function compile(array $sessions): ?array
    {
        if ($sessions === []) {
            return null;
        }

        $sessions = array_values($sessions);
        $breaks = [];

        for ($i = 1; $i < count($sessions); $i++) {
            $breaks[] = [
                'start' => $sessions[$i - 1]['end'],
                'end' => $sessions[$i]['start'],
            ];
        }

        return [
            'start' => $sessions[0]['start'],
            'end' => $sessions[count($sessions) - 1]['end'],
            'breaks' => $breaks,
        ];
    }
}
