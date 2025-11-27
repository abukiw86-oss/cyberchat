<?php
require 'db.php';
if (!isset($_COOKIE['visitor_id'])) {
    header("Location: recovery.php");
    exit;
}

$visitor_id = $_COOKIE['visitor_id'];
$room = trim(htmlspecialchars($_GET['room'] ?? ''));
$room_type = trim($_GET['room_type'] ?? 'public');
$room = preg_replace('/[^a-zA-Z0-9_-]/', '', $room);

// Initialize name
if(!isset($_COOKIE['nickname'])) {
    $name = trim($_GET['name'] ?? '');
} else {
    $name = $_COOKIE['nickname'];
}
// Validate input
if ($room === '' || $name === '' ) {
    header("Location: index.php?error=Room+and+name+are+required");
    exit;
}




$name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
setcookie('nickname', $name, time() + (10*365*24*60*60), "/");

// Create room if not exists
$stmt = $conn->prepare("SELECT * FROM rooms WHERE code = ?");
if (!$stmt) die("DB error on room check");
$stmt->bind_param("s", $room);
$stmt->execute();
$res = $stmt->get_result();
$room_invi = '';
$is_creator = false;
$room_status = 'public';

if ($res->num_rows === 0) {
    // Create new room with specified type
    $ins = $conn->prepare("INSERT INTO rooms (code, creator_id, status, room_pass, last_active) VALUES (?, ?, ?, '', NOW())");
    $ins->bind_param("sss", $room, $visitor_id, $room_type);
    $ins->execute();
    $ins->close();
    $is_creator = true;
    $room_status = $room_type;


    
    // Generate password only for private rooms created by creator
    if ($room_status === 'private' && $is_creator) {
        $link = bin2hex(random_bytes(6));
        $pa = $conn->prepare("UPDATE rooms SET room_pass = ? WHERE code = ?");
        $pa->bind_param("ss", $link, $room);
        $pa->execute();
        $pa->close();
        $room_invi = $link;
    }
    }
