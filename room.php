<?php
// room.php
require 'db.php';

// Ensure visitor cookie
if (!isset($_COOKIE['visitor_id'])) {
    $visitor_id = bin2hex(random_bytes(16));
    setcookie('visitor_id', $visitor_id, time() + (10*365*24*60*60), "/");
    $_COOKIE['visitor_id'] = $visitor_id;
}

$visitor_id = $_COOKIE['visitor_id'];

$room = trim($_GET['room'] ?? '');
$room_type = trim($_GET['room_type'] ?? 'public');

// Sanitize room code (alphanumeric and hyphens only)
$room = preg_replace('/[^a-zA-Z0-9_-]/', '', $room);

// Initialize name
if(!isset($_COOKIE['nickname'])) {
    $name = trim($_GET['name'] ?? '');
} else {
    $name = $_COOKIE['nickname'];
}

// Validate input
if ($room === '' || $name === '') {
    header("Location: index.php?error=Room+and+name+are+required");
    exit;
}

$name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

// Set nickname cookie
setcookie('nickname', $name, time() + (10*365*24*60*60), "/");

// Create room if not exists
$stmt = $conn->prepare("SELECT id, creator_id, status FROM rooms WHERE code = ?");
if (!$stmt) die("DB error on room check");
$stmt->bind_param("s", $room);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    // Create new room with specified type
    $ins = $conn->prepare("INSERT INTO rooms ( code, creator_id, status, last_active) VALUES (?, ?, ?, NOW())");
    $ins->bind_param("sss", $room, $visitor_id, $room_type);
    $ins->execute();
    $ins->close();
    $is_creator = true;
    $room_status = $room_type;
} else {
    $room_data = $res->fetch_assoc();
    $is_creator = ($room_data['creator_id'] === $visitor_id);
    $room_status = $room_data['status'];
    
    // Check if user can join private room
    if ($room_status === 'private' && !$is_creator) {
        $check_user = $conn->prepare("SELECT id FROM user_rooms WHERE user_id = ? AND room_code = ?");
        $check_user->bind_param("ss", $visitor_id, $room);
        $check_user->execute();
        $user_exists = $check_user->get_result()->num_rows > 0;
        $check_user->close();
        
        if (!$user_exists) {
            header("Location: index.php?error=This+is+a+private+room.+You+need+an+invitation+to+join.");
            exit;
        }
    }
}
$stmt->close();

// Record user in room
$up = $conn->prepare("INSERT INTO user_rooms (user_id, room_code, nickname, last_joined) VALUES (?, ?, ?, NOW()) 
                     ON DUPLICATE KEY UPDATE last_joined = NOW(), nickname = VALUES(nickname)");
$up->bind_param("sss", $visitor_id, $room, $name);
$up->execute();
$up->close();

// Update participants count
$cnt_sql = "UPDATE rooms SET participants = (SELECT COUNT(DISTINCT user_id) FROM user_rooms WHERE room_code = ?), last_active = NOW() WHERE code = ?";
$cnt = $conn->prepare($cnt_sql);
$cnt->bind_param("ss", $room, $room);
$cnt->execute();
$cnt->close();

// Get room participants
$part_stmt = $conn->prepare("SELECT nickname FROM user_rooms WHERE room_code = ? ORDER BY last_joined DESC");
$part_stmt->bind_param("s", $room);
$part_stmt->execute();
$participants_result = $part_stmt->get_result();
$participants = [];
while ($row = $participants_result->fetch_assoc()) {
    $participants[] = $row['nickname'];
}
$part_stmt->close();

// Get base URL for invites
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . "/index.php";

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
        /* Image Popup Styles */
        .image-popup-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
            z-index: 2000;
            opacity: 0;
            transition: opacity 0.3s ease;
            align-items: center;
            justify-content: center;
        }
        
        .image-popup-overlay.active {
            opacity: 1;
        }
        
        .image-popup-container {
            position: relative;
            max-width: 90%;
            max-height: 90%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .image-popup-content {
            max-width: 100%;
            max-height: 80vh;
            border-radius: 8px;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.5);
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }
        
        .image-popup-overlay.active .image-popup-content {
            transform: scale(1);
        }
        
        .image-popup-close {
            position: absolute;
            top: -40px;
            right: 0;
            color: white;
            font-size: 30px;
            cursor: pointer;
            background: rgba(0, 0, 0, 0.5);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s ease;
        }
        
        .image-popup-close:hover {
            background: rgba(255, 0, 0, 0.7);
        }
        
        .image-popup-info {
            color: white;
            text-align: center;
            margin-top: 15px;
            max-width: 600px;
        }
        
        .image-popup-info .sender {
            font-weight: bold;
            color: #4CAF50;
        }
        
        .image-popup-info .timestamp {
            color: #aaa;
            font-size: 0.9em;
        }
        
        /* Make chat images clickable */
        .chat-image {
            cursor: pointer;
            transition: transform 0.2s ease;
            max-width: 300px;
            border-radius: 8px;
            margin: 5px 0;
        }
        
        .chat-image:hover {
            transform: scale(1.03);
        }
        
        /* Chat message styles */

        
       
    </style>
