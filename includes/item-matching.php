<?php

const ITEM_MATCH_CANDIDATE_LIMIT = 120;
const ITEM_MATCH_RESULT_LIMIT = 5;

function canViewItemMatches(?array $report, int $studentId): bool
{
    return $report !== null && $report['item_type'] === 'lost' && $report['status'] === 'open'
        && (string) $report['reporter_id'] === (string) $studentId;
}

function itemMatchTerms(string $text): array
{
    static $ignored = [
        'about' => true, 'and' => true, 'any' => true, 'anyone' => true, 'are' => true,
        'campus' => true, 'contact' => true, 'details' => true, 'find' => true, 'for' => true,
        'found' => true, 'have' => true, 'help' => true, 'here' => true, 'item' => true,
        'items' => true, 'lost' => true, 'missing' => true, 'near' => true, 'now' => true,
        'object' => true, 'owner' => true,
        'please' => true, 'report' => true, 'something' => true, 'somewhere' => true,
        'take' => true, 'tell' => true, 'that' => true, 'the' => true, 'there' => true,
        'these' => true, 'thing' => true, 'things' => true, 'this' => true, 'today' => true,
        'student' => true, 'unknown' => true, 'very' => true, 'was' => true, 'were' => true, 'with' => true,
        'you' => true, 'your' => true, 'at' => true, 'by' => true, 'in' => true,
        'is' => true, 'it' => true, 'me' => true, 'my' => true, 'of' => true,
        'on' => true, 'or' => true, 'to' => true,
        'ang' => true, 'ito' => true, 'ko' => true, 'mga' => true, 'nakita' => true,
        'nawala' => true, 'ng' => true, 'po' => true, 'sa' => true,
    ];

    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    if (preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{M}]+/u', $text, $matches) === false) {
        return [];
    }

    $terms = array_fill_keys($matches[0], true);
    return array_diff_key($terms, $ignored);
}

function itemMatchLocationsAreSimilar(array $lost, array $found): bool
{
    $common = count(array_intersect_key($lost, $found));
    if ($common === 0) {
        return false;
    }
    $distinct = count($lost) + count($found) - $common;

    return $common === $distinct || ($common >= 2 && 2 * $common >= $distinct);
}

function itemMatchOccurrenceTime(?string $value): ?int
{
    if ($value === null || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value) !== 1) {
        return null;
    }

    // MariaDB DATETIME values share the same reporting clock. Compare them as wall-clock values.
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    return $date !== false && $date->format('Y-m-d H:i:s') === $value ? $date->getTimestamp() : null;
}

function findPotentialItemMatches(PDO $pdo, array $lost, int $studentId): array
{
    if (!canViewItemMatches($lost, $studentId)) {
        return [];
    }

    return rankOpenLostReportMatches($pdo, $lost);
}

// Callers must authorize access to the source report before ranking candidates.
function rankOpenLostReportMatches(PDO $pdo, array $lost): array
{
    // Recheck the source and keep the candidate window bounded before ranking in PHP.
    $statement = $pdo->prepare("SELECT found.id, found.title, found.description, found.location,
        found.occurred_at, found.image_path
        FROM items lost
        INNER JOIN items found ON found.category_id = lost.category_id
        WHERE lost.id = :lost_id AND lost.reporter_id = :owner
          AND lost.item_type = 'lost' AND lost.status = 'open'
          AND found.item_type = 'found' AND found.status = 'open'
          AND found.reporter_id <> lost.reporter_id
        ORDER BY found.id DESC LIMIT :candidate_limit");
    $statement->bindValue(':lost_id', (string) $lost['id']);
    $statement->bindValue(':owner', (string) $lost['reporter_id']);
    $statement->bindValue(':candidate_limit', ITEM_MATCH_CANDIDATE_LIMIT, PDO::PARAM_INT);
    $statement->execute();

    $lostTitle = itemMatchTerms($lost['title']);
    $lostDescription = itemMatchTerms($lost['description']);
    $lostLocation = itemMatchTerms($lost['location']);
    $lostTime = itemMatchOccurrenceTime($lost['occurred_at']);
    $ranked = [];

    while ($found = $statement->fetch(PDO::FETCH_ASSOC)) {
        $sharedTitle = array_intersect_key($lostTitle, itemMatchTerms($found['title']));
        $sharedDescription = array_diff_key(
            array_intersect_key($lostDescription, itemMatchTerms($found['description'])),
            $sharedTitle
        );
        $titleCount = count($sharedTitle);
        $descriptionCount = count($sharedDescription);
        // Category, date, and location alone are too broad to suggest a match.
        if ($titleCount === 0 && $descriptionCount < 2) {
            continue;
        }

        $reasons = ['Same category'];
        $score = 5 * min($titleCount, 4) + min($descriptionCount, 4);
        if ($titleCount > 0) {
            $reasons[] = 'Similar title';
        }
        if ($descriptionCount >= 2) {
            $reasons[] = 'Similar description';
        }
        if (itemMatchLocationsAreSimilar($lostLocation, itemMatchTerms($found['location']))) {
            $score += 2;
            $reasons[] = 'Similar reported location';
        }
        $foundTime = itemMatchOccurrenceTime($found['occurred_at']);
        if ($lostTime !== null && $foundTime !== null) {
            $gap = $foundTime - $lostTime;
            if ($gap >= 0 && $gap <= 14 * 86400) {
                $score += $gap <= 3 * 86400 ? 2 : 1;
                $reasons[] = 'Occurrence dates are close';
            }
        }

        $ranked[] = [
            'score' => $score,
            'report' => [
                'id' => (string) $found['id'],
                'title' => $found['title'],
                'description' => $found['description'],
                'location' => $found['location'],
                'occurred_at' => $found['occurred_at'],
                'image_path' => $found['image_path'],
                'reasons' => $reasons,
            ],
        ];
    }

    // PHP's stable sort preserves the SQL order (newest ID first) for ties.
    usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_column(array_slice($ranked, 0, ITEM_MATCH_RESULT_LIMIT), 'report');
}