else {
    $room_data = $res->fetch_assoc();
    $is_creator = ($room_data['creator_id'] === $visitor_id);
    $room_status = $room_data['status'];
    $room_invi = $room_data['room_pass']; 
    $roomlimstat = $room_data['enable_par_limit'];
    $roompar = $room_data['participants'];
    $roomlimit =   $room_data['participant_limit']; 




    // Check if user can join private room
    if ($room_status === 'private' && !$is_creator) {
        $check_user = $conn->prepare("SELECT id FROM user_rooms WHERE user_id = ? AND room_code = ?");
        $check_user->bind_param("ss", $visitor_id, $room);
        $check_user->execute();
        $user_exists = $check_user->get_result()->num_rows > 0;
        $check_user->close();
        if (!$user_exists) {
            header("Location: pass.php?room=" . urlencode($room) . "&error=this+is+a+private+room.+enter+password!");
            exit;
        }
    }
    
    // Generate password only if it doesn't exist and creator is joining private room
    if ($room_status === 'private' && $is_creator && empty($room_invi)) {
        $link = bin2hex(random_bytes(6));
        $pa = $conn->prepare("UPDATE rooms SET room_pass = ? WHERE code = ?");
        $pa->bind_param("ss", $link, $room);
        $pa->execute();
        $pa->close();
        $room_invi = $link;
    }

    if($roompar != 1 && $roomlimit === $roompar  && !$is_creator && $roomlimstat !="no"){
       header("location:index.php?error=room+limit+reached+that+enabled+by+owner!");
    }else{
$up = $conn->prepare("INSERT INTO user_rooms (user_id, room_code, nickname, last_joined) VALUES (?, ?, ?, NOW()) 
                     ON DUPLICATE KEY UPDATE last_joined = NOW(), nickname = VALUES(nickname)");
$up->bind_param("sss", $visitor_id, $room, $name);
$up->execute();
$up->close();
$stmt->close();
    }
}
// Update participants count
$cnt_sql = "UPDATE rooms SET participants = (SELECT COUNT(DISTINCT user_id) FROM user_rooms WHERE room_code = ?), last_active = NOW() WHERE code = ?";
$cnt = $conn->prepare($cnt_sql);
$cnt->bind_param("ss", $room, $room);
$cnt->execute();
$cnt->close();

// Get room participants
$part_stmt = $conn->prepare("SELECT nickname ,user_id FROM user_rooms WHERE room_code = ? ORDER BY last_joined DESC");
$part_stmt->bind_param("s", $room);
$part_stmt->execute();
$participants_result = $part_stmt->get_result();
$participants = [];
while ($row = $participants_result->fetch_assoc()) {
    $participants[] = $row['nickname'];
    $parid[] =  $row['user_id'];
}
$part_stmt->close();
//get banned users
$banned_stmt = $conn->prepare("SELECT user_id,nickname FROM banned_users WHERE room_code = ?");
$banned_stmt->bind_param("s", $room);
$banned_stmt->execute();
$banned_result = $banned_stmt->get_result();
$banned_users = [];
while ($bannedrow = $banned_result->fetch_assoc()) {
    $banned_users[] = $bannedrow['user_id'];
    $banned_nickname[] = $bannedrow['nickname'];
}
$banned_stmt->close();
// Handle unban user action
if (isset($_POST['unban_user']) && $is_creator) {
    $unban_user_id = $_POST['unban_user_id'];
    // Remove user from banned_users table
    $unban_stmt = $conn->prepare("DELETE FROM banned_users WHERE room_code = ? AND user_id = ?");
    $unban_stmt->bind_param("ss", $room, $unban_user_id);
    
    if ($unban_stmt->execute()) {
        echo "<script>
                alert('User has been unbanned and can now join the room again.');
                window.location.href = 'room.php?room=" . urlencode($room) . "';
              </script>";
    } else {
        echo "<script>alert('Error unbanning user.');</script>";
    }
    $unban_stmt->close();
}

// Handle Make Owner action
if (isset($_POST['make_owner']) && $is_creator) {
    $new_owner_id = $_POST['new_owner_id'];
    $new_owner_name = $_POST['new_owner_name'];
    
    $conn->begin_transaction();
    
    try {
        $update_owner = $conn->prepare("UPDATE rooms SET creator_id = ? WHERE code = ?");
        $update_owner->bind_param("ss", $new_owner_id, $room);
        
        if ($update_owner->execute()) {
            $check_participant = $conn->prepare("SELECT COUNT(*) FROM user_rooms WHERE room_code = ? AND user_id = ?");
            $check_participant->bind_param("ss", $room, $new_owner_id);
            $check_participant->execute();
            $check_participant->bind_result($count);
            $check_participant->fetch();
            $check_participant->close();
            
            if ($count == 0) {
                $add_participant = $conn->prepare("INSERT INTO user_rooms (room_code, user_id, nickname) VALUES (?, ?, ?)");
                $add_participant->bind_param("sss", $room, $new_owner_id, $new_owner_name);
                $add_participant->execute();
            }
            
            $conn->commit();
            echo "<script>
                    alert('Owner successfully changed to $new_owner_name');
                    window.location.href = 'room.php?room=" . urlencode($room) . "';
                  </script>";
        } else {
            throw new Exception("Failed to update owner");
        }
    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>alert('Error changing owner: " . addslashes($e->getMessage()) . "');</script>";
    }
}

if (isset($_POST['remove_user']) && $is_creator) {
    $remove_user_id = $_POST['remove_user_id'];
    $remove_user_name = $_POST['remove_user_name'];

    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First, check if user exists in user_rooms
        $check_user = $conn->prepare("SELECT COUNT(*) FROM user_rooms WHERE room_code = ? AND user_id = ?");
        $check_user->bind_param("ss", $room, $remove_user_id);
        $check_user->execute();
        $check_user->bind_result($user_count);
        $check_user->fetch();
        $check_user->close();
        
        if ($user_count == 0) {
            throw new Exception("User not found in this room");
        }
        
        // Check if banned_users table exists, if not create it
        $table_check = $conn->query("SHOW TABLES LIKE 'banned_users'");
        if ($table_check->num_rows == 0) {
            // Create banned_users table if it doesn't exist
            $create_table = $conn->query("
                CREATE TABLE banned_users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    room_code VARCHAR(50) NOT NULL,
                    user_id VARCHAR(50) NOT NULL,
                    banned_by VARCHAR(50) NOT NULL,
                    banned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_ban (room_code, user_id)
                )
            ");
        }
        
        // Remove user from participants
        $remove_user = $conn->prepare("DELETE FROM user_rooms WHERE room_code = ? AND user_id = ?");
        $remove_user->bind_param("ss", $room, $remove_user_id);
        
        // Add user to banned list to prevent rejoining
        $ban_user = $conn->prepare("INSERT INTO banned_users (room_code, user_id,nickname, banned_by) VALUES (?, ?, ?, ?)");
        $ban_user->bind_param("ssss", $room, $remove_user_id,$remove_user_name, $visitor_id);
        if($remove_user_id === $room_data['creator_id']){

        }

        $remove_success = $remove_user->execute();
        $ban_success = $ban_user->execute();
        
        if ($remove_success && $ban_success) {
            $conn->commit();
            echo "<script>
                    alert('$remove_user_name has been removed and banned from this room');
                    window.location.href = 'room.php?room=" . urlencode($room) . "';
                  </script>";
        } else {
            throw new Exception("Failed to remove or ban user");
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = addslashes($e->getMessage());
        echo "<script>alert('Error: $error_msg');</script>";
    }
    
    // Close statements
    if (isset($remove_user)) $remove_user->close();
    if (isset($ban_user)) $ban_user->close();
}

// Get base URL for invites
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . "/index.php";

// Fetch room logo path from database
$stmt = $conn->prepare("SELECT logo_path FROM rooms WHERE code = ?");
$stmt->bind_param("s", $room);
$stmt->execute();
$result = $stmt->get_result();
$roomData = $result->fetch_assoc();
$roomLogo = $roomData['logo_path'] ?? '';
$stmt->close();

//leave room 
if(isset($_POST['leaveroom'])){
        $remove_user = $conn->prepare("DELETE FROM user_rooms WHERE room_code = ? AND user_id = ?");
        $remove_user->bind_param("ss", $room, $visitor_id);
        if($remove_user->execute()){
            header("location:index.php?success=you+leaved+$room+succesfully");
        }
}
// Get banned users
$banned_stmt = $conn->prepare("SELECT user_id, nickname FROM banned_users WHERE room_code = ?");
$banned_stmt->bind_param("s", $room);
$banned_stmt->execute();
$banned_result = $banned_stmt->get_result();
$banned_users = [];
$banned_nickname = [];
while ($bannedrow = $banned_result->fetch_assoc()) {
    $banned_users[] = $bannedrow['user_id'];
    $banned_nickname[] = $bannedrow['nickname'];
}
$banned_stmt->close();
if (isset($_POST['unban_user']) && $is_creator) {
    $unban_user_id = $_POST['unban_user_id'];
    
// Remove user from banned_users table
    $unban_stmt = $conn->prepare("DELETE FROM banned_users WHERE room_code = ? AND user_id = ?");
    $unban_stmt->bind_param("ss", $room, $unban_user_id);
    
    if ($unban_stmt->execute()) {
        echo "<script>
                alert('User has been unbanned and can now join the room again.');
                window.location.href = 'room.php?room=" . urlencode($room) . "';
              </script>";
    } else {
        echo "<script>alert('Error unbanning user.');</script>";
    }
    $unban_stmt->close();
}
//chnage room member no
if (isset($_POST['changeroomlimit'])){
    $limitno = $_POST['changelimit'];
    $roomlimpermission = "yes";
     if ($limitno < $roompar){
         header("location:room.php?room=" . urlencode($room) . "&error=never!+because+member+limit+is+less+than+members+now.!");
     }else{
        $change = $conn->prepare("UPDATE rooms SET participant_limit = ?  WHERE code = ?");
        $change->bind_param("is", $limitno, $room);

        $changepermission = $conn->prepare("UPDATE rooms SET enable_par_limit = ?  WHERE code = ?");
        $changepermission->bind_param("ss", $roomlimpermission, $room);

    if($change->execute() && $changepermission->execute()){
        header("location:room.php?room=" . urlencode($room) . "&success=updated!");
    }
    else{
        header("location:room.php?room=" . urlencode($room) . "&error=failed!");
    }
}
}
        
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="Referrer-Policy" content="strict-origin-when-cross-origin">
    
    <title>Room: <?= htmlspecialchars($room) ?></title>
    <link rel="stylesheet" href="room.css?v=<?php echo time();?>">
    <link rel="shortcut icon" href="comment-solid-full.svg" type="image/x-icon">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        #messages {
            padding: 20px;
            padding-top: 90px; 
            padding-bottom: 120px; 
            overflow-y: auto;
            height: 100vh;
            max-width: 100%;
            display: flex;
            background: url(<?= htmlspecialchars($roomLogo) ?>);
            background-position: center;
            background-size: cover;
            flex-direction: column;
        }
    </style>
