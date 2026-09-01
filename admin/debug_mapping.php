<?php
// หน้าตรวจสอบการแมปปิ้งคอลัมน์ CSV
session_start();
require_once '../config/database.php';

// ตรวจสอบว่ามีการล็อกอิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$message = '';
$messageType = '';
$csvData = [];
$headers = [];
$mappedColumns = [];

// ฟังก์ชันแมปคอลัมน์ - นำมาจากไฟล์ upload.php
function mapColumns($headers) {
    $columnMap = [];
    $expectedColumns = [
        'rotation' => ['rotation', 'rot', 'rotation_number', 'rotation_id'],
        'group_name' => ['part_group', 'group', 'grp', 'group_name', 'groupname'],
        'part_number' => ['part_number', 'part_no', 'part', 'partnumber', 'pn', 'partno'],
        'part_name' => ['part_name', 'name', 'description', 'desc', 'part_description'],
        'quantity' => ['quantity', 'qty', 'amount', 'count'],
        'supplier' => ['supplier', 'sup', 'vendor', 'supplier_name'],
        'location' => ['location', 'loc', 'position', 'pos', 'storage']
    ];
    
    foreach ($expectedColumns as $key => $variations) {
        foreach ($headers as $index => $header) {
            $header = strtolower(trim($header));
            foreach ($variations as $variation) {
                if ($header === $variation || strpos($header, $variation) !== false) {
                    $columnMap[$key] = $index;
                    break 2;
                }
            }
        }
    }
    
    // ถ้าไม่พบการแมปตามปกติ ให้ใช้ตำแหน่งคอลัมน์ตามลำดับ
    if (!isset($columnMap['rotation']) && count($headers) > 0) {
        $columnMap['rotation'] = 0; // คอลัมน์แรก
    }
    
    if (!isset($columnMap['group_name']) && count($headers) > 1) {
        $columnMap['group_name'] = 1; // คอลัมน์ที่สอง
    }
    
    if (!isset($columnMap['part_number']) && count($headers) > 2) {
        $columnMap['part_number'] = 2; // คอลัมน์ที่สาม
    }
    
    if (!isset($columnMap['supplier']) && count($headers) > 3) {
        $columnMap['supplier'] = 3; // คอลัมน์ที่สี่
    }
    
    if (!isset($columnMap['location']) && count($headers) > 4) {
        $columnMap['location'] = 4; // คอลัมน์ที่ห้า
    }
    
    return $columnMap;
}

