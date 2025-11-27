<?php
// upload-room-logo.php
require 'db.php';

if (!isset($_COOKIE['visitor_id'])) {
    header("Location: recovery.php");
    exit;
}

$visitor_id = $_COOKIE['visitor_id'];
$room = trim($_POST['room'] ?? '');

// Verify user is the room creator
$stmt = $conn->prepare("SELECT id FROM rooms WHERE code = ? AND creator_id = ?");
$stmt->bind_param("ss", $room, $visitor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0 && !$_COOKIE["visitor_id"] === $visitor_id) {
 header("room.php?room=" . urlencode($room) . "&error=only+creator+can+change+!");
}
$stmt->close();

// Handle file upload
if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = 'room_logos/';
    
    // Create directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $fileExtension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
    $allowedExtensions = ['jpg', 'jpeg', 'png'];
    
    if (!in_array(strtolower($fileExtension), $allowedExtensions)) {
         header("room.php?room=" . urlencode($room) . "&error=Invalid file type. Only JPG, PNG, are allowed.!");
    }
    
    // Generate unique filename
    $filename = 'room_' . $room . '_' . time() . '.' . $fileExtension;
    $filePath = $uploadDir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($_FILES['logo']['tmp_name'], $filePath)) {
        // Update database with new logo path
        $update = $conn->prepare("UPDATE rooms SET logo_path = ? WHERE code = ?");
        $update->bind_param("ss", $filePath, $room);
        
        if ($update->execute()) {
            // Redirect back to room
            header("Location: room.php?room=" . urlencode($room));
            exit;
        } else {
           header("room.php?room=" . urlencode($room) . "&error=Error+uploading+file!");
        }
    } else {
        header("room.php?room=" . urlencode($room) . "&error=Error+uploading+file!");
    }
} else {
    header("room.php?room=" . urlencode($room) . "&error=no+file+selected!");
}
?>