</head>
<body>
    
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

    <div class="top">
        <a href="index.php" class="back"><i class="fa-solid fa-arrow-left"></i></a>

        <?php if (!empty($roomLogo) && file_exists($roomLogo)): ?>
            <img src="<?= htmlspecialchars($roomLogo) ?>" onclick="openinvite()" alt="Room Logo" class="room-logo-img">
        <?php else: ?>
            <div class="logoo">
                <button onclick="openinvite()" class="roomlogo">
                    <?= strtoupper(substr($room, 0, 1)) ?>
                </button>
            </div>
        <?php endif; ?>
        
        <br>
        <h3 style="font-size: 1.3em; font-weight: 600; font-family: Arial, Helvetica, sans-serif; letter-spacing: 0.5px;  text-transform: capitalize;"><?= htmlspecialchars($room) ?> </h3>
        <button class="count"><small><i class="fa-solid fa-users"></i> (<?= count($participants) ?>)</strong></small></button>
        <select id="mode" class="count">
            <option value="normal">default</option>
            <option value="dark"> Dark</option>
            <option value="light">light</option>
        </select>
    </div>

    <!-- Invite Modal -->
    <div id="invite">
        <div class="invite-modal-content">
            <button onclick="closeinvite()" class="close-modal-btn">✕</button>

            <form id="uploadLogoForm" action="upload-room-logo.php" method="POST" enctype="multipart/form-data" style="display:inline;">
                <input type="hidden" name="room" value="<?= htmlspecialchars($room) ?>">
                
                <label for="logoUpload" class="" title="Click to change logo">
                    <?php if (!empty($roomLogo) && file_exists($roomLogo)): ?>
                        <div class="imghoolld">
                            <img src="<?= htmlspecialchars($roomLogo) ?>" alt="RoomLogo" class="RoomLogo">
                        </div>
                    <?php else: ?>
                        <div class="Roomtct"><?= strtoupper(substr($room, 0, 1)) ?></div>  
                    <?php endif; ?>
                </label>

                <?php if ($is_creator): ?>
                    <div>
                        <input type="file" style="display: none;" name="logo" id="logoUpload" accept="image/*" onchange="document.getElementById('uploadLogoForm').submit();">
                    </div>
                <?php endif; ?>
            </form>
            
            <h3>
                Room: <?= htmlspecialchars($room) ?> <br>
                <small style="color:#4CAF50;">You: <?= htmlspecialchars($name) ?></small><br>
                <?php if($is_creator): ?> 
                    <p>your type: <span style="color:#FF9800; font-size:0.8em;">(Creator)</span></p><br>
                <?php else:?>
                    <p>your type:  <span style="color:#FF9800; font-size:0.8em;">(Member)</span><br></p>
                <?php endif; ?>
                <span style="color:<?= $room_status === 'private' ? '#f44336' : '#FF9800' ?>; font-size:0.8em;">
                    <?= $room_status === 'private' ? '🔒 Private' : '🌐 Public' ?>
                </span>
            </h3>

            <!-- members count-->
            <div class="countuser">
                <header>Activity</header>
                <div class="meta-list">
                    <h3>Members (<?= count($participants) ?>)</h3><br>
                    <?php 
                    if (count($participants) === count($parid)): 
                        for ($i = 0; $i < count($participants); $i++): 
                            $participant = $participants[$i];
                            $participantId = $parid[$i];
                    ?>
                        <div class="member-item">
                            <div style="display: flex; align-items: center; gap: 10px; flex-grow: 1;">
                                <i class="fa-solid fa-circle-user" style="font-size: 1.5em; color: #666;"></i>
                                <div>
                                    <h4 style="margin: 0; font-size: 1.1em;">
                                        <?= htmlspecialchars($participant) ?>
                                    </h4>
                                </div>
                            </div>

                            <?php if($is_creator && $participantId !== $visitor_id): ?>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <!-- Make Owner Form -->
                                <form action="" method="post" style="margin: 0;">
                                    <input type="hidden" name="new_owner_id" value="<?= $participantId ?>">
                                    <input type="hidden" name="new_owner_name" value="<?= htmlspecialchars($participant) ?>">
                                    <button type="submit" name="make_owner" 
                                            onclick="return confirm('Are you sure you want to make <?= htmlspecialchars($participant) ?> the new room owner? You will lose ownership of this room.')"
                                            style="background: #4CAF50; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.9em; display: flex; align-items: center; gap: 5px;">
                                        <i class="fa-solid fa-crown"></i> Make Owner
                                    </button>
                                </form>
                                <!-- Remove User Form -->
                                <form action="" method="post" style="margin: 0;">
                                    <input type="hidden" name="remove_user_id" value="<?= $participantId ?>">
                                    <input type="hidden" name="remove_user_name" value="<?= htmlspecialchars($participant) ?>">
                                    <button type="submit" name="remove_user" 
                                            onclick="return confirm('Are you sure you want to remove <?= htmlspecialchars($participant) ?> from this room? This user will be banned from joining again.')"
                                            style="background: #f44336; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.9em; display: flex; align-items: center; gap: 5px;">
                                        <i class="fa-solid fa-user-slash"></i> Remove
                                    </button>
                                </form>
                            </div>
                            <?php elseif($is_creator): ?>
                                <span style="color: #4CAF50; font-weight: bold; padding: 5px 10px; background: #e8f5e8; border-radius: 4px;">
                                    <i class="fa-solid fa-crown"></i> Room Owner
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php 
                        endfor;
                    else: 
                        echo "<p style='color: #f44336; text-align: center;'>Error: Participant data mismatch</p>";
                    endif; 
                    ?>
                </div>

                <!-- banned users -->
                <div class="meta-list">
                    <h3>Banned Members (<?= count($banned_users) ?>)  
                    <?php if(count($banned_users) > 0): ?>     
                    <form action="" method="post">
                        <input type="hidden" value="<?=$room?>">
                        <button name="unbanuser">unban all users</button>
                    </form>
                    <?php endif;?>
                    </h3><br>

                    <?php if (empty($banned_users)): ?>
                        <small>There are no banned users. If users get banned, they will appear here.</small>
                    <?php else: ?>
                        <?php if (count($banned_users) === count($banned_nickname)): ?>
                            <?php for ($k = 0; $k < count($banned_users); $k++): 
                                $bannedid = $banned_users[$k];
                                $bannedname = $banned_nickname[$k];

                                if(isset($_POST['unbanuser'])){
                                    $unba_stmt = $conn->prepare("DELETE FROM banned_users WHERE room_code = ? AND user_id = ?");
                                    $unba_stmt->bind_param("ss", $room, $bannedid);
                                }

                                if($bannedid === $room_data['creator_id']){
                                    $remove_user = $conn->prepare("DELETE FROM banned_users WHERE room_code = ? AND user_id = ?");
                                    $remove_user->bind_param("ss", $room, $participantId);
                                }
                            ?>
                                <div class="member-item" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding: 10px; border: 1px solid #ff4444; border-radius: 5px; background: #2a1f1f;">
                                    <div style="display: flex; align-items: center; gap: 10px; flex-grow: 1;">
                                        <i class="fa-solid fa-user-slash" style="font-size: 1.5em; color: #ff4444;"></i>
                                        <div>
                                            <h4 style="margin: 0; font-size: 1.1em; color: white;">
                                                <?= htmlspecialchars($bannedname) ?>
                                            </h4>
                                            <small style="color: #ccc;">Banned from this room</small>
                                        </div>
                                    </div>
                                    
                                    <?php if($is_creator): ?>
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <!-- Remove ban Form -->
                                        <form action="" method="post" style="margin: 0;">
                                            <input type="hidden" name="unban_user_id" value="<?= $bannedid ?>">
                                            <button type="submit" name="unban_user" 
                                                    onclick="return confirm('Are you sure you want to unban <?= htmlspecialchars($bannedname) ?>? They will be able to join the room again.')"
                                                    style="background: #4CAF50; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.9em; display: flex; align-items: center; gap: 5px;">
                                                <i class="fa-solid fa-user-check"></i> Unban User
                                            </button>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endfor; ?>
                        <?php else: ?>
                            <small style="color: #f44336;">Error: Banned users data mismatch</small>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <hr>
                <div class="setbuttons">
                    <?php if(!$is_creator):?>
                    <form action="" method="post">
                        <button type="submit" class="close-modal-btn" style="position: absolute; top: 10px; right: 10px;" name="leaveroom">leave Room <span style="color:#FF9800; font-size:0.8em;"><?=$room?></span> </button>
                    </form>
                    <?php endif;?> 
                    
                    <!-- delete room form -->
                    <?php if($is_creator): ?>
                    <form id="deleteRoomForm" method="post" action="delete-room.php" style="flex: 1;">
                        <input type="hidden" name="code" value="<?= htmlspecialchars($room) ?>">
                        <button type="submit" class="btn-secondary" style="background:red;" onclick="return confirm('Are you sure you want to delete this room and all messages?')">
                            <i class="fa-solid fa-trash"></i> <span>Delete Room</span>
                        </button>
                    </form>
                    
                    <!-- change room status -->
                    <form method="POST" action="roomstat.php" style="display: inline;">
                        <input type="hidden" name="room" value="<?= htmlspecialchars($room) ?>">
                        <input type="hidden" name="status" value="<?= $room_status === 'public' ? 'private' : 'public' ?>">
                        <button type="submit" class="btn-secondary" onclick="return confirm('Change room to <?= $room_status === 'public' ? 'private' : 'public' ?>?')">
                            <?= $room_status === 'public' ? '🔒 Make Private' : '🌐 Make Public' ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- change room member limit -->
                <?php if($is_creator): ?>
                <div class="">
                    <p>you went to limit member number?</p>
                    <form action="" id="parlimitform" method="post">
                        <input type="number" class="inputs" style="padding: 20px" name="changelimit" placeholder="input your limit number" min="1" value="<?=$roomlimit?>">
                        <button type="submit" name="changeroomlimit">change</button>
                    </form>
                </div><br>
                <?php endif; ?>
            </div>

            <div class="inputses">
                <h4 style="color: #4CAF50; margin-bottom: 20px; text-align: center;">Invite to Chat</h4><br>
                <?php if($room_status === 'private' && $is_creator): ?>
                    <div class="invitation">
                        <br>
                        <h3>this is your invite code for this room </h3><br>
                        <textarea id="inviteLink" style="padding: 5px ; width: 100%; background: #FF9800; font-size: 19px;" name="pass" value="<?=$room?> this is your room name  and  <?= $room_invi ?>  this is your invitation code. " readonly> "<?=$room?>" this is your room name  and " <?= $room_invi ?>  "this is your invitation code.</textarea>
                        <button onclick="copyInviteLink()">Copy</button>
                    </div><br>
                    <small>this is your room code to enter </small>
                    <input type="text" value="<?=$room_invi?>" style="padding: 20px" class="inputs" id="inviteCode" readonly>
                    <button onclick="copyInviteCode()">Copy room code</button>
                <?php endif; ?>
                <br>
                <label for="invitelink">this is room name for invite</label>
                <input type="text" value="<?=$room?>" name="invitelink" style="padding: 20px" class="inputs" id="roomName" readonly>
                <button onclick="copyRoomName()">Copy room name</button>
                
                <?php if($room_status === 'private'): ?>
                    <div style="background: rgba(255,107,107,0.1); padding: 10px; border-radius: 6px; text-align: center;">
                        <small style="color: #f44336;">🔒 Private Room - Requires invitation.</small>
                    </div>
                <?php endif; ?>

                <div id="copyStatus" style="margin-top: 15px; text-align: center; color: #4CAF50; font-size: 14px; display: none;"></div>
            </div>
        </div>
    </div>

    <div id="messages" aria-live="polite"></div>
    
    <div class="sbb">
        <div class="inputputton">
            <div class="textarea">
                <textarea id="msgInput" placeholder="Type your message..."></textarea>
                <button id="sendBtn" title="Send"><i class="fa-solid fa-paper-plane"></i></button>
            </div>
            <div>
                <button type="button" onclick="openUploadModal()" title="Upload File"><i class="fa-solid fa-paperclip"></i></button>
            </div>
        </div>
    </div>
    
    <!-- Image Popup Modal -->
    <div id="imagePopup" class="image-popup-overlay">
        <div class="image-popup-container">
            <span class="image-popup-close" onclick="closeImagePopup()">✕</span>
            <img id="popupImage" src="" alt="" class="image-popup-content">
            <div class="image-popup-info">
                <div class="sender" id="popupSender"></div>
                <div class="timestamp" id="popupTimestamp"></div>
            </div>
        </div>
    </div>
    
    <!-- Upload Modal -->
    <div id="uploaderlay">
        <div id="cvvvv">
            <button onclick="closeUploadModal()" class="close-modal-btn">✕</button><br>
            <form id="uploadForm" enctype="multipart/form-data">
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <div style="flex: 1; position: relative;">
                        <input type="file" name="files" id="fileInput" multiple style="width: 100%;">
                        <small id="fileCount" style="position: absolute; bottom: -20px; left: 0; color: #666; font-size: 12px;">
                            No files selected
                        </small>
                    </div>
                    <button type="submit" class="upplod" title="Upload"><i class="fa-solid fa-upload"></i></button>
                </div>
            </form>
            <div id="progressContainer" style="display: none; margin-top: 10px;">
                <div id="progressBar"></div>
            </div>
            <div id="uploadStatus" style="margin-top: 10px; text-align: center;"></div>
            <hr style="border-color: #ddd; margin: 20px 0;">
        </div>
    </div>

