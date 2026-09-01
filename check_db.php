<?php
// ไฟล์สำหรับตรวจสอบการเชื่อมต่อฐานข้อมูลอย่างเดียว
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>ตรวจสอบการเชื่อมต่อฐานข้อมูล</h1>";

$host = "127.0.0.1:3306";
$username = "u892799997_emergency_plan";
$password = "Gan05061997@";
$dbname = "u892799997_emergency_plan";

try {
    echo "<p>กำลังทดสอบการเชื่อมต่อไปยัง:</p>";
    echo "<ul>";
    echo "<li>Host: $host</li>";
    echo "<li>Username: $username</li>";
    echo "<li>Database: $dbname</li>";
    echo "</ul>";
    
    // ทดสอบการเชื่อมต่อโดยไม่ระบุฐานข้อมูล
    $conn_server = new mysqli($host, $username, $password);
    if ($conn_server->connect_error) {
        throw new Exception("ไม่สามารถเชื่อมต่อกับ MySQL Server: " . $conn_server->connect_error);
    }
    
    echo "<p style='color:green'>✓ เชื่อมต่อกับ MySQL Server สำเร็จ!</p>";
    
    // ตรวจสอบว่าฐานข้อมูลมีอยู่หรือไม่
    $result = $conn_server->query("SHOW DATABASES LIKE '$dbname'");
    if ($result->num_rows == 0) {
        echo "<p style='color:red'>✗ ไม่พบฐานข้อมูล '$dbname'</p>";
        
        echo "<p>ฐานข้อมูลที่มีอยู่:</p>";
        $dbs = $conn_server->query("SHOW DATABASES");
        echo "<ul>";
        while ($db = $dbs->fetch_row()) {
            echo "<li>" . $db[0] . "</li>";
        }
        echo "</ul>";
        
        throw new Exception("กรุณาสร้างฐานข้อมูล '$dbname' ก่อน");
    }
    
    echo "<p style='color:green'>✓ พบฐานข้อมูล '$dbname'</p>";
    
    // ทดสอบการเชื่อมต่อกับฐานข้อมูลที่ระบุ
    $conn = new mysqli($host, $username, $password, $dbname);
    if ($conn->connect_error) {
        throw new Exception("ไม่สามารถเชื่อมต่อกับฐานข้อมูล '$dbname': " . $conn->connect_error);
    }
    
    echo "<p style='color:green'>✓ เชื่อมต่อกับฐานข้อมูล '$dbname' สำเร็จ!</p>";
    
    // ตรวจสอบตารางในฐานข้อมูล
    $tables = $conn->query("SHOW TABLES");
    if ($tables->num_rows == 0) {
        echo "<p style='color:orange'>⚠ ไม่พบตารางในฐานข้อมูล '$dbname'</p>";
        echo "<p>คุณต้องนำเข้าโครงสร้างฐานข้อมูลจากไฟล์ database.sql</p>";
    } else {
        echo "<p style='color:green'>✓ พบตารางในฐานข้อมูล:</p>";
        echo "<ul>";
        while ($table = $tables->fetch_row()) {
            echo "<li>" . $table[0] . "</li>";
        }
        echo "</ul>";
    }
    
    $conn->close();
    $conn_server->close();
    
    echo "<p style='color:green; font-size:20px;'>✓ การทดสอบเสร็จสิ้น!</p>";
    echo "<p>หากทุกขั้นตอนแสดงเครื่องหมาย ✓ สีเขียว แสดงว่าการเชื่อมต่อฐานข้อมูลไม่มีปัญหา</p>";
    
} catch (Exception $e) {
    echo "<p style='color:red; font-size:20px;'>✗ เกิดข้อผิดพลาด: " . $e->getMessage() . "</p>";
    
    // แนะนำการแก้ไข
    echo "<h2>แนวทางการแก้ไข:</h2>";
    echo "<ol>";
    echo "<li>ตรวจสอบว่า MySQL Server กำลังทำงานอยู่</li>";
    echo "<li>ตรวจสอบว่า Host, Username และ Password ถูกต้อง</li>";
    echo "<li>ตรวจสอบว่าผู้ใช้มีสิทธิ์ในการเข้าถึงฐานข้อมูล</li>";
    echo "<li>หากเชื่อมต่อผ่าน localhost แต่ไม่สำเร็จ ให้ลองใช้ 127.0.0.1 แทน</li>";
    echo "<li>ตรวจสอบไฟร์วอลล์ว่าอนุญาตการเชื่อมต่อกับ MySQL</li>";
    echo "</ol>";
}
?>
