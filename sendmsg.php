<?php
require 'db.php';

if (!isset($_COOKIE['visitor_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$room = trim($_POST['room'] ?? '');
$name = trim($_POST['name'] ?? '');
$msg  = trim($_POST['message'] ?? '');
$user = $_COOKIE['visitor_id'];

header('Content-Type: application/json');





if ($room && $name && $msg !== '') {
    // Sanitize inputs
    $room = preg_replace('/[^a-zA-Z0-9_-]/', '', $room);
    $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $msg = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    
    $stmt = $conn->prepare("INSERT INTO messages (room_code, nickname, visitor_hash, message) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("ssss", $room, $name, $user, $msg);
        if ($stmt->execute()) {
            // Update room last active
            $update = $conn->prepare("UPDATE rooms SET last_active = NOW() WHERE code = ?");
            $update->bind_param("s", $room);
            $update->execute();
            $update->close();
            
            echo json_encode(['status' => 'success', 'message' => 'Message sent']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to send message']);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
}
?>