<?php

declare(strict_types=1);

// Synthetic ranges for time/conflict tests, not a bypass in the production service.
function fixture_academic_term(string $start, string $end, string $semester = '1'): array
{
    if (PHP_SAPI !== 'cli' || getenv('LUMS_DB_DSN') !== 'sqlite::memory:') {
        throw new RuntimeException('Synthetic term fixtures require an in-memory CLI test database.');
    }
    db()->prepare("INSERT INTO academic_terms (name,academic_year,semester,starts_on,ends_on,status,created_at,updated_at) VALUES (?,2573,?,?,?,'planned',?,?)")
        ->execute(['2573/'.$semester,$semester,$start,$end,utc_now(),utc_now()]);
    return ['ok'=>true,'id'=>(int)db()->lastInsertId()];
}
