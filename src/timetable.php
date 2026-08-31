<?php

declare(strict_types=1);

/** Horizontal timeline geometry. Overlapping events get separate, reusable lanes. */
function timetable_layout(array $slices): array
{
    $start = 8 * 60;
    $end = 20 * 60;
    $days = [];
    foreach ($slices as $slice) {
        [$hour, $minute] = array_map('intval', explode(':', $slice['start_time']));
        $slice['start_minute'] = $hour * 60 + $minute;
        [$hour, $minute] = array_map('intval', explode(':', $slice['end_time']));
        $slice['end_minute'] = $hour * 60 + $minute;
        $start = min($start, (int)floor($slice['start_minute'] / 60) * 60);
        $end = max($end, (int)ceil($slice['end_minute'] / 60) * 60);
        $days[$slice['date']][] = $slice;
    }
    $result = [];
    foreach ($days as $date => $events) {
        usort($events, static fn(array $a, array $b): int => [$a['start_minute'], $a['end_minute'], $a['room_code'], $a['key']] <=> [$b['start_minute'], $b['end_minute'], $b['room_code'], $b['key']]);
        $laneEnds = [];
        foreach ($events as &$event) {
            $lane = 0;
            while (isset($laneEnds[$lane]) && $laneEnds[$lane] > $event['start_minute']) $lane++;
            $laneEnds[$lane] = $event['end_minute'];
            $event['lane'] = $lane;
            $event['left'] = 100 * ($event['start_minute'] - $start) / ($end - $start);
            $event['width'] = 100 * ($event['end_minute'] - $event['start_minute']) / ($end - $start);
        }
        unset($event);
        $result[$date] = ['events'=>$events, 'lanes'=>max(1, count($laneEnds))];
    }
    return ['start'=>$start, 'end'=>$end, 'hours'=>(int)(($end-$start)/60), 'days'=>$result];
}
