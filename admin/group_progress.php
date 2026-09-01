<?php
// หน้าแสดงความคืบหน้าทุกกลุ่มแบบ FHD
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// ดึงข้อมูลทุก Group: นับ Rotation (ไม่ใช่ Part)
$groupsSql = "
    SELECT 
        pp.group_name,
        gs.max_position,
        COUNT(DISTINCT pp.rotation) as total,
        COUNT(DISTINCT CASE WHEN pp.status = 'completed'  THEN pp.rotation END) as completed,
        COUNT(DISTINCT CASE WHEN pp.status = 'inprogress' THEN pp.rotation END) as inprogress,
        COUNT(DISTINCT CASE WHEN pp.status = 'pending'    THEN pp.rotation END) as pending
    FROM picking_plans pp
    LEFT JOIN group_settings gs ON gs.group_name = pp.group_name
    GROUP BY pp.group_name
    ORDER BY pp.group_name
";
$groupsResult = $conn->query($groupsSql);
$groupsData = [];
while ($row = $groupsResult->fetch_assoc()) {
    $groupsData[$row['group_name']] = $row;
}

// ดึง rotation ที่กำลัง inprogress ของแต่ละ group
$inprogressSql = "
    SELECT group_name, MIN(rotation) as current_rotation
    FROM picking_plans
    WHERE status = 'inprogress'
    GROUP BY group_name
";
$inprogressResult = $conn->query($inprogressSql);
$currentRotation = [];
while ($row = $inprogressResult->fetch_assoc()) {
    $currentRotation[$row['group_name']] = $row['current_rotation'];
}

// ดึง rotation pending แรก (ถัดไป) ของแต่ละ group ที่ไม่มี inprogress
$pendingRotSql = "
    SELECT group_name, MIN(rotation) as next_rotation
    FROM picking_plans
    WHERE status = 'pending'
    GROUP BY group_name
";
$pendingRotResult = $conn->query($pendingRotSql);
$nextRotation = [];
while ($row = $pendingRotResult->fetch_assoc()) {
    $nextRotation[$row['group_name']] = $row['next_rotation'];
}

// ดึงจำนวน Dolly รอบที่เสร็จแล้วจาก completed_rounds
$dollySql = "SELECT group_name, COUNT(*) as dolly_done FROM completed_rounds GROUP BY group_name";
$dollyResult = $conn->query($dollySql);
$dollyDone = [];
while ($row = $dollyResult->fetch_assoc()) {
    $dollyDone[$row['group_name']] = $row['dolly_done'];
}

// ดึง rotation สุดท้ายที่ completed ของแต่ละ group
$lastRotSql = "
    SELECT group_name, MAX(rotation) as last_rotation
    FROM picking_plans
    WHERE status = 'completed'
    GROUP BY group_name
";
$lastRotResult = $conn->query($lastRotSql);
$lastCompletedRot = [];
while ($row = $lastRotResult->fetch_assoc()) {
    $lastCompletedRot[$row['group_name']] = $row['last_rotation'];
}

// ดึง Rotation แรกสุด (เริ่มต้น) ของแต่ละ group
$firstRotSql = "
    SELECT group_name, MIN(rotation) as first_rotation
    FROM picking_plans
    GROUP BY group_name
";
$firstRotResult = $conn->query($firstRotSql);
$firstRotation = [];
while ($row = $firstRotResult->fetch_assoc()) {
    $firstRotation[$row['group_name']] = $row['first_rotation'];
}

// ดึง Rotation ล่าสุดที่เข้ามาในระบบ (MAX ของทุก status)
$latestRotSql = "
    SELECT group_name, MAX(rotation) as latest_rotation
    FROM picking_plans
    GROUP BY group_name
";
$latestRotResult = $conn->query($latestRotSql);
$latestRotation = [];
while ($row = $latestRotResult->fetch_assoc()) {
    $latestRotation[$row['group_name']] = $row['latest_rotation'];
}

$conn->close();

