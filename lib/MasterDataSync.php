<?php
/**
 * ดึง Master Data จาก FTM SEQ API แล้วเขียนลงตาราง master_data
 * ใช้แทนการอัปโหลดไฟล์ CSV ด้วยมือ (ให้ผลลัพธ์เหมือนกัน)
 */
require_once __DIR__ . '/FtmSeqClient.php';

final class MasterDataSync
{
    private $conn;
    private $config;

    public function __construct(mysqli $conn, array $config)
    {
        $this->conn = $conn;
        $this->config = $config;
    }

    /**
     * ดึงข้อมูลและบันทึกลงฐานข้อมูล
     * มี lock กันการดึงซ้อนกัน — ถ้ามีรอบอื่นทำงานอยู่จะโยน exception
     */
    public function run(string $trigger = 'manual'): array
    {
        $lock = $this->acquireLock();

        try {
            $master = $this->config['master'];
            $client = new FtmSeqClient($this->config);
            $rows = $client->fetchData($master['endpoint'], $master['fields']);

            $records = $this->mapRows($rows, $master['map']);
            if (empty($records)) {
                throw new RuntimeException('API ส่งข้อมูลมา ' . count($rows) . ' แถว แต่ไม่มีแถวไหนใช้ได้เลย (part_number ว่างทั้งหมด)');
            }

            $stats = $this->replaceMasterData($records);
            $stats['fetched'] = count($rows);
            $stats['status'] = 'success';
            $stats['message'] = "ดึงสำเร็จ {$stats['fetched']} แถว | บันทึก {$stats['imported']} รายการ | ซ้ำ {$stats['duplicate']} รายการ";
            $stats['trigger'] = $trigger;
            $stats['time'] = time();

            $this->logUpload($stats['imported']);
            $this->saveState($stats);

            return $stats;
        } catch (Throwable $e) {
            $this->saveState([
                'status'  => 'error',
                'message' => $e->getMessage(),
                'trigger' => $trigger,
                'time'    => time(),
            ]);
            throw $e;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** แปลงข้อมูลดิบจาก API เป็นแถวที่พร้อมบันทึก */
    private function mapRows(array $rows, array $map): array
    {
        if (empty($rows)) {
            throw new RuntimeException('API ตอบสำเร็จแต่ไม่มีข้อมูล (0 แถว)');
        }

        $first = (array)reset($rows);
        $resolved = [];
        foreach ($map as $column => $candidates) {
            $resolved[$column] = $this->resolveKey($first, (array)$candidates);
        }

        if ($resolved['part_number'] === null) {
            throw new RuntimeException(
                'ไม่พบฟิลด์ part_number ใน API — ฟิลด์ที่ API ส่งมาคือ: ' . implode(', ', array_keys($first))
                . ' | กรุณาแก้ค่า map ใน config/ftmseq.php'
            );
        }

        $records = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            $partNumber = $this->normalizePartNumber($this->value($row, $resolved['part_number']));
            if ($partNumber === '') {
                continue;
            }
            $records[] = [
                'part_group'    => $this->clean($this->value($row, $resolved['part_group'])),
                'part_number'   => $partNumber,
                'supplier'      => $this->clean($this->value($row, $resolved['supplier'])),
                'location_pick' => $this->clean($this->value($row, $resolved['location_pick'])),
            ];
        }

        return $records;
    }

    /** ล้างตารางเดิมแล้วเขียนชุดใหม่ในทรานแซกชันเดียว */
    private function replaceMasterData(array $records): array
    {
        $this->conn->begin_transaction();

        try {
            $this->conn->query("DELETE FROM master_data");

            $stmt = $this->conn->prepare(
                "INSERT INTO master_data (part_group, part_number, supplier, location_pick) VALUES (?, ?, ?, ?)"
            );
            if (!$stmt) {
                throw new RuntimeException('เตรียมคำสั่ง INSERT ไม่สำเร็จ: ' . $this->conn->error);
            }
            $stmt->bind_param('ssss', $partGroup, $partNumber, $supplier, $locationPick);

            $imported = 0;
            $duplicate = 0;
            $seen = [];

            foreach ($records as $record) {
                $key = $record['part_group'] . '|' . $record['part_number'] . '|' . $record['supplier'];
                if (isset($seen[$key])) {
                    $duplicate++;
                    continue;
                }
                $seen[$key] = true;

                $partGroup    = $record['part_group'];
                $partNumber   = $record['part_number'];
                $supplier     = $record['supplier'];
                $locationPick = $record['location_pick'];

                if ($stmt->execute()) {
                    $imported++;
                }
            }
            $stmt->close();

            $this->conn->commit();

            return ['imported' => $imported, 'duplicate' => $duplicate];
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    private function logUpload(int $rowCount): void
    {
        $filename = 'FTM SEQ API (' . $rowCount . ' รายการ)';
        $filedate = date('Y-m-d H:i:s');
        $stmt = $this->conn->prepare("INSERT INTO master_uploads (filename, filedate) VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param('ss', $filename, $filedate);
            $stmt->execute();
            $stmt->close();
        }
    }

    /** รอบถัดไปที่ถึงกำหนดแล้วแต่ยังไม่ได้ดึง คืน null ถ้ายังไม่ถึงเวลา */
    public function dueSlot(): ?string
    {
        $master = $this->config['master'];
        $lastRun = (int)($this->readState()['time'] ?? 0);
        $now = time();
        $grace = (int)$master['schedule_grace_minutes'] * 60;

        $due = null;
        foreach ($master['schedule'] as $slot) {
            $slotTime = strtotime(date('Y-m-d ') . $slot);
            if ($slotTime > $now || ($now - $slotTime) > $grace || $lastRun >= $slotTime) {
                continue;
            }
            if ($due === null || $slotTime > strtotime(date('Y-m-d ') . $due)) {
                $due = $slot;
            }
        }

        return $due;
    }

    /** เวลา (unix) ของรอบดึงอัตโนมัติรอบถัดไป */
    public function nextSlotTime(): int
    {
        $now = time();
        $candidates = [];
        foreach ($this->config['master']['schedule'] as $slot) {
            $candidates[] = strtotime(date('Y-m-d ') . $slot);
            $candidates[] = strtotime(date('Y-m-d ', strtotime('+1 day')) . $slot);
        }
        sort($candidates);

        foreach ($candidates as $time) {
            if ($time > $now) {
                return $time;
            }
        }

        return 0;
    }

    public function readState(): array
    {
        $file = $this->config['state_file'];

        return is_file($file) ? (array)json_decode((string)file_get_contents($file), true) : [];
    }

    private function saveState(array $state): void
    {
        $file = $this->config['state_file'];
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /** @return resource */
    private function acquireLock()
    {
        $file = $this->config['lock_file'];
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $handle = fopen($file, 'c');
        if ($handle === false) {
            throw new RuntimeException('เปิดไฟล์ lock ไม่ได้: ' . $file);
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('มีการดึงข้อมูลรอบอื่นทำงานอยู่ กรุณารอสักครู่');
        }

        return $handle;
    }

    private function resolveKey(array $row, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $row)) {
                return $candidate;
            }
        }

        return null;
    }

    private function value(array $row, ?string $key): string
    {
        return $key === null ? '' : trim((string)($row[$key] ?? ''));
    }

    /** ตัดอักขระที่ไม่ใช่ตัวเลข/ตัวอักษร ให้เข้ากับ key ที่ convert_ssc.php ใช้ค้นหา */
    private function normalizePartNumber(string $value): string
    {
        if ($value === '' || strcasecmp($value, 'N/A') === 0) {
            return '';
        }

        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    private function clean(string $value): string
    {
        return strcasecmp($value, 'N/A') === 0 ? '' : $value;
    }
}
