<?php
// fetch.php
require 'db.php';
header('Content-Type: application/json');
$visitor_id = $_COOKIE['visitor_id'] ?? '';

if (isset($_POST['room'])) {
    $room = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['room']);
    
    // First, get all user logos and their visitor hashes
    $userStmt = $conn->prepare("SELECT recovery_hash, user_logo FROM users WHERE user_logo IS NOT NULL AND user_logo != ''");
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    
    $userLogos = [];
    while ($userRow = $userResult->fetch_assoc()) {
        $userLogos[$userRow['recovery_hash']] = $userRow['user_logo'];
    }
    $userStmt->close();
    
    // Fetch messages - include file_paths column
    $stmt = $conn->prepare("SELECT id, nickname, message, file_path, file_paths, created_at, visitor_hash FROM messages WHERE room_code = ? ORDER BY id ASC");
    if (!$stmt) {
        echo json_encode(['success' => false, 'html' => 'Database error']);
        exit;
    }

    $stmt->bind_param("s", $room);
    $stmt->execute();
    $result = $stmt->get_result();

    $html = '';
    $messageCount = 0;

    while ($row = $result->fetch_assoc()) {
        $messageCount++;
        $id = $row['id'];
        $nick = htmlspecialchars($row['nickname']);
        $firstLetter = strtoupper(substr($nick ?: "?", 0, 1));
        $msg = nl2br(trim($row['message']));
        $time = date("H:i", strtotime($row['created_at']));

        // ✅ Detect whether it's the user's own message
        $isMine = ($row['visitor_hash'] === $visitor_id);
        $messageClass = $isMine ? "chat-message self" : "chat-message other";

        $html .= "<div class='{$messageClass}'>";
        
        // Check if this user has a profile logo
        if (isset($userLogos[$row['visitor_hash']]) && !empty($userLogos[$row['visitor_hash']])) {
            $userLogo = htmlspecialchars($userLogos[$row['visitor_hash']]);
            $html .= "<img src='{$userLogo}' class='logoimg' alt='{$nick}'>";
        } else {
            $html .= "<div class='logotxt'>{$firstLetter}</div>";
        }
        
        $html .= "<div class='message-content'>";
        $html .= "<div class='name'><b>{$nick}</b></div>";
        
        // Handle multiple files from file_paths (JSON array)
        $hasMultipleFiles = false;
        if (!empty($row['file_paths'])) {
            $filePaths = json_decode($row['file_paths'], true);
            if (is_array($filePaths) && count($filePaths) > 0) {
                $hasMultipleFiles = true;
                $html .= "<div class='file-attachments'>";
                
                foreach ($filePaths as $filePath) {
                    if (file_exists($filePath)) {
                        $path = htmlspecialchars($filePath);
                        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                        $fileName = htmlspecialchars(basename($path));

                        if (in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg'])) {
                            $html .= "<div class='file-attachment'>";
                            $html .= "<img src='{$path}' alt='{$fileName}' class='chat-image' onclick='openImagePopup()'>";
                            $html .= "</div>";
                        } elseif (in_array($ext, ['mp4','webm','avi','mov','wmv'])) {
                            $html .= "<div class='file-attachment'>";
                            $html .= "<a href='{$path}' download class='file-download'>🎥 {$fileName}</a>";
                            $html .= "</div>";
                        } elseif (in_array($ext, ['mp3','wav','ogg','m4a','aac','flac'])) {
                            $html .= "<div class='file-attachment'>";
                            $html .= "<a href='{$path}' download class='file-download'>🎵 {$fileName}</a>";
                            $html .= "</div>";
                        } else {
                            $html .= "<div class='file-attachment'>";
                            $html .= "<a href='{$path}' download class='file-download'>📄 {$fileName}</a>";
                            $html .= "</div>";
                        }
                    }
                }
                $html .= "</div>"; // .file-attachments
            }
        }
        
        // Handle single file from file_path (for backward compatibility)
        if (!$hasMultipleFiles && !empty($row['file_path']) && file_exists($row['file_path'])) {
            $path = htmlspecialchars($row['file_path']);
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $fileName = htmlspecialchars(basename($path));

            $html .= "<div class='file-attachments'>";
            $html .= "<div class='file-attachment'>";
            
            if (in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg'])) {
                $html .= "<img src='{$path}' class='chat-image'>";
            } elseif (in_array($ext, ['mp4','webm','avi','mov','wmv'])) {
                $html .= "<a href='{$path}' download class='file-download'>🎥{$fileName}</a>";
            } elseif (in_array($ext, ['mp3','wav','ogg','m4a','aac','flac'])) {
                $html .= "<a href='{$path}' download class='file-download'>🎵 {$fileName}</a>";
            } else {
                $html .= "<a href='{$path}' download class='file-download'>📄 {$fileName}</a>";
            }
            
            $html .= "</div>"; // .file-attachment
            $html .= "</div>"; // .file-attachments
        }

        // Message text and time
        if (!empty(trim($row['message']))) {
            $html .= "<div class='message-text'>{$msg}</div>";
        }
        
        $html .= "<div class='message-footer'>";
        $html .= "<span class='time'>{$time}</span>";
        
        // Delete button only for own messages
        if ($isMine) {
            $html .= " <a href='delete.php?room=" . htmlspecialchars($room) . "&delete={$id}' class='delete-btn'>🗑️</a>";
        }
        
        $html .= "</div>"; // .message-footer
        $html .= "</div>"; // .message-content
        $html .= "</div>"; // .chat-message
    }

    if ($messageCount === 0) {
        $html = "<div style='text-align:center; color:#888; padding:20px;'>No messages yet. Start the conversation!</div>";
    }

    $stmt->close();
    echo json_encode(['success' => true, 'html' => $html, 'count' => $messageCount]);
} else {
    echo json_encode(['success' => false, 'html' => 'No room specified']);
}
?>