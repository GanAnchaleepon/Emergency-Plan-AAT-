# Emergency Picking System – Additional Information  
<th>ระบบ Emergency Picking – ข้อมูลเพิ่มเติม</th>

---

## 1. Database Information  
<th>1. ข้อมูลฐานข้อมูล</th>

- **Server**: `127.0.0.1:3306`
- **Database Name**: `u892799997_emergency_plan`
- **MySQL User**: `u892799997_emergency_plan`
- **Password**: `Gan05061997@`

> **Note:**  
> - These credentials are for system development & deployment on Hostinger (https://hpanel.hostinger.com/).  
> - For security, please update the password after initial setup and never commit sensitive credentials in public repositories.

<th>
> **หมายเหตุ**  
> - ข้อมูลนี้ใช้สำหรับการพัฒนาระบบและนำขึ้น Hostinger  
> - เพื่อความปลอดภัยควรเปลี่ยนรหัสผ่านหลังตั้งค่าครั้งแรก และไม่ควร commit รหัสผ่านลง repository สาธารณะ
</th>

---

## 2. Hosting / Environment  
<th>2. โฮสติ้ง/สภาพแวดล้อม</th>

- Host: [Hostinger hPanel](https://hpanel.hostinger.com/)
- Environment supports PHP (MySQLi/PDO), recommended for PHP+MySQL systems.
- **Not confirmed:** Some advanced Python/Node.js features may not be supported (confirm in hPanel).

<th>
- โฮสต์ที่ใช้คือ Hostinger hPanel  
- รองรับ PHP (MySQLi/PDO) ซึ่งเหมาะกับระบบที่วางแผนไว้  
- **หมายเหตุ:** ฟีเจอร์ Python หรือ Node.js อาจใช้งานไม่ได้ ต้องตรวจสอบกับ hPanel
</th>

---

## 3. Group List  
<th>3. รายชื่อกลุ่ม (Group) ที่ใช้ในระบบ</th>

- GROUP 01  
- GROUP 02  
- GROUP 03  
- GROUP 04  
- GROUP 05  
- GROUP 06  
- GROUP 07  
- GROUP 08L  
- GROUP 08R  
- GROUP 09  
- GROUP 10  
- GROUP 11L  
- GROUP 11R  
- GROUP 12  
- GROUP 13  
- GROUP 14  

<th>
- กลุ่มงานทั้งหมดที่ระบบต้องรองรับ (รวม L/R ในบางกลุ่ม)
</th>

---

## 4. Remarks / Implementation Notes  
<th>4. หมายเหตุสำหรับการติดตั้ง/พัฒนา</th>

- Always use prepared statements or ORM to prevent SQL injection.
- Create DB tables using UTF8MB4 encoding for multilingual support.
- For Hostinger, make sure to set up `.env` or equivalent config file for DB credentials (never hard-code in production).
- If you plan to use scheduled tasks (cron jobs), verify Hostinger's cron support.

<th>
- ควรใช้ prepared statement หรือ ORM ป้องกัน SQL injection  
- ตั้งค่าฐานข้อมูลเป็น UTF8MB4 เพื่อรองรับหลายภาษา  
- ใส่ข้อมูล config ไว้ในไฟล์ .env หรือ config แยก (อย่าฝังรหัสผ่านในโค้ด)  
- ถ้าจะตั้ง Cron Job ต้องเช็คว่า Hostinger รองรับหรือไม่
</th>

---

*End of README04*  
<th>จบ README04</th>
