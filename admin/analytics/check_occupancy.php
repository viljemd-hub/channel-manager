<?php

$data = json_decode(file_get_contents('occupancy_merged.json'), true);

// normalize days
$days = [];

foreach ($data as $row) {
    if (($row['status'] ?? '') !== 'reserved') continue;

    $start = strtotime($row['start']);
    $end   = strtotime($row['end']);

    for ($t = $start; $t < $end; $t += 86400) {
        $days[date('Y-m-d', $t)] = true;
    }
}

$totalDays = count($days);

// define period manually
$periodStart = strtotime('2026-04-01');
$periodEnd   = strtotime('2026-10-01');

$allDays = 0;
for ($t = $periodStart; $t < $periodEnd; $t += 86400) {
    $allDays++;
}

echo "Booked days: $totalDays\n";
echo "Total days: $allDays\n";
echo "Occupancy: " . round(($totalDays / $allDays) * 100, 2) . "%\n";