</head>
<body>
    <div class="top">
        <h3>Room: <?= htmlspecialchars($room) ?> 
            <small style="color:#4CAF50;">You: <?= htmlspecialchars($name) ?></small>
            <?php if($is_creator): ?>
                <span style="color:#FF9800; font-size:0.8em;">(Creator)</span>
            <?php endif; ?>
            <span style="color:<?= $room_status === 'private' ? '#f44336' : '#FF9800' ?>; font-size:0.8em;">
                <?= $room_status === 'private' ? '🔒 Private' : '🌐 Public' ?>
            </span>
        </h3>
        <button onclick="openchoice()" id="choicef">Activity</button>
        <button onclick="opneparr()"> 
            <strong><i class="fa-solid fa-users"></i> (<?= count($participants) ?>)</strong>
        </button> 

        <div id="choice" style="display:none;">
           <a href="index.php" style="margin: 10px;"><button style="background: #f44336;"><i class="fa-solid fa-arrow-left"></i> <br/><span>back</span></button></a>
           <button onclick="openinvite()" style="">
               <i class="fa-solid fa-user-plus"></i> <br/><span>Invite</span>
           </button>
        </div>
        
        <div id="parr" style="display: none;">
            <div class="meta-list">
                <?php foreach($participants as $participant): ?>
                    <span style=""><i class="fa-solid fa-circle-user"></i> <?= htmlspecialchars($participant) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <div id="messages" aria-live="polite"></div>

    <div class="sbb">
        <div class="inputputton">
            <textarea id="msgInput" placeholder="Type your message..."></textarea>
            <div>
                <button id="sendBtn" title="Send"><i class="fa-solid fa-paper-plane"></i></button>
                <button type="button" onclick="openUploadModal()" title="Upload File"><i class="fa-solid fa-upload"></i></button>
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
    
    <div id="invite">
        <div>
             <button onclick="closeinvite()" class="close-modal-btn">✕</button>
             
             <h4 style="color: #4CAF50; margin-bottom: 20px; text-align: center;">Invite to Chat</h4>
             
             <div style="margin-bottom: 20px;">
                 <label style="color: #aaa; font-size: 14px;">Share base link:</label>
                 <div style="display: flex; gap: 10px; margin-top: 8px;">
                     <input type="text" id="inviteLink" value="<?= htmlspecialchars($base_url) ?>" readonly>
                     <button onclick="copyInviteLink()">Copy</button>
                 </div>
             </div>
             
             <div style="margin-bottom: 20px;">
                 <label style="color: #aaa; font-size: 14px;">Room code (Use with link):</label>
                 <div style="display: flex; gap: 10px; margin-top: 8px;">
                     <input type="text" id="roomCode" value="<?= htmlspecialchars($room) ?>" readonly style="font-size: 16px; font-weight: bold; text-align: center;">
                     <button onclick="copyRoomCode()" style="background: #FF9800;">Copy</button>
                 </div>
             </div>
             
             <?php if($room_status === 'private'): ?>
             <div style="background: rgba(255,107,107,0.1); padding: 10px; border-radius: 6px; text-align: center;">
                 <small style="color: #f44336;">🔒 Private Room - Requires invitation.</small>
             </div>
             <?php endif; ?>
             
             <div id="copyStatus" style="margin-top: 15px; text-align: center; color: #4CAF50; font-size: 14px; display: none;"></div>
         </div>
    </div>

    <div id="uploaderlay">
        <div id="cvvvv">
             <button onclick="closeUploadModal()" class="close-modal-btn">✕</button>
            <form id="uploadForm" enctype="multipart/form-data">
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <input type="file" name="file" id="fileInput" style="flex: 1;">
                    <button type="submit" title="Upload"><i class="fa-solid fa-upload"></i></button>
                </div>
            </form>
            <div id="progressContainer" style="display: none; margin-top: 10px;">
                <div id="progressBar"></div>
            </div>
            <div id="uploadStatus" style="margin-top: 10px; text-align: center;"></div>

            <hr style="border-color: #ddd; margin: 20px 0;">
            
            <?php if($is_creator): ?>
            <div class="deletebb">
                <form id="deleteRoomForm" method="post" action="delete-room.php" style="flex: 1;">
                    <input type="hidden" name="code" value="<?= htmlspecialchars($room) ?>">
                    <button type="submit" class="del-btn" onclick="return confirm('Are you sure you want to delete this room and all messages? This action cannot be undone.')">
                        <i class="fa-solid fa-trash"></i><br/><span>Delete Room</span>
                    </button>
                </form>
                
                <div style="flex: 1;">
                    <form method="POST" action="roomstat.php" style="display: inline;">
                        <input type="hidden" name="room" value="<?= htmlspecialchars($room) ?>">
                        <input type="hidden" name="status" value="<?= $room_status === 'public' ? 'private' : 'public' ?>">
                        <button type="submit" class="btn-secondary" onclick="return confirm('Change room to <?= $room_status === 'public' ? 'private' : 'public' ?>?')">
                            <?= $room_status === 'public' ? '🔒 Make Private' : '🌐 Make Public' ?>
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
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
    
    // Prevent body scroll when popup is open
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

