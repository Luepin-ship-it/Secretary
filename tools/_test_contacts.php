<?php
require __DIR__ . '/../lib/contact_normalize.php';

$cases = [
    ['phone' => '8.02289963E8', 'line' => '5.0', 'name' => 'TAN499 เกี้ยว'],
    ['phone' => 'kakiaz', 'line' => '', 'name' => 'TAN486'],
    ['phone' => 'iamditsaraapond', 'line' => '', 'name' => 'TAN729'],
    ['phone' => '8.79978324E8', 'line' => '8.79978324E8', 'name' => 'dup phone in line'],
    ['phone' => 'รามอินทรา วัชรพล', 'line' => '', 'name' => 'zone in phone'],
    ['phone' => '0972515458', 'line' => 'รามอินทรา วัชรพล สายไหม หทัยราษฎร์', 'zone' => '', 'name' => 'zone in line'],
    ['phone' => '3.0', 'line' => '', 'name' => 'tiny phone'],
];

foreach ($cases as $c) {
    $r = repair_owner_contacts($c);
    $zone = isset($c['zone']) ? " zone={$r['zone']}" : '';
    echo "{$c['name']}: phone={$r['phone']} line={$r['line_id']}{$zone}\n";
}
