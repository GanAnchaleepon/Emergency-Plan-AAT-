<?php
session_start();
require_once '../config/database.php';

// Set header to return JSON
header('Content-Type: application/json');

// Response array
$response = ['success' => false, 'message' => 'Invalid request.'];

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $response['message'] = 'Unauthorized access.';
    echo json_encode($response);
    exit;
}

// Check if it's a POST request and all required data is present
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $group = $_POST['group_name'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $rack_sequence = $_POST['rack_sequence'] ?? '';
    $ttv_round_no = $_POST['ttv_round_no'] ?? '';

    if (empty($group) || empty($start_time) || empty($end_time) || !isset($rack_sequence) || !isset($ttv_round_no)) {
        $response['message'] = 'Missing required data.';
        echo json_encode($response);
        exit;
    }

    // Basic validation
    if (strlen($rack_sequence) > 0 && !preg_match('/^\d{1,3}$/', $rack_sequence)) {
        $response['message'] = 'RACK SEQUENCE must be up to 3 digits.';
        echo json_encode($response);
        exit;
    }
    if (strlen($ttv_round_no) > 0 && !preg_match('/^\d{1,2}$/', $ttv_round_no)) {
        $response['message'] = 'TTV Round No. must be up to 2 digits.';
        echo json_encode($response);
        exit;
    }

    try {
        $stmt_save_round = $conn->prepare("
            INSERT INTO completed_rounds (group_name, start_time, end_time, rack_sequence, ttv_round_no)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                rack_sequence = VALUES(rack_sequence),
                ttv_round_no = VALUES(ttv_round_no),
                updated_at = NOW()
        ");

        if ($stmt_save_round) {
            $stmt_save_round->bind_param("sssss", $group, $start_time, $end_time, $rack_sequence, $ttv_round_no);
            if ($stmt_save_round->execute()) {
                $response['success'] = true;
                $response['message'] = 'บันทึกข้อมูลสำเร็จ';
            } else {
                $response['message'] = 'Database error: ' . $stmt_save_round->error;
            }
            $stmt_save_round->close();
        } else {
            $response['message'] = 'Database error: ' . $conn->error;
        }
    } catch (Exception $e) {
        $response['message'] = 'An exception occurred: ' . $e->getMessage();
    }

    $conn->close();
}

echo json_encode($response);
?>
