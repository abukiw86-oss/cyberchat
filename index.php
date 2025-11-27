<?php
// index.php
require 'db.php';
if (isset($_COOKIE['visitor_id'])) {
    $visitor_id = $_COOKIE['visitor_id'];
} else {
    $visitor_id = '';
}
if (!empty($_COOKIE['nickname'])) {
    $name = $_COOKIE['nickname'];
} else {
    $name = '';
}
if(isset($_SESSION['room_name'])){
  header("location:room.php");
}
// Get initial active rooms (only public rooms)
$rooms_result = $conn->query("
    SELECT r.code, r.participants, r.last_active, ur.nickname, r.status, r.logo_path
    FROM rooms r 
    LEFT JOIN user_rooms ur ON r.code = ur.room_code AND ur.user_id = '" . $conn->real_escape_string($visitor_id ?? '') . "'
    WHERE r.status = 'public' OR ur.user_id = '" . $conn->real_escape_string($visitor_id ?? '') . "'
    ORDER BY r.last_active DESC 
    LIMIT 20
");


//for users profile
$stt = $conn->prepare("SELECT code, status, room_pass FROM rooms WHERE creator_id = ?");
if (!$stt) die("DB error on room check");
$stt->bind_param("s", $visitor_id);
$stt->execute();
$res = $stt->get_result();

$rooms = array();
while ($rr = $res->fetch_assoc()) {
    $rooms[] = $rr;
}

$stmt = $conn->prepare("SELECT user_logo FROM users WHERE recovery_hash = ?");
$stmt->bind_param("s", $visitor_id);
$stmt->execute();
$result = $stmt->get_result();
$userdata = $result->fetch_assoc();
$userlogo = $userdata['user_logo'] ?? '';
$userid = $userdata['recovery_hash'] ?? '';
$stmt->close();
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
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    body {
      background:url(  <?php if(!$userlogo == ''): ?> 
          background: url(<?= $userlogo?>);
      <?php else: ?>
          background: #0f1419;
      <?php endif; ?>);
      background-position: center;
      background-size: cover;
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>💬 Your Chat Rooms</h1>
    
    <?php if(isset($_GET['error'])): ?>
      <div class="alert error">
        <?= htmlspecialchars($_GET['error']) ?>
        <button onclick="closeerror()" class="errornote">x</button>
      </div>
    <?php endif; ?>
    
    <?php if(isset($_GET['success'])): ?>
      <div class="alert success">
        <?= htmlspecialchars($_GET['success']) ?>
        <button onclick="closeerror()" class="errornote">x</button>
      </div>
    <?php endif; ?>
    

    <div class="main-grid">
      <?php if (isset($_COOKIE['visitor_id'] )): ?>
      <div class="form-section" id="roomsearch">
                <form method="GET" action="room.php">
          <div class="input-group">
            <h2>Join or Create Room</h2>
            <label for="room">Room Code:</label>
            <input type="text" id="room" name="room" placeholder="Enter room code/abc/123/@#$" required 
                   pattern="[a-zA-Z0-9_-]+" title="Only letters, numbers, hyphens and underscores">
          </div>
          
          <?php if(isset($name) && !empty($name)): ?>
            <input type="hidden" id="name" name="name" value="<?= htmlspecialchars($name) ?>" required>
          <?php else: ?>
            <div class="input-group">
              <input type="text" id="nameinp" name="name" placeholder="Enter your display name" required maxlength="50">
              <small>this name uses every time you join or create rooms!</small>
            </div>
          <?php endif; ?>

          <div class="input-group">
            <label>Room Type:</label>
            <div class="radio-group">
              <label class="radio-option">
                <input type="radio" name="room_type" value="public" checked>
                <span class="radio-label">🌐 Public Room <small>Anyone can join with room code</small></span>
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
      <?php else: ?>
        <div class="recovery-section">
          <h1>Set Recovery</h1>
          <p>First you need to set a recovery phrase for this chat</p><br>
          <a href="recovery.php" class="btn-primary">Continue to Recovery Settings</a>
        </div>
      <?php endif; ?>

      <div class="rooms-section">
        <h2>Active Public Rooms <small id="rooms-count"></small></h2>
        <div class="meta-list" id="rooms-list">
          <div class="rooms-loading"></div>
        </div>
      </div>
    </div>
  </div>

  <?php if(!$visitor_id == "" && !$name == ''): ?>
    <?php if ($visitor_id && !$userlogo == '') : ?>
      <div onclick="opnfoot()" class="btn-primary1">
        <img src="<?= htmlspecialchars($userdata['user_logo']) ?>" alt="RoomLogo" class="RoomLogo">
      </div>
    <?php else: ?>
      <button class="btn-primary1" onclick="opnfoot()" id="ooop"><i class="fa-solid fa-circle-user"></i></button> 
    <?php endif; ?>

    <div style="margin-top: 100px; display:none;" id="foot">
      <button onclick="closefoot()" style="position: absolute; top: 0; right: 0; margin: 10px;" class="btn-secondary">X</button>

      <form id="uploadLogoForm" action="upload_user_logo.php" method="POST" enctype="multipart/form-data" style="display:inline;">
        <input type="hidden" name="room" value="<?= htmlspecialchars($room) ?>">
        <label for="logoUpload" class="roologo" title="Click to change logo">
          <?php if ($name && !$userlogo == '') : ?>
            <img src="<?= htmlspecialchars($userdata['user_logo']) ?>" alt="RoomLogo" class="RoomLogo"><br>
            <strong><?= htmlspecialchars($name) ?></strong><br>
          <?php elseif(!$name == '') : ?>
            <h2><i class="fa-solid fa-circle-user"></i> <br>
              <strong><?= htmlspecialchars($name) ?></strong><br>
            </h2>
          <?php endif; ?>
        </label>
        <div>
          <input type="file" name="logo" id="logoUpload" style="display: none;" accept="image/*" onchange="document.getElementById('uploadLogoForm').submit();">
        </div>
      </form>
     <select id="mode" class="btn-secondary" style="position: absolute; top: 70px; right: 0; margin: 10px;">
        <option value="normal">default</option>
        <option value="dark"> Dark</option>
        <option value="light">light</option>
    </select>
<div class="my-roombox">
    <header>Your Owened Rooms</header>
    <?php if (empty($rooms)) : ?>
        <div class="no-rooms" style="padding: 10px; margin: 10px; text-align: center; color: #666;">
            You haven't created any rooms yet.
        </div>
    <?php else : ?>
        <?php foreach ($rooms as $room) : ?>
            <div class="btn-secondary" style="margin:  8px;" onclick="window.location.href='room.php?room=<?= urlencode($room['code']) ?>'"
                 style=""
          >
                <p>Room Code:</p> <?= htmlspecialchars($room['code']) ?> <br>
                <p>Status:</p> <?= htmlspecialchars($room['status']) ?><br>
                <?php if (isset($room['participants'])) : ?>
                    <strong>Participants:</strong> <?= $room['participants'] ?><br>
                <?php endif; ?>
                <?php if (isset($room['created_at'])) : ?>
                    <strong>Created:</strong> <?= date('M j, Y g:i A', strtotime($room['created_at'])) ?>
                <?php endif; ?>
                <div style="color: #4CAF50; margin-top: 5px; font-weight: bold;">
                    <i class="fa-solid fa-arrow-right"></i>
               <form id="deleteRoomForm" method="post" action="delete-room.php" style="flex: 1;">
                <input type="hidden" name="code" value="<?= htmlspecialchars($room['code']) ?>">
                <button type="submit" class="btn-secondary" style="background:red;" onclick="return confirm('Are you sure you want to delete this room and all messages?')">
                    <i class="fa-solid fa-trash"></i> <span>Delete Room</span>
                </button>
            </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
      
      <div class="room-meta">
        <a href="chatbot/" class="btn-secondary">Start a chat with chatbot</a>
      </div>
      <a href="app/cyberchat.apk" class="btn-primary" download title="download app"><i class="fa-solid fa-mobile-button"></i><span>Download App</span></a>
      <a href="clear.php" class="btn-primary" style="border-radius: 6px; text-decoration:none;" onclick="return confirm('Are you sure you want to clear all your data? that does not recovered.')">
        <i class="fa-solid fa-trash"></i> <span> Delete Account Data</span>
      </a>
      <a href="cookie.php" class="btn-primary" style="padding:7px; border-radius: 6px; text-decoration:none;" onclick="return confirm('Are you sure you want to logout?')">
        <i class="fa-solid fa-logout"></i> <span>logout</span>     
      </a>
    </div>
  <?php endif; ?>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script>
    function opnfoot() {
      const foot = document.getElementById('foot');
      const butt = document.getElementById('ooop');
      foot.style.display = (foot.style.display === "block") ? 'none' : 'block';
      if (butt) {
        butt.innerHTML = (butt.innerHTML === "x") ? '<i class="fa-solid fa-circle-user"></i>' : 'x';
        butt.style.background = (butt.style.background === "red") ? '#66ffc2' : 'red';
      }
    }
 

    function closefoot() {
      const foot = document.getElementById('foot');
      const butt = document.getElementById('ooop');
      foot.style.display = "none";
      if (butt) {
        butt.innerHTML = '<i class="fa-solid fa-circle-user"></i>';
        butt.backgroundImage = "none"; 
      }
    }

    function closeerror() {
      const closeerror = document.querySelector('.alert');
      if (closeerror) {
        closeerror.style.display = "none";
      }
    }

// Function to fetch and update active rooms
function fetchActiveRooms() {
  $.ajax({
    url: 'fetch_rooms.php',
    type: 'GET',
    dataType: 'json',
    success: function(response) {
      if (response && response.success) {
        updateRoomsList(response.rooms);
        // Update rooms count
        document.getElementById('rooms-count').textContent = `(${response.count})`;
      } else {
        console.error('Failed to fetch rooms:', response?.message || 'Unknown error');
        showRoomsError();
      }
    },
    error: function(xhr, status, error) {
      console.error('Rooms fetch error:', error);
      showRoomsError();
    }
  });
}

// Function to update the rooms list in the DOM
function updateRoomsList(rooms) {
  const roomsContainer = document.getElementById('rooms-list');
  
  if (!rooms || rooms.length === 0) {
    roomsContainer.innerHTML = `
      <div class="no-rooms">
        No public active rooms. Create one!
      </div>
    `;
    return;
  }

  let html = '';
  rooms.forEach(room => {
    const lastActive = new Date(room.last_active).toLocaleTimeString('en-US', { 
      hour: '2-digit', 
      minute: '2-digit',
      hour12: false 
    });
    
    // Check if user has already joined this room
    const hasJoined = room.nickname && room.nickname !== '';
    
    html += `
      <div class="room-item ${room.status}" onclick="joinRoom('${escapeHtml(room.code)}', '${escapeHtml(room.nickname || '')}')" style="cursor: pointer;">
        <div class="room-meta">      
          ${room.logo_path ? 
            `<img src="${escapeHtml(room.logo_path)}" alt="Room Logo" class="room_logo">` : 
            `<button class="roomlogo">${room.code.charAt(0).toUpperCase()}</button>`
          }
          <span class="room-code">${escapeHtml(room.code)}</span>
          <br>
          👥 ${room.participants} participants • 
          Last active: ${lastActive}
          Member-limit: ${room.user_limits}
        </div>
        ${hasJoined ? `
          <div class="rejoin-indicator">
    
              <i class="fa-solid fa-arrow-right"></i> 
   
          </div>
        ` : ''}
      </div>
    `;
  });
  
  roomsContainer.innerHTML = html;
}

function joinRoom(roomCode, nickname) {
  if (nickname) {
    window.location.href = `room.php?room=${encodeURIComponent(roomCode)}&name=${encodeURIComponent(nickname)}`;
  } else {
    window.location.href = `room.php?room=${encodeURIComponent(roomCode)}`;
  }
}

// Show error message in rooms list
function showRoomsError() {
  const roomsContainer = document.getElementById('rooms-list');
  roomsContainer.innerHTML = `
    <div class="no-rooms">
      Error loading rooms. <button onclick="fetchActiveRooms()" style="background: none; border: none; color: #4CAF50; text-decoration: underline; cursor: pointer;">Retry</button>
    </div>
  `;
}

// Helper function to escape HTML
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// Initialize when document is ready
$(document).ready(function() {
  // Fetch rooms immediately
  fetchActiveRooms();
  
  // Set up interval to fetch rooms every 3 seconds
  setInterval(fetchActiveRooms, 3000);
  
  // Also fetch when the page becomes visible again
  document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
      fetchActiveRooms();
    }
  });
  
  // Fetch when window gains focus
  window.addEventListener('focus', fetchActiveRooms);
});
    //mode 