<script>
// PHP variables for JS
const room = "<?= $room ?>";
const name = "<?= $name ?>";
const visitorId = "<?= $visitor_id ?>";

// --- Image Popup Functions ---
function openImagePopup(imageSrc, sender, timestamp) {
    const popup = document.getElementById('imagePopup');
    const popupImage = document.getElementById('popupImage');
    const popupSender = document.getElementById('popupSender');
    const popupTimestamp = document.getElementById('popupTimestamp');
    
    popupImage.src = imageSrc;
    popupSender.textContent = `From: ${sender}`;
    popupTimestamp.textContent = `Sent: ${timestamp}`;
    
    popup.style.display = 'flex';
    setTimeout(() => {
        popup.classList.add('active');
    }, 10);
    
    document.body.style.overflow = 'hidden';
}

function closeImagePopup() {
    const popup = document.getElementById('imagePopup');
    popup.classList.remove('active');
    setTimeout(() => {
        popup.style.display = 'none';
        document.body.style.overflow = 'auto';
    }, 300);
}

document.getElementById('imagePopup').addEventListener('click', function(event) {
    if (event.target === this) {
        closeImagePopup();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('imagePopup').style.display === 'flex') {
        closeImagePopup();
    }
});

// --- UI Toggle Functions ---
function openchoice(){
    const choice = document.getElementById('choice');
    choice.style.display = (choice.style.display === "flex") ? 'none' : 'flex';
}

