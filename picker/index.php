<?php
// หน้าหลักสำหรับพนักงานหยิบงาน (Picker)
session_start();

// รายชื่อกลุ่มงานทั้งหมด (กำหนดไว้ล่วงหน้า)
$defined_groups = [
    'Group01', 'Group02', 'Group03', 'Group04',
    'Group05', 'Group06L', 'Group06R', 'Group07',
    'Group09L', 'Group09R', 'Group10', 'Group11'
];

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เลือกกลุ่มงาน - ระบบ Emergency Picking</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1a4f72;
            --secondary-color: #2980b9;
            --success-color: #27ae60;
            --light-color: #f6fafd;
            --dark-color: #2c3e50;
            --border-radius: 1.2rem;
            --box-shadow: 0 8px 24px rgba(41, 79, 114, 0.08);
        }
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #fff;
            min-height: 100vh;
            display: flex; /* Added */
            flex-direction: column; /* Added */
            color: var(--dark-color);
            overflow-y: hidden; /* Added to prevent body scroll */
        }
        .main-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--success-color) 100%);
            color: white;
            padding: 1.5rem 0 1rem 0; /* Reduced padding */
            text-align: center;
            box-shadow: 0 6px 15px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem; /* Reduced margin */
            position: relative;
        }
        .main-header h1 {
            font-weight: 700;
            font-size: 2.2rem; /* Reduced font size */
            margin-bottom: 0.3rem; /* Reduced margin */
            text-shadow: 2px 2px 6px rgba(0,0,0,0.08);
        }
        .main-header p {
            font-size: 1rem; /* Reduced font size */
            opacity: 0.95;
        }
        .main-content {
            flex: 1;
            padding: 1rem 1rem 0.5rem 1rem; /* Reduced padding */
            max-width: 1100px;
            margin: 0 auto;
            overflow-y: auto; /* Allow scrolling within main content if needed */
            display: flex; /* Added for inner flex */
            flex-direction: column; /* Added for inner flex */
        }
        /* Adjust grid to be 4 columns vertically */
        .group-grid {
            display: grid;
            /* Use auto-fit to adjust column count automatically */
            /* grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); */ /* Removed */
            grid-template-columns: 1fr; /* Default: 1 column on small screens */
            gap: 0.8rem; /* Reduced gap */
            margin-top: 1rem; /* Reduced margin */
            flex-grow: 1; /* Added to make grid fill space */
            align-content: start; /* Added to align items to the start */
        }
        .group-card {
            background: var(--light-color);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0.8rem 0.5rem 0.5rem 0.5rem; /* Reduced padding */
            transition: box-shadow 0.2s, transform 0.2s;
            border: 2px solid transparent;
            position: relative;
            min-height: 90px; /* Reduced min-height */
            justify-content: center;
        }
        .group-card:hover, .group-card:focus-within {
            box-shadow: 0 12px 32px rgba(41, 79, 114, 0.15);
            border-color: var(--primary-color);
            transform: translateY(-4px) scale(1.02); /* Reduced transform */
            z-index: 2;
        }
        .group-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 0.6rem 0.3rem; /* Reduced padding */
            font-size: 1.1rem; /* Reduced font-size */
            font-weight: 700;
            color: var(--primary-color);
            background: white;
            border: none;
            border-radius: 0.6rem; /* Reduced border-radius */
            box-shadow: 0 2px 8px rgba(41, 79, 114, 0.07);
            transition: background 0.2s, color 0.2s, box-shadow 0.2s;
            text-decoration: none;
            margin-top: 0.3rem; /* Reduced margin */
            outline: none;
        }
        .group-btn:focus, .group-btn:hover {
            background: linear-gradient(90deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: #fff;
            box-shadow: 0 4px 16px rgba(41, 79, 114, 0.18);
            text-decoration: none;
        }
        .group-btn i {
            margin-right: 0.5rem; /* Reduced margin */
            font-size: 1.4rem; /* Reduced font-size */
            transition: color 0.2s;
        }
        .group-card .group-label {
            font-size: 0.9rem; /* Reduced font-size */
            font-weight: 600;
            color: var(--secondary-color);
            margin-bottom: 0.3rem; /* Reduced margin */
            letter-spacing: 1px;
        }
        .footer {
            background-color: var(--dark-color);
            color: rgba(255,255,255,0.8);
            text-align: center;
            padding: 0.8rem 0 0.6rem 0; /* Reduced padding */
            margin-top: 1.5rem; /* Reduced margin */
            font-size: 0.9rem; /* Reduced font size */
            flex-shrink: 0; /* Added */
        }
        .alert-info {
            background-color: #e1f5fe;
            border-color: #b3e5fc;
            color: #01579b;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 1rem; /* Reduced padding */
            font-size: 1rem; /* Reduced font size */
        }
        .alert-info i { margin-right: 0.5rem; /* Reduced margin */ }
        h2 {
            color: var(--dark-color);
            font-weight: 700;
            margin-bottom: 1.5rem; /* Reduced margin */
            position: relative;
            padding-bottom: 0.4rem; /* Reduced padding */
            font-size: 1.5rem; /* Reduced font size */
        }
        h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 70px; /* Reduced width */
            height: 3px; /* Reduced height */
            background-color: var(--primary-color);
            border-radius: 2px;
        }
        /* Adjust Media Queries to define column count */
        @media (min-width: 576px) {
            .group-grid { grid-template-columns: repeat(2, 1fr); gap: 0.8rem; } /* Adjusted gap */
            .group-card { padding: 0.8rem 0.5rem 0.5rem 0.5rem; min-height: 90px; } /* Adjusted padding/min-height */
            .group-btn { font-size: 1.1rem; padding: 0.6rem 0.3rem; } /* Adjusted font/padding */
            .group-btn i { font-size: 1.4rem; margin-right: 0.5rem; } /* Adjusted font/margin */
            .group-card .group-label { font-size: 0.9rem; margin-bottom: 0.3rem; } /* Adjusted font/margin */
        }
        @media (min-width: 768px) {
            .group-grid { grid-template-columns: repeat(3, 1fr); gap: 1rem; } /* Adjusted gap */
            .group-card { padding: 1rem 0.7rem 0.7rem 0.7rem; min-height: 100px; } /* Adjusted padding/min-height */
            .group-btn { font-size: 1.2rem; padding: 0.7rem 0.4rem; } /* Adjusted font/padding */
            .group-btn i { font-size: 1.5rem; margin-right: 0.6rem; } /* Adjusted font/margin */
            .group-card .group-label { font-size: 0.95rem; margin-bottom: 0.4rem; } /* Adjusted font/margin */
        }
        @media (min-width: 992px) {
            .group-grid { grid-template-columns: repeat(4, 1fr); gap: 1.2rem; } /* Adjusted gap */
            .group-card { padding: 1.2rem 0.8rem 0.8rem 0.8rem; min-height: 110px; } /* Adjusted padding/min-height */
            .group-btn { font-size: 1.3rem; padding: 0.8rem 0.5rem; } /* Adjusted font/padding */
            .group-btn i { font-size: 1.6rem; margin-right: 0.7rem; } /* Adjusted font/margin */
            .group-card .group-label { font-size: 1rem; margin-bottom: 0.5rem; } /* Adjusted font/margin */
        }
        /* Remove previous media queries that conflict with the new approach */
        /*
        @media (max-width: 1200px) {
            .group-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 900px) {
            .group-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 600px) {
            .group-grid { grid-template-columns: 1fr; }
        }
        */
        @media (max-width: 768px) {
            .main-header h1 { font-size: 1.8rem; } /* Further reduced */
            .main-header p { font-size: 0.9rem; } /* Further reduced */
            h2 { font-size: 1.2rem; } /* Further reduced */
            .main-content { padding: 0.8rem 0.3rem; } /* Further reduced */
        }
         @media (max-width: 576px) {
            .group-grid { gap: 0.6rem; } /* Adjusted gap */
            .group-card { padding: 0.6rem 0.4rem 0.4rem 0.4rem; min-height: 80px; } /* Adjusted padding/min-height */
            .group-btn { font-size: 0.9rem; padding: 0.5rem 0.1rem; } /* Adjusted font/padding */
            .group-btn i { font-size: 1rem; margin-right: 0.3rem; } /* Adjusted font/margin */
            .group-card .group-label { font-size: 0.8rem; margin-bottom: 0.1rem; } /* Adjusted font/margin */
        }
    </style>
