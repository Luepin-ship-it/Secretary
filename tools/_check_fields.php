<?php
require dirname(__DIR__) . '/config.php';
$key = $conn->query('SELECT encryption_key FROM users WHERE id=8')->fetch_assoc()['encryption_key'];
$r = $conn->query("SELECT code_list, unit_no_enc, direction_enc, asking_price_enc, rental_price_enc, selling_condition, floor_enc, area_sqm_enc FROM owners WHERE user_id=8 AND (code_list LIKE 'TAN618%' OR rental_price_enc IS NOT NULL) LIMIT 8");
while ($row = $r->fetch_assoc()) {
    echo $row['code_list'] . PHP_EOL;
    foreach (['unit_no','direction','asking_price','rental_price','selling_condition','floor','area_sqm'] as $f) {
        $v = decrypt_data($row[$f . '_enc'] ?? '', $key);
        if ($f === 'selling_condition') $v = $row['selling_condition'];
        echo "  $f: " . var_export($v, true) . PHP_EOL;
    }
    echo PHP_EOL;
}
