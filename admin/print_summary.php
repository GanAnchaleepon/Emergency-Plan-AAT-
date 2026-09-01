<?php
session_start();
require_once '../config/database.php';

// ตรวจสอบว่ามีการล็อกอินเป็นแอดมิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// ดึงรายชื่อกลุ่มงานทั้งหมดที่มีข้อมูล
$groups = [];
$sql_groups = "SELECT DISTINCT group_name FROM picking_plans ORDER BY group_name ASC";
$result_groups = $conn->query($sql_groups);
if ($result_groups && $result_groups->num_rows > 0) {
    while ($row = $result_groups->fetch_assoc()) {
        $groups[] = $row['group_name'];
    }
}

$selected_group = isset($_GET['group']) ? $_GET['group'] : '';
$rounds = []; // เปลี่ยนจาก $completed_rotations เป็น $rounds

if (!empty($selected_group)) {
    // 1. ดึงค่า max_position ของกลุ่ม
    $max_position = 18; // ค่าเริ่มต้น
    $stmt_settings = $conn->prepare("SELECT max_position FROM group_settings WHERE group_name = ?");
    if ($stmt_settings) {
        $stmt_settings->bind_param('s', $selected_group);
        $stmt_settings->execute();
        $result_settings = $stmt_settings->get_result();
        if ($row_settings = $result_settings->fetch_assoc()) {
            $max_position = (int)$row_settings['max_position'];
        }
        $stmt_settings->close();
    }

    // 2. ดึงรายการที่ completed ทั้งหมดของกลุ่มนี้ เรียงตามเวลา
    $sql_completed = "SELECT id, updated_at, position, rotation FROM picking_plans WHERE group_name = ? AND status = 'completed' ORDER BY updated_at ASC";
    $stmt_completed = $conn->prepare($sql_completed);
    $stmt_completed->bind_param('s', $selected_group);
    $stmt_completed->execute();
    $result_completed = $stmt_completed->get_result();

    $completed_rows = [];
    while ($row = $result_completed->fetch_assoc()) {
        $completed_rows[] = $row;
    }
    $stmt_completed->close();

    // --- ดึงข้อมูลรอบที่บันทึกไว้ ---
    $saved_rounds_data = [];
    $sql_saved_rounds = "SELECT end_time, rack_sequence, ttv_round_no FROM completed_rounds WHERE group_name = ?";
    $stmt_saved = $conn->prepare($sql_saved_rounds);
    if ($stmt_saved) {
        $stmt_saved->bind_param('s', $selected_group);
        $stmt_saved->execute();
        $result_saved = $stmt_saved->get_result();
        while ($row = $result_saved->fetch_assoc()) {
            $saved_rounds_data[$row['end_time']] = [
                'rack_sequence' => $row['rack_sequence'],
                'ttv_round_no' => $row['ttv_round_no']
            ];
        }
        $stmt_saved->close();
    }
    // --- สิ้นสุดการดึงข้อมูล ---

    // 3. แบ่งรอบใหม่ทุกครั้งที่มี completed ต่อเนื่อง (ไม่ต้องรอเต็มคัน)
    $final_end_times = [];
    $last_end_time = '1970-01-01 00:00:00';
    $round_number = 1;
    $rounds = [];

    if (!empty($completed_rows)) {
        $current_start_time = $last_end_time;
        $i = 0;
        while ($i < count($completed_rows)) {
            $row = $completed_rows[$i];
            $is_last = ($i === count($completed_rows) - 1);

            // ถ้า position = max_position หรือเป็นแถวสุดท้าย
            if ($row['position'] == $max_position || $is_last) {
                // หา updated_at ที่มากที่สุดของ position นี้ในช่วงเวลาที่ติดกัน
                // (ทุก part ของ rotation เดียวกันที่ position นี้ จะมี timestamp ต่อเนื่องกัน)
                $current_pos = $row['position'];
                $max_updated_at = $row['updated_at'];
                $j = $i;
                // วนหาแถวถัดไปที่ position เดียวกันและ updated_at มากกว่าเดิม
                while ($j + 1 < count($completed_rows) && $completed_rows[$j + 1]['position'] == $current_pos) {
                    $j++;
                    if ($completed_rows[$j]['updated_at'] > $max_updated_at) {
                        $max_updated_at = $completed_rows[$j]['updated_at'];
                    }
                }

                // ดึงรายละเอียดรอบนี้
                $sql_round_details = "SELECT 
                                        COUNT(*) as item_count,
                                        MIN(position) as start_pos,
                                        MAX(position) as end_pos,
                                        GROUP_CONCAT(DISTINCT rotation ORDER BY CAST(rotation AS UNSIGNED) SEPARATOR ', ') as rotations
                                      FROM picking_plans 
                                      WHERE group_name = ? 
                                        AND status = 'completed' 
                                        AND updated_at > ? 
                                        AND updated_at <= ?";
                $stmt_round = $conn->prepare($sql_round_details);
                $stmt_round->bind_param('sss', $selected_group, $current_start_time, $max_updated_at);
                $stmt_round->execute();
                $round_details = $stmt_round->get_result()->fetch_assoc();
                $stmt_round->close();

                if ($round_details['item_count'] > 0) {
                    $rounds[] = [
                        'round_no' => $round_number++,
                        'item_count' => $round_details['item_count'],
                        'start_pos' => $round_details['start_pos'],
                        'end_pos' => $round_details['end_pos'],
                        'rotations' => $round_details['rotations'],
                        'completion_time' => $max_updated_at,
                        'start_time_param' => $current_start_time,
                        'end_time_param' => $max_updated_at,
                        'saved_data' => $saved_rounds_data[$max_updated_at] ?? null
                    ];
                }
                $current_start_time = $max_updated_at;
                $i = $j + 1;
            } else {
                $i++;
            }
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>พิมพ์ใบสรุป Dolly - ระบบ Emergency Picking</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../includes/navbar.css">
    <style>
        :root {
            --primary-color: #1a4f72; 
            --secondary-color: #2980b9;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
        }
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f5f5f5;
        }
        .main-content { padding: 2rem; max-width: 900px; margin: 20px auto; }
        .card { border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-radius: 10px; }
        .card-header {
            background-color: var(--primary-color); color: white; font-weight: 600;
            border-top-left-radius: 10px; border-top-right-radius: 10px;
        }
        .card-header i { margin-right: 0.5rem; }
        .btn-primary { background-color: var(--primary-color); border-color: var(--primary-color); }
        .btn-primary:hover { background-color: #16405d; border-color: #16405d; }
        .footer { text-align: center; margin-top: 2rem; padding: 1rem; color: #6c757d; font-size: 0.9rem; }
        .table thead th {
            background-color: #f8f9fa;
        }
        .badge-new {
            position: absolute;
            top: 10px;
            right: 3px;
            font-size: 0.65rem;
            padding: 0.15rem 0.35rem;
            background-color: #e74c3c;
            border-radius: 10px;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="main-content">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-search"></i> เลือกกลุ่มเพื่อพิมพ์ใบสรุป Dolly
            </div>
            <div class="card-body">
                <form method="GET" action="">
                    <div class="form-group">
                        <label for="group"><strong>เลือกกลุ่มงาน:</strong></label>
                        <select class="form-control" id="group" name="group" onchange="this.form.submit()">
                            <option value="">-- กรุณาเลือกกลุ่มงาน --</option>
                            <?php foreach ($groups as $group_name): ?>
                                <option value="<?php echo htmlspecialchars($group_name); ?>" 
                                    <?php echo ($selected_group === $group_name) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($group_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($selected_group)): ?>
        <div class="card mt-4">
            <div class="card-header">
                <i class="fas fa-file-pdf"></i> รอบการหยิบที่เสร็จสมบูรณ์สำหรับกลุ่ม <?php echo htmlspecialchars($selected_group); ?>
            </div>
            <div class="card-body">
                <?php if (empty($rounds)): ?>
                    <div class="alert alert-warning" role="alert">
                        ไม่พบรอบการหยิบที่เสร็จสมบูรณ์สำหรับกลุ่มนี้ (ยังไม่มีการหยิบถึง Position สูงสุด)
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered">
                            <thead>
                                <tr>
                                    <th>รอบที่</th>
                                    <th>จำนวนรายการ</th>
                                    <th>ตำแหน่ง (เริ่ม - จบ)</th>
                                    <th>Rotations ที่เกี่ยวข้อง</th>
                                    <th>เวลาที่เสร็จสิ้น</th>
                                    <th>RACK SEQUENCE (3 ตัว)</th>
                                    <th>TTV Round No.</th>
                                    <th>การดำเนินการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rounds as $round): ?>
                                <tr>
                                    <td><?php echo $round['round_no']; ?></td>
                                    <td><?php echo $round['item_count']; ?></td>
                                    <td><?php echo $round['start_pos'] . ' - ' . $round['end_pos']; ?></td>
                                    <td><?php echo htmlspecialchars($round['rotations']); ?></td>
                                    <td><?php echo date('d/m/Y H:i:s', strtotime($round['completion_time'])); ?></td>
                                    <td>
                                        <input type="text" id="rack_sequence_<?php echo $round['round_no']; ?>" class="form-control form-control-sm mx-auto" maxlength="3" pattern="\d{3}" placeholder="000" style="width: 80px;" value="<?php echo htmlspecialchars($round['saved_data']['rack_sequence'] ?? ''); ?>">
                                    </td>
                                    <td>
                                        <input type="text" id="ttv_round_no_<?php echo $round['round_no']; ?>" class="form-control form-control-sm mx-auto" placeholder="00" style="width: 120px;" maxlength="2" pattern="\d{2}" value="<?php echo htmlspecialchars($round['saved_data']['ttv_round_no'] ?? ''); ?>">
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-success btn-sm" 
                                                onclick="printSummary(
                                                    <?php echo $round['round_no']; ?>, 
                                                    '<?php echo urlencode($selected_group); ?>', 
                                                    '<?php echo urlencode($round['start_time_param']); ?>', 
                                                    '<?php echo urlencode($round['end_time_param']); ?>'
                                                )">
                                            <i class="fas fa-print"></i> พิมพ์ใบสรุป
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        function printSummary(roundNo, group, startTime, endTime) {
            const rackInput = document.getElementById('rack_sequence_' + roundNo);
            const rackSequence = rackInput.value;
            const ttvRoundInput = document.getElementById('ttv_round_no_' + roundNo);
            const ttvRoundNo = ttvRoundInput.value;

            if (!/^\d{3}$/.test(rackSequence)) {
                alert('กรุณากรอก RACK SEQUENCE เป็นตัวเลข 3 หลัก');
                rackInput.focus();
                return;
            }

            if (!/^\d{2}$/.test(ttvRoundNo)) {
                alert('กรุณากรอก TTV Round No. เป็นตัวเลข 2 หลัก');
                ttvRoundInput.focus();
                return;
            }

            const url = `../generate_summary_html.php?group=${group}&start_time=${startTime}&end_time=${endTime}&round_no=${roundNo}&rack_sequence=${rackSequence}&ttv_round_no=${encodeURIComponent(ttvRoundNo)}`;
            window.open(url, '_blank');
        }
    </script>
</body>
</html>