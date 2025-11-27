<?php
require 'db.php';

if (!isset($_COOKIE['visitor_id'])) {
    header("Location: index.php?error=Unauthorized");
    exit;

}
$visitor_id = $_COOKIE['visitor_id'];
$code = $_POST['code'] ?? '';

if ($code) {
    // Only creator can delete
    $stmt = $conn->prepare("SELECT creator_id FROM rooms WHERE code = ?");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $creator = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($creator && $creator['creator_id'] === $visitor_id) {
        // Delete messages
        $del_msg = $conn->prepare("DELETE FROM messages WHERE room_code = ?");
        $del_msg->bind_param("s", $code);
        $del_msg->execute();
        $del_msg->close();
        
        // Delete user rooms
        $del_ur = $conn->prepare("DELETE FROM user_rooms WHERE room_code = ?");
        $del_ur->bind_param("s", $code);
        $del_ur->execute();
        $del_ur->close();
        
        // Delete room
        $del_room = $conn->prepare("DELETE FROM rooms WHERE code = ?");
        $del_room->bind_param("s", $code);
        $del_room->execute();
        $del_room->close();
        
        header("Location: index.php?success=Room+deleted");
    } else {
        header("Location: index.php?error=Only+room+creator+can+delete");
    }
} else {
    header("Location: index.php?error=Invalid+room");
}
exit;
?>