# Emergency Picking System – Development Plan  
<th>ระบบ Emergency Picking – แผนการพัฒนา</th>

---

## 1. Development Milestones  
<th>1. แผนงานหลัก (Milestone)</th>

- **Milestone 1: System Structure & Database Setup**  
  <th>ตั้งค่าโครงสร้างระบบและฐานข้อมูล</th>
- **Milestone 2: Admin Portal (Login & CSV Upload)**  
  <th>พัฒนาโมดูลผู้ดูแลระบบ (ล็อกอิน & อัปโหลดไฟล์ CSV)</th>
- **Milestone 3: Picker Portal (Group Workflow)**  
  <th>พัฒนาโมดูลฝั่งผู้หยิบงาน (เลือกกลุ่ม/ขั้นตอนหยิบ)</th>
- **Milestone 4: Dashboard & History**  
  <th>สร้าง Dashboard และระบบประวัติ/ติดตามงาน</th>
- **Milestone 5: Label/Report Print & Export (Single & Bulk)**  
  <th>ฟังก์ชันพิมพ์ป้าย/ใบสรุปและส่งออกข้อมูล (เดี่ยวและทั้งหมด)</th>
- **Milestone 6: Error Handling, Logging, and Final Testing**  
  <th>ระบบแจ้งเตือน/ตรวจสอบข้อผิดพลาด, บันทึกกิจกรรม, ทดสอบรวม</th>
- **Milestone 7: Go-live & Training**  
  <th>เปิดใช้งานจริงและอบรมผู้ใช้งาน</th>

---

## 2. Development Steps  
<th>2. ขั้นตอนการพัฒนา</th>

### Step 1: Setup & Planning  
<th>วางแผนและเซ็ตระบบเบื้องต้น</th>
- Define project folder structure  
  <th>กำหนดโฟลเดอร์/โครงสร้างไฟล์ระบบ</th>
- Setup database and tables  
  <th>สร้างฐานข้อมูลและตารางที่ใช้</th>
- Setup version control (Git, etc.)  
  <th>ตั้งค่าระบบจัดการเวอร์ชั่น (Git ฯลฯ)</th>

### Step 2: Admin Portal  
<th>พัฒนาโมดูลผู้ดูแลระบบ</th>
- Create login system (admin only)  
  <th>สร้างระบบล็อกอิน (เฉพาะแอดมิน)</th>
- CSV upload page, format validation, duplicate detection  
  <th>หน้าสำหรับอัปโหลด CSV, ตรวจรูปแบบไฟล์, เช็คข้อมูลซ้ำ</th>
- Store upload logs/history  
  <th>บันทึกประวัติการอัปโหลดและ log</th>
- Manual download of CSV template  
  <th>ให้ดาวน์โหลดไฟล์ตัวอย่าง CSV ได้</th>

### Step 3: Group/Picker Portal  
<th>พัฒนาโมดูลสำหรับผู้หยิบงาน</th>
- Show available Groups from last uploaded plan  
  <th>แสดงรายชื่อกลุ่มจากไฟล์แผนงานล่าสุด</th>
- Workflow: Select group → select position → scan/check → print label → log step  
  <th>ขั้นตอนหยิบงาน: เลือกกลุ่ม → เลือกตำแหน่ง → สแกน/เช็ค → พิมพ์ Label → Log</th>
- Validate each scan/step and log result  
  <th>ตรวจสอบข้อมูลทุกขั้นตอนและบันทึกผล</th>

### Step 4: Dashboard & History  
<th>สร้าง Dashboard และประวัติ</th>
- Dashboard shows status, progress for all groups  
  <th>Dashboard แสดงสถานะ/ความคืบหน้าของแต่ละกลุ่ม</th>
- View history of uploads, logs, errors  
  <th>ดูประวัติไฟล์ที่อัปโหลด, log, ข้อผิดพลาดย้อนหลัง</th>
- Option to export summary/log  
  <th>สามารถ export สรุป/ประวัติได้</th>

### Step 5: Print & Export  
<th>ฟังก์ชันพิมพ์ป้าย/ใบสรุปและส่งออกข้อมูล</th>
- Print rotation labels after correct pick  
  <th>พิมพ์ป้าย Rotation หลังหยิบงานถูกต้อง</th>
- Develop bulk sticker printing page (select group, label size, generate PDF)  
  <th>พัฒนาหน้าพิมพ์สติกเกอร์ทั้งหมด (เลือกกลุ่ม, ขนาด, สร้าง PDF)</th>