</head>
<body>
    <!-- Header Section -->
    <header class="main-header">
        <div class="container">
            <h1><i class="fas fa-dolly"></i> ระบบ Emergency Picking (Operation TTV AAT-Wiring)</h1>
            <p>สำหรับพนักงานหยิบงาน</p>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <h2 class="text-center">เลือกกลุ่มงานที่ต้องการ</h2>
            <?php if (empty($defined_groups)): ?>
                <div class="alert alert-info text-center" role="alert">
                    <i class="fas fa-info-circle"></i> ไม่พบกลุ่มงานที่กำหนดไว้ กรุณาติดต่อผู้ดูแลระบบ
                </div>
            <?php else: ?>
                <div class="group-grid">
                    <?php foreach ($defined_groups as $group): ?>
                        <?php
                            // สร้างชื่อไฟล์ PHP สำหรับกลุ่มงาน (เช่น group01.php, group08l.php)
                            $group_file_name = strtolower(str_replace(' ', '', $group)) . '.php';
                            $group_url = htmlspecialchars($group_file_name) . '?group=' . urlencode($group);
                        ?>
                        <div class="group-card">
                            <div class="group-label">กลุ่มงาน</div>
                            <a href="#" onclick="openInPrivate(event, '<?php echo $group_url; ?>')" class="group-btn" tabindex="0">
                                <i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($group); ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; <?php echo date("Y"); ?> Emergency Picking System - By Gan Anchaleepon</p>
        </div>
    </footer>

    <!-- Modal for InPrivate Browsing -->
    <div class="modal fade" id="private-window-modal" tabindex="-1" role="dialog" aria-labelledby="privateModalLabel" aria-hidden="true">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="privateModalLabel"><i class="fas fa-user-secret"></i> เปิดในโหมดส่วนตัว (InPrivate)</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <p>เพื่อป้องกันปัญหาการจำค่าเก่าในระบบ (caching) กรุณาเปิดลิงก์นี้ในหน้าต่างใหม่แบบส่วนตัว (InPrivate/Incognito) ครับ</p>
            <p><strong>ขั้นตอน:</strong></p>
            <ol>
              <li>คัดลอกลิงก์ด้านล่างนี้</li>
              <li>เปิดหน้าต่างเบราว์เซอร์ใหม่ในโหมด InPrivate หรือ Incognito (ปกติจะใช้คีย์ลัด <strong>Ctrl+Shift+N</strong> หรือ <strong>Ctrl+Shift+P</strong>)</li>
              <li>วางลิงก์ที่คัดลอกแล้วกด Enter</li>
            </ol>
            <div class="input-group mb-3">
              <input type="text" class="form-control" id="private-link" readonly>
              <div class="input-group-append">
                <button class="btn btn-outline-secondary" type="button" id="copy-link-btn">คัดลอกลิงก์</button>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">ปิด</button>
          </div>
        </div>
      </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        function openInPrivate(event, url) {
            event.preventDefault();
            
            const modal = $('#private-window-modal');
            const linkElement = document.getElementById('private-link');
            const copyButton = document.getElementById('copy-link-btn');

            // Construct the full URL by taking the current path and replacing the file name
            const currentPath = window.location.href.substring(0, window.location.href.lastIndexOf('/') + 1);
            const fullUrl = currentPath + url;
            linkElement.value = fullUrl;
            
            modal.modal('show');

            copyButton.onclick = function() {
                linkElement.select();
                document.execCommand('copy');
                copyButton.textContent = 'คัดลอกแล้ว!';
                copyButton.classList.remove('btn-outline-secondary');
                copyButton.classList.add('btn-success');
                setTimeout(() => {
                    copyButton.textContent = 'คัดลอกลิงก์';
                    copyButton.classList.remove('btn-success');
                    copyButton.classList.add('btn-outline-secondary');
                }, 2000);
            };
        }
    </script>
</body>
</html>