function opneparr(){
    const dds = document.getElementById("parr");
    dds.style.display = (dds.style.display === "block") ? "none" :"block";
}

function openinvite() {
    document.getElementById('invite').style.display = 'flex';
}
function closeerror() {
const closeerror = document.querySelector('.alert');
if (closeerror) {
    closeerror.style.display = "none";
}
}


function closeinvite() {
    document.getElementById('invite').style.display = 'none';
    document.getElementById('copyStatus').style.display = 'none';
}

function openUploadModal() {
    document.getElementById('uploaderlay').style.display = 'flex';
    document.getElementById('uploadForm').reset();
    document.getElementById('uploadStatus').innerHTML = '';
    document.getElementById('progressContainer').style.display = 'none';
    const submitBtn = document.querySelector('#uploadForm button[type="submit"]');
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="fa-solid fa-upload"></i>';
}

function closeUploadModal() {
    document.getElementById('uploaderlay').style.display = 'none';
}

document.getElementById('uploaderlay').addEventListener('click', function(event) {
    if (event.target === this) {
        closeUploadModal();
    }
});

document.getElementById('invite').addEventListener('click', function(event) {
    if (event.target === this) {
        closeinvite();
    }
});

// --- Clipboard Functions ---
function showCopyStatus(message) {
    const status = document.getElementById('copyStatus');
    status.textContent = '✅ ' + message;
    status.style.display = 'block';
    setTimeout(() => {
        status.style.display = 'none';
    }, 2000);
}

