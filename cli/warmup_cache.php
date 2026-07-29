<?php
/**
 * MySQL FTS buffer pool warmup script.
 * Run after MySQL starts to pre-load ft_address posting list pages into the
 * InnoDB buffer pool, so the first real user search is fast instead of cold.
 *
 * Usage:  php cli/warmup_cache.php
 * Schedule: Windows Task Scheduler → trigger: "On service start: MySQL57"
 */

require __DIR__ . '/../config/db.php';

$cities = [
    // Major Tamil Nadu cities / districts
    'Chennai','Coimbatore','Madurai','Tiruppur','Salem','Erode','Tirunelveli',
    'Tuticorin','Thoothukudi','Vellore','Thanjavur','Trichy','Tiruchirappalli',
    'Dindigul','Cuddalore','Nagercoil','Kanchipuram','Kumbakonam','Theni',
    'Namakkal','Dharmapuri','Virudhunagar','Sivaganga','Ramanathapuram',
    'Kovilpatti','Villupuram','Tiruvannamalai','Chengalpattu','Hosur',
    'Krishnagiri','Karur','Perambalur','Mayiladuthurai','Puducherry',
    // Karnataka
    'Bangalore','Bengaluru','Mysuru','Mangalore','Hubli','Dharwad','Belgaum',
    'Bellary','Gulbarga','Bijapur','Shimoga','Tumkur','Raichur','Bidar',
    // Andhra Pradesh
    'Hyderabad','Visakhapatnam','Vijayawada','Guntur','Nellore','Kurnool',
    'Tirupati','Rajahmundry','Kakinada','Anantapur',
    // Kerala
    'Thiruvananthapuram','Kochi','Kozhikode','Thrissur','Kollam','Palakkad',
];

$tables = [
    'customers_tamil_nadu',
    'customers_karnataka',
    'customers_andhra_pradesh',
    'customers_kerala',
];

// Check which tables have ft_address
$hasFt = array_flip(
    $pdo->query("SELECT table_name FROM information_schema.STATISTICS
                  WHERE table_schema = DATABASE() AND index_name = 'ft_address'"
    )->fetchAll(PDO::FETCH_COLUMN)
);

$start = microtime(true);
echo date('H:i:s') . " Warmup started\n";

foreach ($tables as $tbl) {
    if (!isset($hasFt[$tbl])) continue;
    echo "  [$tbl]\n";
    foreach ($cities as $city) {
        try {
            $pdo->query(
                "SELECT id FROM `$tbl`
                  WHERE MATCH(address,permanent_address) AGAINST('+$city' IN BOOLEAN MODE)
                  LIMIT 1"
            )->fetch();
        } catch (PDOException $e) { /* skip errors */ }
    }
}

$sec = round(microtime(true) - $start);
echo date('H:i:s') . " Warmup done in {$sec}s\n";
