<?php
echo "STARTING CONNECTION TO 127.0.0.1...\n";
flush();
$t0 = microtime(true);
try {
    // try with mysqli
    $conn = @new mysqli("127.0.0.1", "root", "", "");
    if ($conn->connect_error) {
        echo "MYSQLI ERROR: " . $conn->connect_error . "\n";
    } else {
        echo "MYSQLI CONNECTED SUCCESSFULLY in " . round(microtime(true) - $t0, 4) . "s\n";
        $res = $conn->query("SHOW DATABASES");
        while ($row = $res->fetch_row()) {
            echo "DB: " . $row[0] . "\n";
        }
        $conn->close();
    }
} catch (Exception $e) {
    echo "MYSQLI THREW EXCEPTION: " . $e->getMessage() . "\n";
}
echo "DONE\n";
