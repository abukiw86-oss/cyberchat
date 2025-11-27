<?php
// upload-room-logo.php
require 'db.php';

if (!isset($_COOKIE['visitor_id'])) {
    header("Location: recovery.php");
    exit;
}

$visitor_id = $_COOKIE['visitor_id'];
$name = $_COOKIE['nickname'];

// Verify user is the room creator
$stmt = $conn->prepare("SELECT id FROM users WHERE recovery_hash = ?");
$stmt->bind_param("s", $visitor_id,);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0 && !$_COOKIE["visitor_id"] === $visitor_id) {
 header("recovery.php");
}
$stmt->close();

// Handle file upload
if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = 'user_logos/';
    
    // Create directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $fileExtension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
    $allowedExtensions = ['jpg', 'jpeg', 'png'];
    
    if (!in_array(strtolower($fileExtension), $allowedExtensions)) {
         header("index.php?room=" . urlencode($room) . "&error=Invalid file type. Only JPG, PNG, are allowed.!");
    }
    
    // Generate unique filename
    $filename = 'room_' . $visitor_id . '_' . time() . '.' . $fileExtension;
    $filePath = $uploadDir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($_FILES['logo']['tmp_name'], $filePath)) {
        // Update database with new logo path
        $update = $conn->prepare("UPDATE users SET user_logo = ? WHERE recovery_hash = ?");
        $update->bind_param("ss", $filePath, $visitor_id);
        
        if ($update->execute()) {
            // Redirect back to room
            header("Location: index.php?success=uploaded");
            exit;
        } else {
           header("index.php?error=Error+uploading+file!");
        }
    } else {
        header("index.php?error=Error+uploading+file!");
    }
} else {
    header("index.php?error=no+file+selected!");
}
?>