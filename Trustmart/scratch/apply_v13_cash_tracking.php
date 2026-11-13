<?php
/**
 * Apply schema_update_v13.sql - Cash Payment Tracking
 * Run this once to add cash payment audit trail
 */

require_once __DIR__ . '/../config/db.php';

echo "=== Applying Schema Update V13: Cash Payment Tracking ===\n\n";

try {
    // Read SQL file
    $sql_file = __DIR__ . '/../config/schema_update_v13.sql';
    
    if (!file_exists($sql_file)) {
        die("ERROR: schema_update_v13.sql not found!\n");
    }
    
    $sql = file_get_contents($sql_file);
    
    // Split by delimiter and execute
    $statements = preg_split('/;[\s]*\n/', $sql, -1, PREG_SPLIT_NO_EMPTY);
    
    $executed = 0;
    $skipped = 0;
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        
        // Skip comments and empty statements
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }
        
        // Skip delimiter changes
        if (stripos($statement, 'DELIMITER') !== false) {
            continue;
        }
        
        try {
            $pdo->exec($statement . ';');
            $executed++;
            echo ".";
        } catch (PDOException $e) {
            // Skip if already exists
            if (strpos($e->getMessage(), 'Duplicate') !== false || 
                strpos($e->getMessage(), 'already exists') !== false) {
                $skipped++;
                echo "S";
            } else {
                echo "\n\nERROR executing statement:\n";
                echo substr($statement, 0, 200) . "...\n";
                echo "Error: " . $e->getMessage() . "\n\n";
            }
        }
    }
    
    echo "\n\n=== Schema Update V13 Applied ===\n";
    echo "Statements executed: $executed\n";
    echo "Statements skipped: $skipped\n\n";
    
    // Verify tables exist
    echo "=== Verification ===\n";
    
    $tables_check = [
        'cash_payments' => "SELECT COUNT(*) FROM cash_payments",
    ];
    
    foreach ($tables_check as $table => $query) {
        try {
            $stmt = $pdo->query($query);
            $count = $stmt->fetchColumn();
            echo "✓ Table '$table' exists with $count record(s)\n";
        } catch (PDOException $e) {
            echo "✗ Table '$table' check failed: " . $e->getMessage() . "\n";
        }
    }
    
    // Check backfill results
    echo "\n=== Backfill Results ===\n";
    
    $stmt = $pdo->query("
        SELECT 
            payment_status,
            COUNT(*) as count
        FROM transactions
        WHERE payment_type = 'cash'
        GROUP BY payment_status
    ");
    
    echo "Cash order payment statuses:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['payment_status'] ?? 'NULL';
        echo "  - $status: {$row['count']} orders\n";
    }
    
    $stmt = $pdo->query("
        SELECT COUNT(*) as confirmed_count
        FROM cash_payments
        WHERE confirmed_by_seller = 1
    ");
    $confirmed = $stmt->fetchColumn();
    
    echo "\nCash payments confirmed: $confirmed\n";
    
    echo "\n=== SUCCESS: Cash payment tracking enabled ===\n";
    
} catch (Exception $e) {
    echo "\n\nFATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
