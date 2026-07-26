<?php
require __DIR__ . '/../includes/auth.php';
requireAdmin('../login.php');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="ecommerce_import_sample.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Name', 'Mobile Number', 'Address', 'Delivery Date', 'Location']);
fputcsv($out, ['Ravi Kumar', '9000000001', '12 MG Road, Sample Town', '2026-07-20', '12.9716,77.5946']);
fputcsv($out, ['Priya Sharma', '9000000002', '45 Lake View, Demo City', '2026-07-22', '13.0827,80.2707']);
fclose($out);
