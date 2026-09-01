<?php
// import_master.php
// ใช้สำหรับ import ข้อมูล ABT1755565050560.csv เข้า MySQL

// แสดง error เพื่อ debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ล้างข้อมูลเก่า
$conn->query("TRUNCATE TABLE master_data");

$file = 'ABT1755565050560.csv';
if (!file_exists($file)) {
    die("File not found: $file");
}

$delimiter = ",";
if (($handle = fopen($file, "r")) !== FALSE) {
    // ข้าม header ถ้ามี
    fgetcsv($handle);
    $imported = 0;
    
    while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
        if (count($data) >= 7) {
            // part_group = col 7, part_number = col 2, supplier = col 4, location_pick = col 5
            $part_group = isset($data[7]) ? $conn->real_escape_string($data[7]) : '';
            $part_number = isset($data[2]) ? $conn->real_escape_string(str_replace('-', '', $data[2])) : '';
            $supplier = isset($data[8]) ? $conn->real_escape_string($data[8]) : '';
            $location_pick = isset($data[14]) ? $conn->real_escape_string($data[14]) : '';

            if (!empty($part_number)) {
                $sql = "INSERT INTO master_data (part_group, part_number, supplier, location_pick) VALUES ('$part_group', '$part_number', '$supplier', '$location_pick')";
                if ($conn->query($sql)) {
                    $imported++;
                } else {
                    echo "<p>Error: " . $conn->error . " for part number $part_number</p>";
                }
            }
        }
    }
    fclose($handle);
    echo "<p>Import completed. Imported $imported records.</p>";
}
$conn->close();
?>
