<?php  
require 'db.php';

if (!isset($_COOKIE['visitor_id'])) {
    header("Location: recovery.php");
    exit;
}

$visitor_id = $_COOKIE['visitor_id'];
$room = trim(htmlspecialchars($_GET['room'] ?? ''));
$room = preg_replace('/[^a-zA-Z0-9_-]/', '', $room);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['pass'] ?? '';
    
    if (empty($password)) {
        $error = 'Password is required';
    } else {
        // Use regular query instead of prepared statements to avoid sync issues
        $result = $conn->query("SELECT room_pass FROM rooms WHERE code = '$room' AND status = 'private'");
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stored_password = $row['room_pass'];
            
            if ($password === $stored_password) {
                $name = $_COOKIE['nickname'] ?? 'User';
                $date = date('Y-m-d H:i:s');
                
                // Insert user room access
                $conn->query("INSERT INTO user_rooms (user_id, room_code, nickname, last_joined) VALUES ('$visitor_id', '$room', '$name', '$date')");
                
                header("Location: room.php?room=" . urlencode($room));
                exit;
            } else {
                header("location:index.php?error=Incorrect code!");
            }
        } else {
            $error = "Room not found or not private";
        }
        if ($result) $result->close();
    }
}

// Check if room exists
$result = $conn->query("SELECT status FROM rooms WHERE code = '$room'");
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    if ($row['status'] === 'public') {
        header("Location: room.php?room=" . urlencode($room));
        exit;
    }
    $result->close();
} else {
    $error = "Room not found";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Private Room Password</title>
    <link rel="stylesheet" href="style.css?v<?php echo time();?>">
</head>
<body>
    <h3>Enter Room code</h3>
    <form action="" method="post" class="form-section">
        <div class="input-group">
            <label for="pass">Please enter room code for "<?php echo htmlspecialchars($room); ?>"</label>
            <input type="text" name="pass" required>
            <input type="hidden" name="room" value="<?php echo htmlspecialchars($room); ?>">
            <input type="hidden" name="room_type" value="<?php echo htmlspecialchars($room_type); ?>">
            <button type="submit">Enter Room</button>
        </div>
    </form>
    
    <?php if(isset($error)): ?>
        <div class="alert error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
</body>
</html>