<?php
require __DIR__ . '/../lib/owner_field_normalize.php';

$cases = [
    '50/50 Transfer Fee' => ['rent' => '', 'fmt' => ''],
    '90000.0' => ['rent' => '90000.0', 'fmt' => '90,000'],
    '90000' => ['rent' => '90000', 'fmt' => '90,000'],
    '1.79E7' => ['rent' => '1.79E7', 'fmt' => '17,900,000'],
];

foreach ($cases as $input => $expect) {
    $san = sanitize_rental_price($input);
    $fmt = fmt_price_full($san !== '' ? $san : $input);
    if ($expect['rent'] !== '') {
        $san = sanitize_rental_price($input);
    }
    $fmtRent = fmt_price_full(sanitize_rental_price($input) ?: (is_transfer_fee_text($input) ? '' : $input));
    echo "$input => sanitize_rent=" . json_encode(sanitize_rental_price($input)) . " fmt=" . json_encode(fmt_price_full($input)) . PHP_EOL;
}

$row = repair_shifted_owner_fields([
    'asking_price' => '',
    'direction' => '7200000.0',
    'rental_price' => '50/50 Transfer Fee',
    'unit_no' => '21.9',
]);
echo "TAN728 repair: " . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
