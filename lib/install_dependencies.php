<?php
// ไฟล์สำหรับติดตั้ง dependencies
echo "<h1>กำลังติดตั้ง PhpSpreadsheet...</h1>";

// ตรวจสอบว่ามี Composer หรือไม่
if (!file_exists(__DIR__ . '/composer.phar')) {
    echo "<p>กำลังดาวน์โหลด Composer...</p>";
    $composerInstaller = file_get_contents('https://getcomposer.org/installer');
    file_put_contents(__DIR__ . '/composer_installer.php', $composerInstaller);
    
    exec('php ' . __DIR__ . '/composer_installer.php', $output, $return_var);
    
    if ($return_var !== 0) {
        echo "<p style='color:red'>ไม่สามารถติดตั้ง Composer ได้</p>";
        echo "<pre>" . implode("\n", $output) . "</pre>";
        exit;
    }
    
    echo "<p style='color:green'>ติดตั้ง Composer สำเร็จ</p>";
    unlink(__DIR__ . '/composer_installer.php');
}

// ติดตั้ง PhpSpreadsheet
echo "<p>กำลังติดตั้ง PhpSpreadsheet...</p>";
exec('php ' . __DIR__ . '/composer.phar require phpoffice/phpspreadsheet', $output, $return_var);

if ($return_var !== 0) {
    echo "<p style='color:red'>ไม่สามารถติดตั้ง PhpSpreadsheet ได้</p>";
    echo "<pre>" . implode("\n", $output) . "</pre>";
    exit;
}

echo "<p style='color:green'>ติดตั้ง PhpSpreadsheet สำเร็จ</p>";
echo "<p>คุณสามารถใช้งานระบบอัปโหลดไฟล์ Excel ได้แล้ว</p>";
echo "<p><a href='../admin/upload.php'>กลับไปหน้าอัปโหลด</a></p>";
