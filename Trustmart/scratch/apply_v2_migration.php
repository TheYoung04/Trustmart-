<?php
// scratch/apply_v2_migration.php
// Run this to automatically apply schema_update_v2.sql to the database.
require_once __DIR__ . '/../config/db.php';

try {
    echo "Applying schema_update_v2.sql...\n";
    $sql = file_get_contents(__DIR__ . '/../config/schema_update_v2.sql');
    $pdo->exec($sql);
    echo "Successfully applied schema_update_v2.sql!\n";
} catch (Exception $e) {
    echo "Error applying migration: " . $e->getMessage() . "\n";
}
