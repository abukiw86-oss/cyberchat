<?php
// update_room_status.php
require 'db.php';

if (!isset($_COOKIE['visitor_id'])) {
    header("Location: index.php?error=Unauthorized");
    exit;
}

$visitor_id = $_COOKIE['visitor_id'];
$room = $_POST['room'] ?? '';
$new_status = $_POST['status'] ?? '';

if ($room && in_array($new_status, ['public', 'private'])) {
    // Check if user is room creator
    $stmt = $conn->prepare("SELECT creator_id FROM rooms WHERE code = ?");
    $stmt->bind_param("s", $room);
    $stmt->execute();
    $result = $stmt->get_result();
    $room_data = $result->fetch_assoc();
    $stmt->close();

    if ($room_data && $room_data['creator_id'] === $visitor_id) {
        // Update room status
        $update = $conn->prepare("UPDATE rooms SET status = ? WHERE code = ?");
        $update->bind_param("ss", $new_status, $room);
        $update->execute();
        $update->close();
        
        header("Location: room.php?room=" . urlencode($room) . "&success=Room+updated+to+" . $new_status);
    } else {
        header("Location: room.php?room=" . urlencode($room) . "&error=Only+room+creator+can+change+settings");
    }
} else {
    header("Location: index.php?error=Invalid+request");
}
exit;
?>