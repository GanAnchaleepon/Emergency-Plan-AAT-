<?php
// import_master_form.php
// หน้าเว็บสำหรับอัปโหลดไฟล์ Master และเรียกใช้งาน import_master.php
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Import Master CSV</title>
</head>
<body>
    <h2>อัปโหลดไฟล์ Master (ABT1755565050560.csv)</h2>
    <form id="uploadForm" action="" method="post" enctype="multipart/form-data">
        <input type="file" name="master_csv" accept=".csv" required>
        <button type="submit">อิมพอร์ต</button>
        <div id="progressContainer" style="display:none; margin-top:15px;">
            <progress id="progressBar" value="0" max="100" style="width:300px;"></progress>
            <span id="progressText">0%</span>
        </div>
    </form>
    <script>
    document.getElementById('uploadForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var form = e.target;
        var fileInput = form.master_csv;
        if (!fileInput.files.length) return;
        var file = fileInput.files[0];
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '', true);
        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                var percent = Math.round((e.loaded / e.total) * 100);
                document.getElementById('progressContainer').style.display = 'block';
                document.getElementById('progressBar').value = percent;
                document.getElementById('progressText').textContent = percent + '%';
            }
        };
        xhr.onload = function() {
            if (xhr.status === 200) {
                document.getElementById('progressText').textContent = 'อัปโหลดเสร็จสิ้น!';
                document.getElementById('progressBar').value = 100;
                document.getElementById('progressContainer').style.display = 'block';
                // reload page to show import result
                setTimeout(function(){ location.reload(); }, 1200);
            } else {
                document.getElementById('progressText').textContent = 'เกิดข้อผิดพลาด';
            }
        };
        var formData = new FormData(form);
        xhr.send(formData);
    });
    </script>
    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['master_csv'])) {
        $target = 'ABT1755565050560.csv';
        if (move_uploaded_file($_FILES['master_csv']['tmp_name'], $target)) {
            echo "<p>อัปโหลดไฟล์เรียบร้อย กำลังนำเข้า...</p>";
            // เรียกใช้งาน import_master.php
            include 'import_master.php';
        } else {
            echo "<p>เกิดข้อผิดพลาดในการอัปโหลดไฟล์</p>";
        }
    }
    ?>
</body>
</html>
