<?php
// ============================================================
// Root entry point.
// Sends visitors who arrive at /Trustmart/ (or the server root)
// to the public landing/login page. Also stops Apache from
// exposing a directory listing of the project files.
// ============================================================
header('Location: public/index.php');
exit;
