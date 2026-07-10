<?php
// db_update.php
// Script to safely add missing columns to the users table.
require_once 'config.php';

$columns_to_add = [
    'google_drive_id' => "VARCHAR(255) DEFAULT NULL AFTER google_sheet_id",
    'first_name' => "VARCHAR(100) DEFAULT NULL AFTER encryption_key",
    'last_name' => "VARCHAR(100) DEFAULT NULL AFTER first_name",
    'phone' => "VARCHAR(50) DEFAULT NULL AFTER last_name",
    'consent_required' => "TINYINT DEFAULT 1 AFTER phone",
    'consent_optional' => "TINYINT DEFAULT 0 AFTER consent_required"
];

foreach ($columns_to_add as $col => $definition) {
    // Check if column exists
    $result = $conn->query("SHOW COLUMNS FROM `users` LIKE '$col'");
    if ($result && $result->num_rows == 0) {
        echo "Adding column '$col' to users table...\n";
        $alter = $conn->query("ALTER TABLE `users` ADD `$col` $definition");
        if ($alter) {
            echo "Successfully added column '$col'.\n";
        } else {
            echo "Error adding column '$col': " . $conn->error . "\n";
        }
    } else {
        echo "Column '$col' already exists in users table.\n";
    }
}

echo "Database updates complete.\n";
