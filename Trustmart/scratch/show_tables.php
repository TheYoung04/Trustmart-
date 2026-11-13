<?php
require_once __DIR__ . '/../config/db.php';

echo "TABLES IN DATABASE:\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    echo "=====================================\n";
    echo "TABLE: $table\n";
    echo "=====================================\n";
    $columns = $pdo->query("DESCRIBE `$table`")->fetchAll();
    foreach ($columns as $column) {
        printf("  %-20s %-20s %-10s %-5s %s\n", 
            $column['Field'], 
            $column['Type'], 
            $column['Null'], 
            $column['Key'], 
            $column['Default']
        );
    }
}
