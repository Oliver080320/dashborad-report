<?php
// Local dev-only helper: sets the session fields queue.php / monitoring.php read
// ($_SESSION['user_name'], $_SESSION['title']) so the app can be clicked through
// without the real CRM login. Not part of Daniel's repo — added for this local run only.
session_start();
$role = $_GET['as'] ?? 'admin';

$logins = [
    'admin'   => ['user_name' => 'admin',   'title' => 'admin'],
    'karthik' => ['user_name' => 'karthik', 'title' => 'sales'],
    'HarshP'  => ['user_name' => 'HarshP',  'title' => 'sales'],
    'hemant'  => ['user_name' => 'hemant',  'title' => 'sales'],
    'ArunP'   => ['user_name' => 'ArunP',   'title' => 'sales'],
];
$chosen = $logins[$role] ?? $logins['admin'];
$_SESSION['user_name'] = $chosen['user_name'];
$_SESSION['title'] = $chosen['title'];

$target = $_GET['go'] ?? 'tdu_queue/monitoring_system/monitoring.php';
header('Location: /' . ltrim($target, '/'));
exit;
