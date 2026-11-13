<?php
require_once __DIR__ . '/../config/db.php';
echo "=== TRIGGERS ===\n";
foreach ($pdo->query('SHOW TRIGGERS')->fetchAll() as $t) {
    echo $t['Trigger'] . ': ' . $t['Event'] . ' ON ' . $t['Table'] . "\n";
}
echo "\n=== PROCEDURES ===\n";
foreach ($pdo->query("SHOW PROCEDURE STATUS WHERE Db='trustmart'")->fetchAll() as $p) {
    echo $p['Name'] . "\n";
}
echo "\n=== users columns ===\n";
foreach ($pdo->query('DESCRIBE users')->fetchAll() as $c) {
    echo $c['Field'] . ' | ' . $c['Type'] . ' | default:' . ($c['Default'] ?? 'NULL') . "\n";
}
