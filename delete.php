<?php
require 'db.php';
header('Content-Type: application/json');

// Ensure visitor cookie exists
if (!isset($_COOKIE['visitor_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit;
}

$visitor = $_COOKIE['visitor_id'];

$id = trim($_GET['delete']);
$room = trim($_GET['room']);

    // Verify that this message belongs to the user before deleting
    $stmt = $conn->prepare("SELECT message FROM messages WHERE id = ? AND visitor_hash = ?");
    $stmt->bind_param("is", $id, $visitor);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($file_path);
        $stmt->fetch();

        // Delete file if it exists
        if ($file_path && file_exists($file_path)) {
            unlink($file_path);
        }

        // Delete message
        $delete = $conn->prepare("DELETE FROM messages WHERE id = ? AND visitor_hash = ?");
        $delete->bind_param("ss", $id, $visitor);
        $delete->execute();

         
     
            header("location:room.php?room=$room");

    } else {
        echo json_encode(['success' => false, 'message' => 'Message not found or not owned']);
    }
?>