function changecolor(){
    const bg = document.querySelector('body');
    const iii = document.getElementById('butto');
    bg.style.background = (bg.style.background === "#8b949e") ? '#0f1419' : '#585858ff';
    iii.innerText = (iii.innerText === "dark") ? 'light' : 'dark';
}

function copyInviteLink() {
    const input = document.getElementById('inviteLink');
    navigator.clipboard.writeText(input.value).then(() => {
        showCopyStatus('code copied!');
    }).catch(err => console.error('Could not copy text: ', err));
}

function copyRoomCode() {
    const input = document.getElementById('roomCode');
    navigator.clipboard.writeText(input.value).then(() => {
        showCopyStatus('Room code copied!');
    }).catch(err => console.error('Could not copy text: ', err));
}

// --- Message & Fetch Logic ---
function fetchMessages(){
    const messagesDiv = $('#messages')[0];
    let shouldScroll = false;
    
    if (messagesDiv) {
        const isScrolledToBottom = messagesDiv.scrollHeight - messagesDiv.clientHeight <= messagesDiv.scrollTop + 1;
        if (isScrolledToBottom || messagesDiv.scrollTop === 10000) {
            shouldScroll = true;
        }
    }

    $.post('fetch.php', { room: room, visitor_id: visitorId }, function(response){
        if(response && response.success) {
            $('#messages').html(response.html);
            
            // Re-attach image click handlers
            $('.chat-image').off('click').on('click', function() {
                const imageSrc = $(this).attr('src');
                const sender = $(this).closest('.chat-message').find('.name b').text();
                const timestamp = $(this).closest('.chat-message').find('.time').text();
                openImagePopup(imageSrc, sender, timestamp);
            });
            
            if (shouldScroll) {
                $('#messages').scrollTop($('#messages')[0].scrollHeight);
            }
        }
    }, 'json').fail(function(xhr, status, error) {
        console.error('Failed to fetch messages:', error);
    });
}