// ฟังก์ชันตรวจสอบคุณภาพข้อมูล
function validateData($row, $headerMap) {
    $issues = [];
    
    // ตรวจสอบข้อมูลสำคัญว่าครบถ้วนหรือไม่
    $group = isset($headerMap['group_name']) && isset($row[$headerMap['group_name']]) ? trim($row[$headerMap['group_name']]) : '';
    $partNumber = isset($headerMap['part_number']) && isset($row[$headerMap['part_number']]) ? trim($row[$headerMap['part_number']]) : '';
    $rotation = isset($headerMap['rotation']) && isset($row[$headerMap['rotation']]) ? trim($row[$headerMap['rotation']]) : '';
    
    if (empty($rotation)) {
        $issues[] = "ไม่พบข้อมูล Rotation";
    }
    
    if (empty($group)) {
        $issues[] = "ไม่พบข้อมูล Group";
    } elseif (strpos(strtoupper($group), 'GROUP') === false) {
        $issues[] = "Group ไม่มีคำว่า 'GROUP' อาจแมปผิด: $group";
    }
    
    if (empty($partNumber)) {
        $issues[] = "ไม่พบข้อมูล Part Number";
    } elseif (strpos(strtoupper($partNumber), 'GROUP') !== false) {
        $issues[] = "Part Number มีคำว่า 'GROUP' อาจแมปผิด: $partNumber";
    }
    
    return $issues;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if ($_FILES['csv_file']['error'] == 0) {
        $file = $_FILES['csv_file']['tmp_name'];
        $filename = $_FILES['csv_file']['name'];
        
        // ตรวจสอบนามสกุลไฟล์
        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($file_ext != 'csv') {
            $message = "กรุณาอัปโหลดไฟล์ CSV เท่านั้น";
            $messageType = "danger";
        } else {
            // เปิดไฟล์ CSV
            $handle = fopen($file, "r");
            if ($handle !== FALSE) {
                // ตรวจสอบ BOM
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    // ไม่มี BOM
                    rewind($handle);
                }
                
                // อ่านหัวคอลัมน์
                if (($headers = fgetcsv($handle)) !== FALSE) {
                    // แมปคอลัมน์
                    $mappedColumns = mapColumns($headers);
                    
                    // อ่านข้อมูล 10 แถวแรก
                    $row_count = 0;
                    while (($data = fgetcsv($handle)) !== FALSE && $row_count < 10) {
                        $csvData[] = [
                            'row' => $row_count + 1,
                            'data' => $data,
                            'issues' => validateData($data, $mappedColumns)
                        ];
                        $row_count++;
                    }
                    
                    $message = "ตรวจสอบการแมปปิ้งคอลัมน์เรียบร้อย";
                    $messageType = "success";
                }
                
                fclose($handle);
            } else {
                $message = "ไม่สามารถเปิดไฟล์ CSV ได้";
                $messageType = "danger";
            }
        }
    } else {
        $message = "เกิดข้อผิดพลาดในการอัปโหลดไฟล์: " . $_FILES['csv_file']['error'];
        $messageType = "danger";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตรวจสอบการแมปปิ้ง CSV - Emergency Picking System</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f8f9fa;
            padding: 20px;
        }
        .card {
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
        }
        .field-mapped {
            background-color: #d4edda;
        }
        .field-default {
            background-color: #fff3cd;
        }
        .field-missing {
            background-color: #f8d7da;
        }
        .has-issues {
            border-left: 4px solid #dc3545;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mb-4">
            <i class="fas fa-table"></i> ตรวจสอบการแมปปิ้งคอลัมน์ CSV
        </h1>
        
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header bg-primary text-white">
                <i class="fas fa-upload"></i> อัปโหลดไฟล์ CSV เพื่อตรวจสอบการแมปปิ้ง
            </div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="csv_file">เลือกไฟล์ CSV:</label>
                        <div class="custom-file">
                            <input type="file" class="custom-file-input" id="csv_file" name="csv_file" accept=".csv">
                            <label class="custom-file-label" for="csv_file">เลือกไฟล์...</label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check-circle"></i> ตรวจสอบการแมปปิ้ง
                    </button>
                    <a href="upload.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> กลับไปหน้าอัปโหลด
                    </a>
                </form>
            </div>
        </div>
        
        <?php if (!empty($headers)): ?>
        <div class="card">
            <div class="card-header bg-info text-white">
                <i class="fas fa-table"></i> ผลการแมปปิ้งคอลัมน์
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <h5><i class="fas fa-info-circle"></i> วิธีดูผลการแมปปิ้ง:</h5>
                    <ul>
                        <li><span class="badge badge-success">สีเขียว</span> - คอลัมน์ที่แมปถูกต้องจากชื่อคอลัมน์</li>
                        <li><span class="badge badge-warning">สีเหลือง</span> - คอลัมน์ที่แมปจากตำแหน่งเริ่มต้น (อาจไม่ถูกต้อง)</li>
                        <li><span class="badge badge-danger">สีแดง</span> - คอลัมน์ที่ไม่พบการแมปปิ้ง (ข้อมูลจะขาดหาย)</li>
                    </ul>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="thead-light">
                            <tr>
                                <th>ฟิลด์ในฐานข้อมูล</th>
                                <th>คอลัมน์ที่แมป</th>
                                <th>ตำแหน่ง</th>
                                <th>สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $requiredFields = ['rotation', 'group_name', 'part_number'];
                            $optionalFields = ['part_name', 'quantity', 'supplier', 'location'];
                            
                            foreach (array_merge($requiredFields, $optionalFields) as $field): 
                                $mappedIndex = isset($mappedColumns[$field]) ? $mappedColumns[$field] : -1;
                                $columnName = ($mappedIndex >= 0 && isset($headers[$mappedIndex])) ? $headers[$mappedIndex] : 'ไม่พบ';
                                
                                $statusClass = 'field-missing';
                                $statusText = 'ไม่พบการแมปปิ้ง';
                                
                                if ($mappedIndex >= 0) {
                                    // ตรวจสอบว่าแมปจากชื่อคอลัมน์หรือตำแหน่งเริ่มต้น
                                    $defaultMapping = false;
                                    if ($field == 'rotation' && $mappedIndex == 0) $defaultMapping = true;
                                    if ($field == 'group_name' && $mappedIndex == 1) $defaultMapping = true;
                                    if ($field == 'part_number' && $mappedIndex == 2) $defaultMapping = true;
                                    if ($field == 'supplier' && $mappedIndex == 3) $defaultMapping = true;
                                    if ($field == 'location' && $mappedIndex == 4) $defaultMapping = true;
                                    
                                    if ($defaultMapping) {
                                        $statusClass = 'field-default';
                                        $statusText = 'แมปจากตำแหน่งเริ่มต้น';
                                    } else {
                                        $statusClass = 'field-mapped';
                                        $statusText = 'แมปสำเร็จ';
                                    }
                                }
                            ?>
                            <tr class="<?php echo $statusClass; ?>">
                                <td><strong><?php echo $field; ?></strong></td>
                                <td><?php echo htmlspecialchars($columnName); ?></td>
                                <td><?php echo ($mappedIndex >= 0) ? $mappedIndex : 'N/A'; ?></td>
                                <td><?php echo $statusText; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($csvData)): ?>
        <div class="card">
            <div class="card-header bg-success text-white">
                <i class="fas fa-table"></i> ตัวอย่างข้อมูล (10 แถวแรก)
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th>แถวที่</th>
                                <?php foreach ($headers as $header): ?>
                                <th><?php echo htmlspecialchars($header); ?></th>
                                <?php endforeach; ?>
                                <th>สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($csvData as $row): ?>
                            <tr class="<?php echo !empty($row['issues']) ? 'has-issues' : ''; ?>">
                                <td><strong><?php echo $row['row']; ?></strong></td>
                                <?php foreach ($row['data'] as $cell): ?>
                                <td><?php echo htmlspecialchars($cell); ?></td>
                                <?php endforeach; ?>
                                <td>
                                    <?php if (!empty($row['issues'])): ?>
                                    <button class="btn btn-danger btn-sm" data-toggle="popover" data-placement="left" 
                                            title="พบปัญหาในข้อมูล" 
                                            data-content="<?php echo htmlspecialchars(implode('<br>', $row['issues'])); ?>">
                                        <i class="fas fa-exclamation-triangle"></i> พบปัญหา
                                    </button>
                                    <?php else: ?>
                                    <span class="badge badge-success">ข้อมูลสมบูรณ์</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if ($mappedColumns): ?>
                <div class="mt-4">
                    <h5>การแมปปิ้งฟิลด์สำคัญจากข้อมูลตัวอย่าง:</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead class="thead-light">
                                <tr>
                                    <th>แถวที่</th>
                                    <th>Rotation</th>
                                    <th>Group</th>
                                    <th>Part Number</th>
                                    <th>Supplier</th>
                                    <th>Location</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($csvData as $row): 
                                    $rotation = isset($mappedColumns['rotation']) && isset($row['data'][$mappedColumns['rotation']]) ? $row['data'][$mappedColumns['rotation']] : '';
                                    $group = isset($mappedColumns['group_name']) && isset($row['data'][$mappedColumns['group_name']]) ? $row['data'][$mappedColumns['group_name']] : '';
                                    $partNumber = isset($mappedColumns['part_number']) && isset($row['data'][$mappedColumns['part_number']]) ? $row['data'][$mappedColumns['part_number']] : '';
                                    $supplier = isset($mappedColumns['supplier']) && isset($row['data'][$mappedColumns['supplier']]) ? $row['data'][$mappedColumns['supplier']] : '';
                                    $location = isset($mappedColumns['location']) && isset($row['data'][$mappedColumns['location']]) ? $row['data'][$mappedColumns['location']] : '';
                                ?>
                                <tr>
                                    <td><strong><?php echo $row['row']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($rotation); ?></td>
                                    <td class="<?php echo strpos(strtoupper($group), 'GROUP') === false ? 'bg-warning' : ''; ?>"><?php echo htmlspecialchars($group); ?></td>
                                    <td class="<?php echo strpos(strtoupper($partNumber), 'GROUP') !== false ? 'bg-warning' : ''; ?>"><?php echo htmlspecialchars($partNumber); ?></td>
                                    <td><?php echo htmlspecialchars($supplier); ?></td>
                                    <td><?php echo htmlspecialchars($location); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        // แสดงชื่อไฟล์ที่เลือก
        $('.custom-file-input').on('change', function() {
            var fileName = $(this).val().split('\\').pop();
            $(this).next('.custom-file-label').html(fileName);
        });
        
        // เปิดใช้งาน popover
        $(function () {
            $('[data-toggle="popover"]').popover({
                html: true
            });
        });
    </script>
</body>
</html>
