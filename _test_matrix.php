<?php
/**
 * Phase 2 matrix verification (CLI) — run: php _test_matrix.php
 */
require_once __DIR__ . '/task_helpers.php';

$pass = 0;
$fail = 0;

function assert_eq($label, $expected, $actual) {
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        echo "OK  $label\n";
    } else {
        $fail++;
        echo "FAIL $label — expected " . json_encode($expected) . " got " . json_encode($actual) . "\n";
    }
}

// Legacy fallback (no events)
$r = lead_resolve_from_stage_events(['status' => 'Win'], []);
assert_eq('legacy Win', 'Win', $r['status']);

// Lose at Call
$events = [
    ['id' => 1, 'stage' => 'Call', 'outcome' => 'lose', 'event_date' => '2026-06-01', 'created_at' => '2026-06-01 10:00:00'],
];
$r = lead_resolve_from_stage_events(['status' => 'Call'], $events);
assert_eq('lose at Call -> Lose', 'Lose', $r['status']);

// Reject at Bank
$events = [
    ['id' => 1, 'stage' => 'Call', 'outcome' => 'yes', 'event_date' => '2026-06-01', 'created_at' => '2026-06-01 10:00:00'],
    ['id' => 2, 'stage' => 'Bank', 'outcome' => 'reject', 'event_date' => '2026-06-10', 'created_at' => '2026-06-10 10:00:00'],
];
$r = lead_resolve_from_stage_events(['status' => 'Call'], $events);
assert_eq('reject at Bank -> Rejected', 'Rejected', $r['status']);
assert_eq('current stage Bank', 'Bank', $r['current_stage']);

// Revival: Bank reject then Bank yes
$events[] = ['id' => 3, 'stage' => 'Bank', 'outcome' => 'yes', 'event_date' => '2026-06-15', 'created_at' => '2026-06-15 10:00:00'];
$r = lead_resolve_from_stage_events(['status' => 'Rejected'], $events);
assert_eq('revival Bank yes -> Bank', 'Bank', $r['status']);

// Win close
$events[] = ['id' => 4, 'stage' => 'Win', 'outcome' => 'yes', 'event_date' => '2026-06-20', 'created_at' => '2026-06-20 10:00:00'];
$r = lead_resolve_from_stage_events(['status' => 'Bank'], $events);
assert_eq('Win event -> Win', 'Win', $r['status']);

// Terminal mapping
assert_eq('outcome lose', 'Lose', lead_matrix_outcome_to_terminal_status('lose'));
assert_eq('outcome reject', 'Rejected', lead_matrix_outcome_to_terminal_status('reject'));
assert_eq('outcome hold', 'Hold_Reject', lead_matrix_outcome_to_terminal_status('hold'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