// แยก GROUP ปกติ กับ LOT/LOT SIZE
$mainGroups = [];
$lotGroups = [];
foreach ($groupsData as $name => $data) {
    if (str_starts_with($name, 'GROUP')) {
        $mainGroups[$name] = $data;
    } else {
        $lotGroups[$name] = $data;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ความคืบหน้าทุกกลุ่ม - Emergency Picking</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1a4f72;
            --secondary-color: #2980b9;
            --success-color: #27ae60;
            --warning-color: #e67e22;
            --danger-color: #c0392b;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #1a1a2e;
            color: #e0e0e0;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

        .page-header {
            background: linear-gradient(135deg, #1a4f72 0%, #16213e 100%);
            padding: 0.7rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #2980b9;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .page-header h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #fff;
            margin: 0;
        }

        .page-header h1 i {
            color: #ffcc00;
            margin-right: 0.5rem;
        }

        .header-meta {
            font-size: 0.82rem;
            color: rgba(255,255,255,0.7);
        }

        .header-meta span {
            margin-left: 1rem;
        }

        .header-meta .badge-live {
            background: #e74c3c;
            color: white;
            padding: 0.15rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            animation: pulse-red 1.5s infinite;
        }

        @keyframes pulse-red {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .main-content {
            padding: 1rem 1.2rem 1.5rem;
        }

        /* Summary Bar */
        .summary-bar {
            display: flex;
            gap: 0.8rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .summary-pill {
            background: #16213e;
            border: 1px solid #2980b9;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            flex: 1;
            min-width: 140px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .summary-pill .sp-label {
            font-size: 0.72rem;
            color: rgba(255,255,255,0.6);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .summary-pill .sp-value {
            font-size: 1.6rem;
            font-weight: 700;
            line-height: 1.1;
        }

        .summary-pill.sp-blue  .sp-value { color: #5dade2; }
        .summary-pill.sp-green .sp-value { color: #58d68d; }
        .summary-pill.sp-orange .sp-value { color: #f39c12; }
        .summary-pill.sp-red   .sp-value { color: #ec7063; }
        .summary-pill.sp-yellow .sp-value { color: #f7dc6f; }

        /* Section Title */
        .section-title {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(255,255,255,0.5);
            margin: 0.8rem 0 0.5rem;
            padding-left: 0.3rem;
            border-left: 3px solid #2980b9;
        }

        /* Group Grid */
        .group-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 0.8rem;
        }

        /* FHD: force 5 columns for main groups */
        @media (min-width: 1500px) {
            .group-grid.main-grid {
                grid-template-columns: repeat(5, 1fr);
            }
        }

        @media (min-width: 1200px) and (max-width: 1499px) {
            .group-grid.main-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        /* Group Card */
        .group-card {
            background: #16213e;
            border: 1px solid #2c3e6e;
            border-radius: 10px;
            padding: 0.9rem 1rem;
            transition: border-color 0.2s, transform 0.2s;
            position: relative;
            overflow: hidden;
        }

        .group-card:hover {
            border-color: #2980b9;
            transform: translateY(-2px);
        }

        .group-card.status-completed {
            border-left: 4px solid #27ae60;
        }

        .group-card.status-inprogress {
            border-left: 4px solid #f39c12;
        }

        .group-card.status-pending {
            border-left: 4px solid #2980b9;
        }

        .group-card.status-empty {
            border-left: 4px solid #555;
        }

        /* Card Header */
        .gc-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.6rem;
        }

        .gc-name {
            font-size: 1.05rem;
            font-weight: 700;
            color: #fff;
        }

        .gc-badge {
            font-size: 0.65rem;
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .badge-completed  { background: #1e8449; color: #a9dfbf; }
        .badge-inprogress { background: #784212; color: #f8c471; }
        .badge-pending    { background: #154360; color: #85c1e9; }
        .badge-empty      { background: #333; color: #aaa; }

        /* Progress Bar */
        .gc-progress-wrap {
            margin-bottom: 0.5rem;
        }

        .gc-progress-bar-bg {
            background: #0d1b2a;
            border-radius: 4px;
            height: 10px;
            overflow: hidden;
            margin-bottom: 0.25rem;
        }

        .gc-progress-bar-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s;
        }

        .fill-completed  { background: linear-gradient(90deg, #1e8449, #58d68d); }
        .fill-inprogress { background: linear-gradient(90deg, #935116, #f39c12); }
        .fill-pending    { background: linear-gradient(90deg, #154360, #2980b9); }
        .fill-empty      { background: #333; }

        .gc-pct {
            font-size: 0.8rem;
            color: rgba(255,255,255,0.6);
            text-align: right;
        }

        /* Stats Row */
        .gc-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.3rem;
            margin-bottom: 0.7rem;
        }

        .gc-stat {
            background: #0d1b2a;
            border-radius: 6px;
            padding: 0.35rem 0.4rem;
            text-align: center;
        }

        .gc-stat .stat-num {
            font-size: 1.1rem;
            font-weight: 700;
            line-height: 1;
        }

        .gc-stat .stat-label {
            font-size: 0.62rem;
            color: rgba(255,255,255,0.5);
            margin-top: 0.1rem;
        }

        .stat-green  { color: #58d68d; }
        .stat-orange { color: #f39c12; }
        .stat-blue   { color: #5dade2; }

        /* Info rows */
        .gc-info {
            border-top: 1px solid #2c3e6e;
            padding-top: 0.55rem;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .gc-info-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: rgba(255,255,255,0.55);
        }

        .gc-info-row .val {
            color: rgba(255,255,255,0.9);
            font-weight: 600;
        }

        .gc-info-row .val.rot-active {
            color: #f39c12;
        }

        /* LOT section */
        .lot-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 0.8rem;
        }

        /* Refresh button */
        .btn-refresh {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            border-radius: 6px;
            padding: 0.3rem 0.8rem;
            font-size: 0.8rem;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-refresh:hover {
            background: rgba(255,255,255,0.2);
        }

        /* Countdown */
        #countdown {
            font-size: 0.75rem;
            color: rgba(255,255,255,0.5);
        }
    </style>
</head>
<body>

<div class="page-header">
    <h1><i class="fas fa-tachometer-alt"></i> ความคืบหน้าทุกกลุ่ม — Operation TTV AAT-Wiring</h1>
    <div class="header-meta">
        <span class="badge-live">● LIVE</span>
        <span><i class="fas fa-clock"></i> <?php echo date('d/m/Y H:i:s'); ?></span>
        <span id="countdown"></span>
        <button class="btn-refresh ml-2" onclick="location.reload()"><i class="fas fa-sync-alt"></i> รีเฟรช</button>
        <a href="index.php" class="btn-refresh ml-1"><i class="fas fa-arrow-left"></i> กลับ</a>
    </div>
</div>

<?php
// Summary totals
$totalAll = 0; $completedAll = 0; $inprogressAll = 0; $pendingAll = 0;
$totalDolly = 0;
foreach ($groupsData as $d) {
    $totalAll += $d['total'];
    $completedAll += $d['completed'];
    $inprogressAll += $d['inprogress'];
    $pendingAll += $d['pending'];
}
foreach ($dollyDone as $d) { $totalDolly += $d; }
$pctAll = $totalAll > 0 ? round($completedAll / $totalAll * 100, 1) : 0;
?>

<div class="main-content">

    <!-- Summary Bar -->
    <div class="summary-bar">
        <div class="summary-pill sp-blue">
            <div class="sp-label">รวมทุก Rotation</div>
            <div class="sp-value"><?php echo number_format($totalAll); ?></div>
        </div>
        <div class="summary-pill sp-green">
            <div class="sp-label">เสร็จแล้ว</div>
            <div class="sp-value"><?php echo number_format($completedAll); ?></div>
        </div>
        <div class="summary-pill sp-orange">
            <div class="sp-label">กำลังทำ</div>
            <div class="sp-value"><?php echo number_format($inprogressAll); ?></div>
        </div>
        <div class="summary-pill sp-red">
            <div class="sp-label">คงเหลือ</div>
            <div class="sp-value"><?php echo number_format($pendingAll); ?></div>
        </div>
        <div class="summary-pill sp-yellow">
            <div class="sp-label">ความคืบหน้ารวม</div>
            <div class="sp-value"><?php echo $pctAll; ?>%</div>
        </div>
        <div class="summary-pill sp-green">
            <div class="sp-label">Dolly สำเร็จทั้งหมด</div>
            <div class="sp-value"><?php echo $totalDolly; ?></div>
        </div>
    </div>

    <!-- Main Groups -->
    <div class="section-title"><i class="fas fa-layer-group"></i> กลุ่มหลัก (<?php echo count($mainGroups); ?> กลุ่ม)</div>
    <div class="group-grid main-grid">
    <?php foreach ($mainGroups as $groupName => $d):
        $pct = $d['total'] > 0 ? round($d['completed'] / $d['total'] * 100, 1) : 0;
        $maxPos = $d['max_position'] ?? 16;

        if ($pct == 100) {
            $statusClass = 'status-completed';
            $badgeClass = 'badge-completed';
            $badgeText = 'เสร็จสมบูรณ์';
            $fillClass = 'fill-completed';
        } elseif ($d['inprogress'] > 0 || $d['completed'] > 0) {
            $statusClass = 'status-inprogress';
            $badgeClass = 'badge-inprogress';
            $badgeText = 'กำลังดำเนินการ';
            $fillClass = 'fill-inprogress';
        } elseif ($d['total'] > 0) {
            $statusClass = 'status-pending';
            $badgeClass = 'badge-pending';
            $badgeText = 'รอดำเนินการ';
            $fillClass = 'fill-pending';
        } else {
            $statusClass = 'status-empty';
            $badgeClass = 'badge-empty';
            $badgeText = 'ว่าง';
            $fillClass = 'fill-empty';
        }

        $curRot = $currentRotation[$groupName] ?? ($nextRotation[$groupName] ?? '-');
        $isActive = isset($currentRotation[$groupName]);
        $lastRot = $lastCompletedRot[$groupName] ?? '-';
        $dollyCount = $dollyDone[$groupName] ?? 0;

        // คำนวณ Dolly รอบที่ทำอยู่ (ประมาณการจาก completed / max_position)
        $completedPositions = $d['completed'];
        // หา current position ใน dolly (ประมาณ)
        $currentDollyRound = $maxPos > 0 ? ceil($completedPositions / $maxPos) : 0;
    ?>
    <div class="group-card <?php echo $statusClass; ?>">
        <div class="gc-header">
            <div class="gc-name"><?php echo htmlspecialchars($groupName); ?></div>
            <span class="gc-badge <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>
        </div>

        <div class="gc-progress-wrap">
            <div class="gc-progress-bar-bg">
                <div class="gc-progress-bar-fill <?php echo $fillClass; ?>" style="width:<?php echo $pct; ?>%"></div>
            </div>
            <div class="gc-pct"><?php echo $pct; ?>% — <?php echo number_format($d['completed']); ?> / <?php echo number_format($d['total']); ?> Rotation</div>
        </div>

        <div class="gc-stats">
            <div class="gc-stat">
                <div class="stat-num stat-blue"><?php echo htmlspecialchars($firstRotation[$groupName] ?? '-'); ?></div>
                <div class="stat-label">Rot. แรกสุด</div>
            </div>
            <div class="gc-stat">
                <div class="stat-num stat-orange"><?php echo htmlspecialchars($currentRotation[$groupName] ?? '-'); ?></div>
                <div class="stat-label">Rot. ที่ทำอยู่</div>
            </div>
            <div class="gc-stat">
                <div class="stat-num stat-green"><?php echo htmlspecialchars($latestRotation[$groupName] ?? '-'); ?></div>
                <div class="stat-label">Rot. ใหม่สุด</div>
            </div>
        </div>

        <div class="gc-info">
            <div class="gc-info-row">
                <span><i class="fas fa-sync-alt fa-xs"></i> Rotation ปัจจุบัน</span>
                <span class="val <?php echo $isActive ? 'rot-active' : ''; ?>">
                    <?php echo htmlspecialchars($curRot); ?>
                    <?php if ($isActive): ?><i class="fas fa-circle fa-xs" style="color:#f39c12;font-size:0.55rem;vertical-align:middle;"></i><?php endif; ?>
                </span>
            </div>
            <div class="gc-info-row">
                <span><i class="fas fa-check-double fa-xs"></i> Rotation ล่าสุดที่เสร็จ</span>
                <span class="val"><?php echo htmlspecialchars($lastRot); ?></span>
            </div>
            <div class="gc-info-row">
                <span><i class="fas fa-dolly fa-xs"></i> Dolly รอบที่พิมพ์ใบสรุป</span>
                <span class="val"><?php echo $dollyCount; ?> รอบ</span>
            </div>
            <div class="gc-info-row">
                <span><i class="fas fa-map-marker-alt fa-xs"></i> Max Position / Dolly</span>
                <span class="val"><?php echo $maxPos; ?> ตำแหน่ง</span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <?php if (!empty($lotGroups)): ?>
    <!-- LOT Groups -->
    <div class="section-title mt-3"><i class="fas fa-boxes"></i> กลุ่ม LOT (<?php echo count($lotGroups); ?> กลุ่ม)</div>
    <div class="group-grid lot-grid">
    <?php foreach ($lotGroups as $groupName => $d):
        $pct = $d['total'] > 0 ? round($d['completed'] / $d['total'] * 100, 1) : 0;
        if ($pct == 100) {
            $statusClass = 'status-completed'; $badgeClass = 'badge-completed'; $badgeText = 'เสร็จสมบูรณ์'; $fillClass = 'fill-completed';
        } elseif ($d['inprogress'] > 0 || $d['completed'] > 0) {
            $statusClass = 'status-inprogress'; $badgeClass = 'badge-inprogress'; $badgeText = 'กำลังดำเนินการ'; $fillClass = 'fill-inprogress';
        } elseif ($d['total'] > 0) {
            $statusClass = 'status-pending'; $badgeClass = 'badge-pending'; $badgeText = 'รอดำเนินการ'; $fillClass = 'fill-pending';
        } else {
            $statusClass = 'status-empty'; $badgeClass = 'badge-empty'; $badgeText = 'ว่าง'; $fillClass = 'fill-empty';
        }
    ?>
    <div class="group-card <?php echo $statusClass; ?>">
        <div class="gc-header">
            <div class="gc-name"><?php echo htmlspecialchars($groupName); ?></div>
            <span class="gc-badge <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>
        </div>
        <div class="gc-progress-wrap">
            <div class="gc-progress-bar-bg">
                <div class="gc-progress-bar-fill <?php echo $fillClass; ?>" style="width:<?php echo $pct; ?>%"></div>
            </div>
            <div class="gc-pct"><?php echo $pct; ?>% — <?php echo number_format($d['completed']); ?> / <?php echo number_format($d['total']); ?> Rotation</div>
        </div>
        <div class="gc-stats">
            <div class="gc-stat">
                <div class="stat-num stat-blue"><?php echo htmlspecialchars($firstRotation[$groupName] ?? '-'); ?></div>
                <div class="stat-label">Rot. แรกสุด</div>
            </div>
            <div class="gc-stat">
                <div class="stat-num stat-orange"><?php echo htmlspecialchars($currentRotation[$groupName] ?? '-'); ?></div>
                <div class="stat-label">Rot. ที่ทำอยู่</div>
            </div>
            <div class="gc-stat">
                <div class="stat-num stat-green"><?php echo htmlspecialchars($latestRotation[$groupName] ?? '-'); ?></div>
                <div class="stat-label">Rot. ใหม่สุด</div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div><!-- end main-content -->

<script>
    // Countdown auto-refresh ทุก 60 วินาที
    let sec = 60;
    const cd = document.getElementById('countdown');
    setInterval(function() {
        sec--;
        if (cd) cd.textContent = 'รีเฟรชใน ' + sec + 'วิ';
        if (sec <= 0) location.reload();
    }, 1000);
</script>
</body>
</html>
