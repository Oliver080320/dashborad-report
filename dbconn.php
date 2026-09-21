<?php
// Local dev stand-in for the real cPanel dbconn.php, which lives outside this repo and
// connects to the live CRM. This one points at a local MariaDB instance seeded from the
// CSV exports in ../data (see ../../tools/*.sql for how it was built), purely so
// Daniel's queue/monitoring PHP can be run and clicked through locally.
$conn = new mysqli('127.0.0.1', 'root', 'rootpass', 'dev2yourbestwayh_v5', 3307);
if ($conn->connect_error) {
    http_response_code(500);
    die('DB connection failed: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
