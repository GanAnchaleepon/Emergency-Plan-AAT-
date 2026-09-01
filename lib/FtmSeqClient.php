<?php
/**
 * ไคลเอนต์เรียก API ระบบ FTM SEQ (ftmseq.ttv-supplychain.com)
 * อ้างอิง FTM-SEQ-API-Integration.md
 */
final class FtmSeqClient
{
    private $baseUrl;
    private $loginPath;
    private $user;
    private $pass;
    private $caBundle;
    private $cookieJar;

    public function __construct(array $config)
    {
        $this->baseUrl   = rtrim($config['base_url'], '/') . '/';
        $this->loginPath = $config['login_path'];
        $this->user      = (string)($config['user'] ?? '');
        $this->pass      = (string)($config['pass'] ?? '');
        $this->caBundle  = $config['ca_bundle'];
        $this->cookieJar = $config['cookie_jar'];

        if ($this->user === '' || $this->pass === '') {
            throw new RuntimeException('ยังไม่ได้ตั้งค่าชื่อผู้ใช้/รหัสผ่าน กรุณาสร้างไฟล์ config/ftmseq.local.php');
        }

        $dir = dirname($this->cookieJar);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('สร้างโฟลเดอร์เก็บคุกกี้ไม่ได้: ' . $dir);
        }
    }

    /** ดึงข้อมูล พร้อม login ใหม่อัตโนมัติเมื่อ session หมดอายุ (ch:10) */
    public function fetchData(string $path, array $fields = ['type' => 1]): array
    {
        $body = $this->request($this->baseUrl . $path, $fields);

        if ($this->isSessionExpired($body)) {
            $this->login();
            $body = $this->request($this->baseUrl . $path, $fields);
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('คำตอบจาก API ไม่ใช่ JSON: ' . mb_substr($body, 0, 300));
        }

        $ch = isset($json['ch']) ? (int)$json['ch'] : 0;
        if ($ch !== 1) {
            $detail = isset($json['data']) && is_string($json['data']) ? $json['data'] : 'ไม่ทราบสาเหตุ';
            throw new RuntimeException("API ตอบกลับ ch=$ch: " . strip_tags($detail));
        }
        if (!is_array($json['data'] ?? null)) {
            throw new RuntimeException('API ตอบสำเร็จแต่ไม่มีข้อมูลในคีย์ data');
        }

        return $json['data'];
    }

    private function login(): void
    {
        $result = $this->request($this->baseUrl . $this->loginPath, [
            'user' => $this->user,
            'pass' => $this->pass,
        ]);

        // รองรับทั้ง {"ch":1} และ {ch:1}
        if (!preg_match('/["\']?ch["\']?\s*:\s*1\b/', $result)) {
            throw new RuntimeException('เข้าสู่ระบบ FTM SEQ ไม่สำเร็จ: ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
        }
    }

    private function request(string $url, array $fields): string
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_USERAGENT      => 'EmergencyPicking/1.0',
        ];
        if (is_file($this->caBundle)) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }
        curl_setopt_array($ch, $options);

        $body  = curl_exec($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            if (stripos($error, 'certificate') !== false) {
                $error .= ' — ให้โหลด https://curl.se/ca/cacert.pem มาวางที่ storage/certs/cacert.pem';
            }
            throw new RuntimeException('เรียก API ไม่สำเร็จ: ' . $error);
        }
        if ($code !== 200) {
            throw new RuntimeException('API ตอบกลับ HTTP ' . $code);
        }

        return ltrim((string)$body, "\xEF\xBB\xBF \t\n\r");   // ตัด UTF-8 BOM
    }

    private function isSessionExpired(string $body): bool
    {
        if (json_decode($body, true) !== null) {
            return false;
        }
        $head = mb_substr($body, 0, 500);

        return mb_strpos($head, 'เวลาการเชื่อมต่อหมด') !== false
            || stripos($head, 'login') !== false;
    }
}