const mode = document.querySelector('#mode');
const body = document.querySelector('body');
const bg = "url(<?= $userlogo?>)";

// Load saved theme when page loads
document.addEventListener('DOMContentLoaded', function() {
    const savedTheme = localStorage.getItem('selectedTheme');
    if (savedTheme) {
        mode.value = savedTheme;
        applyTheme(savedTheme);
    }
});

// Save and apply theme when changed
mode.addEventListener('change', function(event) {
    const selectedMode = this.value; 
    
    // Save to localStorage
    localStorage.setItem('selectedTheme', selectedMode);
    
    // Apply the theme
    applyTheme(selectedMode);
});

// Function to apply theme
function applyTheme(theme) {
    if(theme === "normal"){
      <?php if(!$userlogo == ''):?>
      body.style.background = bg;
      <?php else:?>
        body.style.background = "#110f19ff";
        <?php endif;?>
      body.style.backgroundSize = "cover";
      body.style.backgroundPosition = "center";
      body.style.backgroundRepeat = "no-repeat";
    } else if(theme === "dark"){
        body.style.background = "#110f19ff";
        body.style.backgroundImage = "none"; 
    } else if(theme === "light"){
        body.style.backgroundImage = "linear-gradient(135deg, black,white, black)";
        body.style.backgroundSize = "cover";
        body.style.backgroundAttachment = "fixed";
    }
}
  </script>
</body>
</html>