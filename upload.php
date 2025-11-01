<?php
require 'db.php';

if (!isset($_COOKIE['visitor_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$room = $_POST['room'] ?? '';
$name = $_POST['name'] ?? '';
$user = $_COOKIE['visitor_id'];

header('Content-Type: application/json');

// Allowed file types
$allowed = ['jpg','jpeg','png','gif','mp4','mp3','pdf','txt','csv','sql','json'];
$maxSize = 10 * 1024 * 1024; // 10MB
$uploadDir = "uploads/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if (!empty($_FILES['file']['name']) && $room && $name) {
    $file = $_FILES['file'];
    
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Upload error: ' . $file['error']]);
        exit;
    }
    
    if ($file['size'] > $maxSize) {
        echo json_encode(['status' => 'error', 'message' => 'File too large (max 10MB)']);
        exit;
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowed)) {
        echo json_encode(['status' => 'error', 'message' => 'File type not allowed']);
        exit;
    }

    // Generate safe filename
    $safeName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = $uploadDir . $safeName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $stmt = $conn->prepare("INSERT INTO messages (room_code, nickname, user_cookie, file_path, message) VALUES (?, ?, ?, ?, ?)");
        $originalName = htmlspecialchars(basename($file['name']), ENT_QUOTES, 'UTF-8');
        $message = "📎 " . $originalName;
        
        if ($stmt) {
            $stmt->bind_param("sssss", $room, $name, $user, $targetPath, $message);
            if ($stmt->execute()) {
                // Update room last active
                $update = $conn->prepare("UPDATE rooms SET last_active = NOW() WHERE code = ?");
                $update->bind_param("s", $room);
                $update->execute();
                $update->close();
                
                echo json_encode(['status' => 'success', 'message' => '✅ File uploaded successfully']);
            } else {
                unlink($targetPath); // Remove file if DB insert fails
                echo json_encode(['status' => 'error', 'message' => '❌ Database error']);
            }
            $stmt->close();
        } else {
            echo json_encode(['status' => 'error', 'message' => '❌ Database preparation error']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => '❌ File move failed']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => '❌ No file or invalid data']);
}
?>