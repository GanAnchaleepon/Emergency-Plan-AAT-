# Emergency Picking System – Workflow & Flowchart  
<th>ระบบ Emergency Picking – ขั้นตอนการทำงานและ Flowchart</th>

---

## 1. System Flow Overview  
<th>1. ภาพรวมการไหลของระบบ</th>

The system workflow is divided into three main modules:
1. **Admin Module** – CSV Upload & Management
2. **Group/Picker Module** – Guided Picking Process
3. **Dashboard & History Module** – Progress and Traceability

<th>
ระบบแบ่งขั้นตอนหลักออกเป็น 3 ส่วน:
1. ส่วนแอดมิน (อัปโหลดและจัดการไฟล์ CSV)
2. ส่วนกลุ่มงาน/พนักงานหยิบ (ขั้นตอนหยิบงาน)
3. ส่วน Dashboard และประวัติ (ติดตามความคืบหน้าและตรวจสอบย้อนหลัง)
</th>

---

## 2. System Flowchart (Text)

[Start]
|
v
[Admin Login]
|
v
[Upload CSV Picking Plan] <-- (Admin)
|
|--(System validates & checks duplicates)
|
v
[Process & Store Valid Data]
|
v
[Display Groups on Main Page]
|
v
[Picker selects Group] <-- (No login)
|
v
[Picker selects Position (1,2,3...)]
|
v
[Show Rotation & Part Details]
|
v
[Scan Part_Number]
|
|
| (If match?)--------------------No---------------------------Yes
| | |
| [Show error] [Print Rotation Label]
| [Block process] [Scan Box/Lot]
| [Scan Rotation Label]
| [Scan Position]
| [Log all activity]
| [Repeat until complete]
|
v
[Print Dolly Summary]
|
v
[Dashboard & History (Progress, Audit, Export)]
|
v
[End]


---

## 3. Step-by-Step Flow Description  
<th>3. คำอธิบายแต่ละขั้นตอน</th>

### 3.1 Admin Module  
<th>3.1 ส่วนแอดมิน</th>

- **Login**: Only admin can access upload & management functions  
  <th>ล็อกอินก่อนเข้าหน้าอัปโหลด/จัดการข้อมูล</th>
- **Upload CSV**: System validates CSV structure, checks for duplicate rows  
  <th>อัปโหลดไฟล์ CSV ระบบตรวจสอบรูปแบบไฟล์และข้อมูลซ้ำ</th>
- **Validation/Logging**: Invalid or duplicate rows are skipped, reported, and logged  
  <th>ถ้ามีข้อมูลผิด/ซ้ำ ระบบจะข้ามและแจ้งเตือนพร้อมเก็บประวัติ</th>
- **Data Process**: Valid data is sorted by group/rotation and stored  
  <th>ข้อมูลที่ผ่านจะถูกแยกกลุ่มและเรียงลำดับไว้ในระบบ</th>

---

### 3.2 Picker/Group Module  
<th>3.2 ส่วนกลุ่มงาน/พนักงานหยิบ</th>

- **Select Group**: Picker (no login) chooses which group to work with  
  <th>เลือกกลุ่มที่ต้องการหยิบงาน (ไม่ต้องล็อกอิน)</th>
- **Select Position**: Enter/select position number (1,2,3,...)  
  <th>เลือกหมายเลขจุดวางงาน (Position)</th>
- **Show Details**: System shows current Rotation, Part_Number, etc.  
  <th>ระบบแสดงรายละเอียดของงานที่ต้องหยิบ</th>
- **Scan Part_Number**: Picker scans actual part  
  <th>สแกนรหัส Part_Number จริง</th>
    - If correct, proceed to label printing  
      <th>หากตรง ให้ไปพิมพ์ Label ต่อ</th>
    - If incorrect, block and show error  
      <th>หากไม่ตรง แจ้งเตือนและไม่ให้ไปต่อ</th>
- **Print & Scan Labels/Lot**: Print rotation label, scan Box/Lot, scan label, scan position  
  <th>พิมพ์ Label, สแกนกล่อง/ล็อต, สแกน Label, สแกน Position ตามลำดับ</th>
- **Repeat**: Process repeats for each position until group is complete  
  <th>ทำซ้ำทุก Position จนจบกลุ่ม</th>
- **Summary**: Print and save Dolly summary  
  <th>พิมพ์และบันทึกใบสรุป Dolly</th>

---

### 3.3 Dashboard & History  
<th>3.3 ส่วน Dashboard และประวัติ</th>

- **View Progress**: Real-time display of progress for all groups  
  <th>แสดงความคืบหน้าของแต่ละกลุ่มแบบ Real-Time</th>
- **Export/History**: Export data, view logs, review all activity and errors  
  <th>สามารถ Export, ดูประวัติการอัปโหลด/หยิบงาน/ข้อผิดพลาดย้อนหลังได้</th>
- **Audit Ready**: All activities are traceable for audit  
  <th>สามารถตรวจสอบย้อนหลังได้ทุกกิจกรรม</th>

---

## 4. System Logic Rules  
<th>4. กติกา/เงื่อนไขสำคัญของระบบ</th>

- Only admin can upload or view all group dashboards  
  <th>มีแค่แอดมินที่อัปโหลดไฟล์หรือดู dashboard รวมได้</th>
- Pickers never see admin tools and never log in  
  <th>Picker ไม่ต้องล็อกอิน และไม่เห็นหน้าจอแอดมิน</th>
- Duplicates are detected at upload and skipped  
  <th>ข้อมูลซ้ำถูกตรวจสอบตั้งแต่ตอนอัปโหลดและจะข้ามไม่ใช้งาน</th>
- Each scan is validated; if not matched, cannot proceed  
  <th>ทุกจุดที่สแกนต้องถูกต้อง ถ้าไม่ตรง จะไม่สามารถไปขั้นตอนถัดไปได้</th>
- Activity is logged at every step  
  <th>ระบบบันทึกกิจกรรมทุกขั้นตอนเพื่อการตรวจสอบย้อนหลัง</th>

---

## 5. Diagram Reference  
<th>5. แผนภาพประกอบ (ดู Flowchart ภาพประกอบ)</th>

See [system flowchart image](../path/to/your_flowchart_image.png) for the full graphical overview.

<th>ดูแผนภาพ Flowchart ที่แนบ เพื่อเข้าใจภาพรวมของระบบ</th>

---

*End of README02*  
<th>จบ README02</th>