// Close image popup when clicking outside the image
document.getElementById('imagePopup').addEventListener('click', function(event) {
    if (event.target === this) {
        closeImagePopup();
    }
});

// Close image popup with Escape key
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

// Close upload modal by clicking outside
document.getElementById('uploaderlay').addEventListener('click', function(event) {
    if (event.target === this) {
        closeUploadModal();
    }
});

// Close invite modal by clicking outside
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

function copyInviteLink() {
    const input = document.getElementById('inviteLink');
    navigator.clipboard.writeText(input.value).then(() => {
        showCopyStatus('Base URL copied!');
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
    const messagesDiv = $('#messages')[1000];
    let shouldScroll = false;
    if (messagesDiv) {
        const isScrolledToBottom = messagesDiv.scrollHeight - messagesDiv.clientHeight <= messagesDiv.scrollTop + 50;
        if (isScrolledToBottom || messagesDiv.scrollTop === 0) {
            shouldScroll = true;
        }
    }

    $.post('fetch.php', { room: room, visitor_id: visitorId }, function(response){
        if(response.success) {
            $('#messages').html(response.html);
            
            // Add click handlers to images after loading messages
            $('.chat-image').on('click', function() {
                const imageSrc = $(this).attr('src');
                const sender = $(this).closest('.chat-message').find('.name b').text();
                const timestamp = $(this).closest('.chat-message').find('.time').text();
                openImagePopup(imageSrc, sender, timestamp);
            });
            
            if (shouldScroll) {
                $('#messages').scrollTop($('#messages')[0].scrollHeight);
            }
        }
    }, 'json').fail(function() {
        console.error('Failed to fetch messages');
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
        if(response.status === 'success'){
            $('#msgInput').val('');
            fetchMessages();
        } else {
            alert('Error: ' + response.message);
        }
    }, 'json');
}

// Send text message handlers
$('#sendBtn').click(sendMessage);
$('#msgInput').keypress(function(e){
    if(e.which == 13 && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
});

// File upload with progress
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
    
    const formData = new FormData(this);
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
                }
            });
            return xhr;
        },
        success: function(response){
            $('#uploadStatus').html(response.message);
            $('#progressContainer').hide();
            $('#progressBar').width('0%');
            
            submitBtn.prop('disabled', false).html('<i class="fa-solid fa-upload"></i>');
            
            fetchMessages();
            $('#uploadForm')[0].reset();
            
            if (response.status === 'success') {
                setTimeout(() => {
                    closeUploadModal();
                }, 2000);
            }
        },
        error: function(){
            $('#uploadStatus').html('❌ Upload failed');
            $('#progressContainer').hide();
            submitBtn.prop('disabled', false).html('<i class="fa-solid fa-upload"></i>');
        }
    });
});

// Initial fetch and polling
fetchMessages();
setInterval(fetchMessages, 2000);
</script>
</body>
</html>