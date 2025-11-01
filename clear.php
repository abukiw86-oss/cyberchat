
<?php
// destroy.php - Clear user data
require 'db.php';

if (isset($_COOKIE['visitor_id'])) {
    $visitor_id = $_COOKIE['visitor_id'];
    
    // Remove user from all rooms
    $stmt = $conn->prepare("DELETE FROM user_rooms WHERE user_id = ?");
    $stmt->bind_param("s", $visitor_id);
    $stmt->execute();
    $stmt->close();
    
    // Delete rooms created by user if empty
    $conn->query("DELETE FROM rooms WHERE creator_id = '" . $conn->real_escape_string($visitor_id) . "' 
                  AND code NOT IN (SELECT DISTINCT room_code FROM user_rooms)");
}

// Clear all cookies
setcookie('visitor_id', '', time() - 3600, "/");
setcookie('nickname', '', time() - 3600, "/");

// Clear session data
session_start();
session_destroy();

header("Location: index.php?success=Your+data+has+been+cleared+successfully");
exit;
?>