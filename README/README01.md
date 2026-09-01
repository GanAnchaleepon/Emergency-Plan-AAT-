# Emergency Picking System (Project Overview)  
<th>ระบบ Emergency Picking (ภาพรวมโครงการ)</th>

---

## 1. Purpose  
<th>1. วัตถุประสงค์</th>

To provide a fast, error-proof, and traceable emergency picking system for warehouse or production environments.  
<th>เพื่อสร้างระบบหยิบฉุกเฉิน (Emergency Picking) ที่รวดเร็ว ตรวจสอบได้ และลดข้อผิดพลาดในคลังสินค้า หรือไลน์ผลิต</th>

This system helps ensure that all emergency picking operations follow correct rotation, minimize human error, and provide a complete history for audit and analysis.  
<th>ระบบนี้จะช่วยให้ทุกขั้นตอนของการหยิบงานเป็นไปตามลำดับ (Rotation) ถูกต้อง ลดข้อผิดพลาดจากมนุษย์ และเก็บประวัติย้อนหลังเพื่อการตรวจสอบ/วิเคราะห์</th>

---

## 2. Scope  
<th>2. ขอบเขตการพัฒนา</th>

- **Admin Portal**  
  <th>ส่วนของผู้ดูแลระบบ (Admin Portal)</th>
  - Secure Login for Admins only  
    <th>มีระบบล็อกอินสำหรับแอดมินเท่านั้น</th>
  - CSV picking plan upload and data validation  
    <th>อัปโหลดไฟล์แผนงาน (CSV) พร้อมตรวจสอบความถูกต้องของข้อมูล</th>
  - History of uploaded files and logs  
    <th>ดูประวัติการอัปโหลดไฟล์และบันทึกกิจกรรม</th>
  - Management dashboard for all groups  
    <th>มี Dashboard แสดงสถานะของทุกกลุ่ม</th>
  - Bulk sticker label printing by work group  
    <th>พิมพ์สติกเกอร์ลาเบลทั้งหมดตามกลุ่มงาน</th>

- **Group/Picker Portal**  
  <th>ส่วนของกลุ่มงาน/ผู้หยิบ (Group/Picker Portal)</th>
  - No login required  
    <th>ไม่ต้องล็อกอิน ใช้งานได้ทันที</th>
  - Select available Group to start picking  
    <th>เลือกกลุ่มที่ต้องการเริ่มงานได้เลย</th>
  - Guided, step-by-step workflow with full validation  
    <th>มีขั้นตอนการหยิบงานแบบแนะนำทีละขั้นตอน พร้อมตรวจสอบความถูกต้อง</th>
  - Real-time progress for each group  
    <th>แสดงความคืบหน้าของแต่ละกลุ่มแบบ Real-Time</th>

- **System-wide**  
  <th>ฟังก์ชันหลักของทั้งระบบ</th>
  - Error handling, duplicate detection, and reporting  
    <th>มีระบบจัดการข้อผิดพลาด ตรวจสอบข้อมูลซ้ำ และรายงานผล</th>
  - Label printing (single and bulk) and dolly summary generation  
    <th>พิมพ์ป้าย (Label) (เดี่ยวและทั้งหมด) และสรุปรายงาน Dolly</th>
  - Traceable log for all activities  
    <th>เก็บประวัติทุกกิจกรรมเพื่อความตรวจสอบย้อนหลัง</th>

---

## 3. User Roles  
<th>3. สิทธิ์การใช้งาน</th>

- **Admin**  
  <th>แอดมิน</th>
  - Access to login-protected admin dashboard  
    <th>เข้าหน้า Admin Dashboard (ต้องล็อกอิน)</th>
  - Can upload/edit CSV plans, view all histories, and monitor all groups  
    <th>อัปโหลด/แก้ไขแผนงาน, ดูประวัติ, และตรวจสอบสถานะกลุ่มทั้งหมด</th>
- **Picker**  
  <th>พนักงานหยิบงาน (Picker)</th>
  - No login required  
    <th>ไม่ต้องล็อกอิน</th>
  - Only sees/selects active groups and performs guided picking process  
    <th>เลือกกลุ่มที่มีงานและดำเนินการหยิบงานตามขั้นตอนที่ระบบแนะนำ</th>

---

## 4. Key Features  
<th>4. ฟีเจอร์หลักของระบบ</th>

- **Admin Login**  
  <th>ล็อกอินสำหรับแอดมิน</th>
- Upload and validate CSV picking plans  
  <th>อัปโหลดและตรวจสอบแผนงาน (CSV)</th>
- Automated duplicate detection with reporting  
  <th>ตรวจสอบข้อมูลซ้ำและรายงานอัตโนมัติ</th>
- Real-time dashboard of picking progress (by group)  
  <th>Dashboard แสดงความคืบหน้าแยกตามกลุ่มแบบ Real-Time</th>
- Full activity log and history  
  <th>บันทึกประวัติและกิจกรรมทั้งหมด</th>
- Guided picking steps (select group, position, scan, confirm, print label, etc.)  
  <th>ขั้นตอนหยิบงานแบบมีระบบช่วยนำทาง</th>
- Printable labels (single and bulk) and group summary  
  <th>พิมพ์ป้าย (Label) (เดี่ยวและทั้งหมด) และรายงานสรุป</th>
- Secure and traceable process for audits  
  <th>ระบบปลอดภัยและตรวจสอบย้อนหลังได้</th>

---

## 5. Technologies  
<th>5. เทคโนโลยีที่ใช้</th>

- Web-based (PHP/MySQL on Hostinger)  
  <th>ระบบเว็บ (PHP/MySQL บน Hostinger)</th>
- Compatible with desktop and mobile barcode scanner workflow  
  <th>ใช้งานได้ทั้งคอมพิวเตอร์และเครื่องสแกนบาร์โค้ดมือถือ</th>
- CSV input, PDF/Label output  
  <th>รองรับไฟล์ CSV และพิมพ์ออกเป็น PDF/Label</th>

---

## 6. Success Criteria  
<th>6. ตัวชี้วัดความสำเร็จของโครงการ</th>

- Every picking event is fully validated and traceable  
  <th>ทุกกิจกรรมหยิบงานได้รับการตรวจสอบและติดตามย้อนหลังได้</th>
- Errors and duplicates are prevented or logged for investigation  
  <th>ข้อมูลผิดพลาดหรือซ้ำจะถูกป้องกันหรือบันทึกไว้ตรวจสอบ</th>
- Admin and pickers can operate the system with minimal training  
  <th>แอดมินและพนักงานสามารถใช้งานระบบได้ง่าย ไม่ต้องอบรมนาน</th>

---

*End of README01*  
<th>จบ README01</th>
