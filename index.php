<?php
// index.php
require 'db.php';

// Set visitor cookie if not exists
if (!isset($_COOKIE['visitor_id'])) {
    $visitor_id = bin2hex(random_bytes(16));
    setcookie('visitor_id', $visitor_id, time() + 2592000, "/");
}

// Get active rooms (only public rooms)
$rooms_result = $conn->query("
    SELECT r.code, r.participants, r.last_active, ur.nickname, r.status
    FROM rooms r 
    LEFT JOIN user_rooms ur ON r.code = ur.room_code AND ur.user_id = '" . $conn->real_escape_string($_COOKIE['visitor_id'] ?? '') . "'
    WHERE r.status = 'public' OR ur.user_id = '" . $conn->real_escape_string($_COOKIE['visitor_id'] ?? '') . "'
    ORDER BY r.last_active DESC 
    LIMIT 20
");
                                          

?>

<!doctype html>
<html>
<head>
  <meta charset="utf-8">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="Referrer-Policy" content="strict-origin-when-cross-origin">
    
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Web Chat</title>
  <link rel="stylesheet" href="style.css?v=<?php echo time();?>">
  <link rel="shortcut icon" href="comment-solid-full.svg" type="image/x-icon">

      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" 
          integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" 
          crossorigin="anonymous" 
          referrerpolicy="no-referrer">
</head>
<body>
  <div class="container">
    <h1>💬 Secure Chat Rooms</h1>
    
    <?php if(isset($_GET['error'])): ?>
      <div class="alert error">
        <?= htmlspecialchars($_GET['error']) ?>
      </div>
    <?php endif; ?>
    
    <?php if(isset($_GET['success'])): ?>
      <div class="alert success">
        <?= htmlspecialchars($_GET['success']) ?>
      </div>
    <?php endif; ?>

    <div class="main-grid">
      <div class="form-section">
        <h2>Join or Create Room</h2>
        <form method="GET" action="room.php">
          <div class="input-group">
            <label for="room">Room Code:</label>
            <input type="text" id="room" name="room" placeholder="Enter room code" required 
                   pattern="[a-zA-Z0-9_-]+" title="Only letters, numbers, hyphens and underscores">
          </div>
          
          <div class="input-group">
            <label for="name">Your Name:</label>
            <?php if(isset($_COOKIE['nickname']) && !empty($_COOKIE['nickname'])): ?>
              <div class="saved-name">
                <input type="text" id="name" name="name" value="<?= htmlspecialchars($_COOKIE['nickname']) ?>" required disabled>
                
              </div>
            <?php else: ?>
              <input type="text" id="name" name="name" placeholder="Enter your display name" required maxlength="50">
              <small>this name uses every time you join or create rooms!</small>
            <?php endif; ?>
          </div>

          <div class="input-group">
            <label>Room Type:</label>
            <div class="radio-group">
              <label class="radio-option">
                <input type="radio" name="room_type" value="public" checked>
                <span class="radio-label">🌐 Public Room    <small>Anyone can join with room code</small></span>
               
              </label>
              <label class="radio-option">
                <input type="radio" name="room_type" value="private">
                <span class="radio-label">🔒 Private Room <small>Only invited users can join</small></span>
                
              </label>
            </div>
          </div>

          <button type="submit" class="btn-primary"><i class="fa-solid fa-arrow-right"></i><br><span>Enter Room</span></button>
        </form>
      </div>

      <div class="rooms-section">
        <h2>Active Rooms</h2>
        <div class="meta-list">
          <?php if($rooms_result && $rooms_result->num_rows > 0): ?>
            <?php while($room = $rooms_result->fetch_assoc()): ?>
              <div class="room-item <?= $room['status'] ?>">
                <div class="room-header">
                  <span class="room-code"><?= htmlspecialchars($room['code']) ?></span>
                  <span class="room-status <?= $room['status'] ?>">
                    <?= $room['status'] === 'private' ? '🔒' : '🌐' ?>
                    <?= ucfirst($room['status']) ?>
                  </span>
                </div>
                <div class="room-meta">
                  👥 <?= $room['participants'] ?> participants • 
                  Last active: <?= date('H:i', strtotime($room['last_active'])) ?>
                  <?php if($room['nickname']): ?>
                    <br><small>You joined as: <?= htmlspecialchars($room['nickname']) ?></small>
                  <?php endif; ?>
                </div>
                <?php if($room['nickname']): ?>
                  <form method="GET" action="room.php" class="rejoin-form">
                    <input type="hidden" name="room" value="<?= htmlspecialchars($room['code']) ?>">
                    <input type="hidden" name="name" value="<?= htmlspecialchars($room['nickname']) ?>">
                    <button type="submit" class="time"><i class="fa-solid fa-arrow-right"></i><br>rejoin</button>
                  </form>
                  <?php
                  ?>
                <?php endif; ?>
              </div>
            <?php endwhile; ?>
          <?php else: ?>
            <div class="no-rooms">
              No public active rooms. Create one!
            </div>
          <?php endif; ?>
  
     
      </div>
         </div>
             <hr/>
            <div class="footer">
      <a href="clear.php" class="btn-danger" style="padding:10px; border-radius: 6px; text-decoration:none; margin-top: 100px;" onclick="return confirm('Are you sure you want to clear all your data?')">
        <i class="fa-solid fa-trash"></i> <span> Delete Account Data</span>
      </a>
    </div>
    </div>
  </div>
  
</body>
</html>