- Print dolly summary after all positions are picked  
  <th>พิมพ์ใบสรุป Dolly หลังครบทุกตำแหน่ง</th>
- Export pick data/report as CSV or PDF  
  <th>ส่งออกข้อมูล/รายงานเป็นไฟล์ CSV หรือ PDF</th>

### Step 6: Error Handling & Testing  
<th>ทดสอบระบบและตรวจสอบข้อผิดพลาด</th>
- System checks for all common errors (format, duplicate, invalid scan, etc.)  
  <th>ระบบตรวจสอบข้อผิดพลาดที่พบบ่อย (ไฟล์ผิดรูปแบบ, ข้อมูลซ้ำ, สแกนไม่ตรง ฯลฯ)</th>
- Logging for all activities and exceptions  
  <th>บันทึก log ทุกกิจกรรมและเหตุการณ์ผิดปกติ</th>
- End-to-end user acceptance testing  
  <th>ทดสอบระบบภาพรวมกับผู้ใช้งานจริง</th>

### Step 7: Go-live & Training  
<th>เปิดใช้งานจริงและอบรม</th>
- Deploy to production environment (Hostinger)  
  <th>นำขึ้นใช้งานจริง (Hostinger)</th>
- Train admin and pickers  
  <th>อบรมแอดมินและพนักงานหยิบงาน</th>
- Monitor first period, collect feedback  
  <th>เฝ้าระวังช่วงแรก รับฟังและปรับปรุงตาม feedback</th>

---

## 3. Task Prioritization Table  
<th>3. ตารางลำดับความสำคัญงาน</th>

| Priority | Module        | Task Description                                   | Owner  |
|----------|--------------|----------------------------------------------------|--------|
| 1        | Setup        | Folder structure, DB, version control              | Dev    |
| 2        | Admin        | Login, CSV upload, validation, duplicate check     | Dev    |
| 3        | Picker       | Group selection, picking flow, validation, logging | Dev    |
| 4        | Dashboard    | Progress view, log/history                         | Dev    |
| 5        | Print/Export | Label (single), summary, export                    | Dev    |
| 5.1      | Admin/Print  | Bulk sticker printing (select group, size, PDF gen)| Dev    |
| 6        | QA/Testing   | Error handling, user testing                       | Dev/QC |
| 7        | Go-live      | Deploy, training, feedback                         | All    |

<th>
| ลำดับ | โมดูล     | รายละเอียดงาน                               | ผู้รับผิดชอบ |
|-------|----------|-----------------------------------------------|-------------|
| 1     | Setup    | โฟลเดอร์, ฐานข้อมูล, ระบบเวอร์ชัน           | Dev         |
| 2     | Admin    | ล็อกอิน, อัปโหลด CSV, ตรวจสอบข้อมูล          | Dev         |
| 3     | Picker   | เลือกกลุ่ม, ขั้นตอนหยิบ, บันทึก, ตรวจสอบ     | Dev         |
| 4     | Dashboard| ดูความคืบหน้า, ประวัติ/Log                  | Dev         |
| 5     | Print/Export | พิมพ์ป้าย (เดี่ยว), รายงาน, ส่งออกข้อมูล   | Dev         |
| 5.1   | Admin/Print| พิมพ์สติกเกอร์ทั้งหมด (เลือกกลุ่ม, ขนาด, สร้าง PDF)| Dev         |
| 6     | ทดสอบ/QA | ตรวจสอบข้อผิดพลาด, ทดสอบกับผู้ใช้จริง        | Dev/QC      |
| 7     | Go-live  | เปิดใช้งาน, อบรม, รับฟัง feedback            | ทุกฝ่าย     |
</th>

---

## 4. Notes & Adjustments  
<th>4. หมายเหตุและการปรับปรุง</th>

- Tasks can be run in parallel if team size allows  
  <th>บางงานสามารถทำพร้อมกันได้ ถ้าทีมงานมีหลายคน</th>
- Plan is subject to change based on business/user feedback  
  <th>แผนอาจมีการปรับปรุงตาม feedback ของผู้ใช้จริง</th>
- Regular review and progress updates required  
  <th>ควรรีวิวความคืบหน้าและอัปเดตสถานะเป็นระยะ</th>

---

*End of README03*  
<th>จบ README03</th>