function sendMessage(){
    const message = $('#msgInput').val().trim();
    if(message === '') return;
    
    $.post('sendmsg.php', {
        room: room,
        name: name,
        message: message,
        visitor_id: visitorId
    }, function(response){
        if(response && response.status === 'success'){
            $('#msgInput').val('');
            fetchMessages();
        } else {
            alert('Error: ' + (response ? response.message : 'Unknown error'));
        }
    }, 'json').fail(function() {
        alert('Error sending message');
    });
}

$('#sendBtn').click(sendMessage);
$('#msgInput').keypress(function(e){
    if(e.which == 13 && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
});

// File count display
$('#fileInput').on('change', function() {
    const fileCount = this.files.length;
    const fileCountElement = $('#fileCount');
    
    if (!fileCountElement.length) {
        // Create file count element if it doesn't exist
        $(this).after('<small id="fileCount" style="color: #666; font-size: 12px; display: block; margin-top: 5px;"></small>');
    }
    
    const updatedElement = $('#fileCount');
    if (fileCount === 0) {
        updatedElement.text('No files selected');
    } else if (fileCount === 1) {
        updatedElement.text('1 file selected');
    } else {
        updatedElement.text(`${fileCount} files selected`);
    }
});

$('#uploadForm').submit(function(e){
    e.preventDefault();
    
    const fileInput = $('#fileInput')[0];
    const submitBtn = $(this).find('button[type="submit"]');
    
    submitBtn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i>');
    
    if (!fileInput.files || !fileInput.files[0]) {
        $('#uploadStatus').html('❌ Please select a file first');
        submitBtn.prop('disabled', false).html('<i class="fa-solid fa-upload"></i>');
        return;
    }
    
    // Validate total file size
    let totalSize = 0;
    const maxTotalSize = 50 * 1024 * 1024; // 50MB total limit
    
    for (let file of fileInput.files) {
        totalSize += file.size;
    }
    
    if (totalSize > maxTotalSize) {
        $('#uploadStatus').html('❌ Total file size exceeds 50MB limit');
        submitBtn.prop('disabled', false).html('<i class="fa-solid fa-upload"></i>');
        return;
    }
    
    const formData = new FormData();
    // Add all selected files
    for (let i = 0; i < fileInput.files.length; i++) {
        formData.append('files[]', fileInput.files[i]);
    }
    formData.append('room', room);
    formData.append('name', name);
    formData.append('visitor_id', visitorId);

    $('#progressContainer').show();
    $('#uploadStatus').html('Uploading...');
    
    $.ajax({
        url: 'upload.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        xhr: function(){
            const xhr = new XMLHttpRequest();
            xhr.upload.addEventListener('progress', function(e){
                if(e.lengthComputable){
                    const percent = (e.loaded / e.total) * 100;
                    $('#progressBar').width(percent + '%');
                    $('#uploadStatus').html(`Uploading... ${Math.round(percent)}%`);
                }
            });
            return xhr;
        },
        success: function(response){
            console.log('Upload response:', response); // Debug log
            
            let statusMessage = '';
            
            // Check if response is valid
            if (!response) {
                statusMessage = '❌ No response from server';
            } else if (response.status === 'success') {
                statusMessage = `✅ Successfully uploaded ${response.success_count} file(s)`;
            } else if (response.status === 'partial') {
                statusMessage = `⚠️ Uploaded ${response.success_count} file(s), ${response.error_count} failed`;
                
                if (response.details) {
                    const failedFiles = response.details.filter(file => file.status === 'error');
                    if (failedFiles.length > 0) {
                        statusMessage += '<br><small>Failed: ' + failedFiles.map(f => f.file).join(', ') + '</small>';
                    }
                }
            } else {
                statusMessage = '❌ Upload failed';
                
                if (response.details && response.details.length > 0) {
                    const errorMessages = response.details.map(file => `${file.file}: ${file.message}`).join('<br>');
                    statusMessage += '<br><small>' + errorMessages + '</small>';
                } else if (response.message) {
                    statusMessage += '<br><small>' + response.message + '</small>';
                }
            }
            
            $('#uploadStatus').html(statusMessage);
            $('#progressContainer').hide();
            $('#progressBar').width('0%');
            
            submitBtn.prop('disabled', false).html('<i class="fa-solid fa-upload"></i>');
            
            // Refresh messages if any files were successfully uploaded
            if (response && response.success_count > 0) {
                fetchMessages();
            }
            
            $('#uploadForm')[0].reset();
            $('#fileCount').text('No files selected');
            
            // Only close modal if all files were successful
            if (response && response.status === 'success') {
                setTimeout(() => {
                    closeUploadModal();
                }, 2000);
            }
        },
        error: function(xhr, status, error){
            console.error('Upload error:', error, xhr.responseText);
            
            let errorMessage = '❌ Upload failed';
            
            // Try to parse error response if available
            try {
                if (xhr.responseText) {
                    const errorResponse = JSON.parse(xhr.responseText);
                    errorMessage = errorResponse.message || errorMessage;
                }
            } catch (e) {
                // If we can't parse JSON, show the actual server response
                if (xhr.responseText.includes('error') || xhr.responseText.includes('Warning')) {
                    errorMessage = '❌ Server error: Check PHP error logs';
                } else {
                    errorMessage = '❌ Server returned invalid response';
                }
                console.error('Raw server response:', xhr.responseText);
            }
            
            $('#uploadStatus').html(errorMessage);
            $('#progressContainer').hide();
            $('#progressBar').width('0%');
            submitBtn.prop('disabled', false).html('<i class="fa-solid fa-upload"></i>');
        }
    });
});

// Initial fetch and polling
$(document).ready(function() {
    fetchMessages();
    setInterval(fetchMessages, 2000);
});
const mode = document.querySelector('#mode');
const body = document.querySelector('#messages');
const bg = 'url(<?= htmlspecialchars($roomLogo) ?>)';

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
        body.style.background = bg;
        body.style.backgroundSize = "cover";
        body.style.backgroundPosition = "center";
        body.style.backgroundRepeat = "no-repeat";
    } else if(theme === "dark"){
        body.style.background = "#0f1419";
        body.style.backgroundImage = "none"; 
    } else if(theme === "light"){
        body.style.backgroundImage = "linear-gradient(135deg, black, white,blue, black)";
        body.style.backgroundSize = "cover";
        body.style.backgroundAttachment = "fixed";
    }
}
</script>
</body>
</html>