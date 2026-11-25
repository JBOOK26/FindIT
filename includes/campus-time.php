<?php

const FINDIT_CAMPUS_TIME_ZONE = 'Asia/Manila';

function parseCampusDate(string $format, string $value): ?DateTimeImmutable
{
    if ($value === '' || str_contains($value, "\0")) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone(FINDIT_CAMPUS_TIME_ZONE));
    // Round-trip validation rejects dates that PHP would otherwise normalize.
    return $date !== false && (int) $date->format('Y') >= 1000 && $date->format($format) === $value ? $date : null;
}

function campusOccurrenceInput(?string $stored): string
{
    return $stored === null ? '' : (parseCampusDate('Y-m-d H:i:s', $stored)?->format('Y-m-d\TH:i') ?? '');
}

function formatCampusOccurrence(?string $stored): string
{
    return $stored === null ? 'No date provided' : (parseCampusDate('Y-m-d H:i:s', $stored)?->format('M j, Y \a\t g:i A') ?? 'Date unavailable');
}
