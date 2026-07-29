<?php
require __DIR__ . '/../includes/auth.php';
requireAdmin('../login.php');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="customer_import_sample.csv"');

$out = fopen('php://output', 'w');
// Customer Code deliberately omitted — the importer auto-generates it
// (CUST####) for any row that doesn't supply one, so it doesn't belong in a
// sample meant to show what you actually need to fill in.
fputcsv($out, ['Name', 'Mobile No', 'DOB', 'Gender', "Father's Name", 'Address', 'Permanent Address', 'Email', 'Alternative No', 'Identity']);
fputcsv($out, ['Ravi Kumar', '9000000001', '1990-04-12', 'M', 'Suresh Kumar', '12 MG Road, Sample Town', '12 MG Road, Sample Town', 'ravi.kumar@example.com', '9000000011', 'ABCDE1234F']);
fputcsv($out, ['Priya Sharma', '9000000002', '1988-11-02', 'F', 'Anil Sharma', '45 Lake View, Demo City', '45 Lake View, Demo City', 'priya.sharma@example.com', '9000000012', 'BCDEF2345G']);
fclose($out);
