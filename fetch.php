<?php
// fetch.php
require 'db.php';

header('Content-Type: application/json');

if (isset($_POST['room'])) {
    $room = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['room']);
    
    $stmt = $conn->prepare("SELECT id, nickname, message, file_path, created_at FROM messages WHERE room_code = ? ORDER BY id ASC");
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
        $msg = nl2br(trim($row['message']));
        $time = date("H:i", strtotime($row['created_at']));
        
        $html .= "<div class='chat-message'>";
        $html .= "<div class='name'><b>{$nick}</b></div>";
        $html .= "<div>";
        
        if ($row['file_path'] && file_exists($row['file_path'])) {
            $path = htmlspecialchars($row['file_path']);
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $fileName = htmlspecialchars(basename($path));

            if (in_array($ext, ['jpg','jpeg','png','gif'])) {
                // Make images clickable by adding chat-image class
                $html .= "<div class='preview'><img src='{$path}' alt='{$fileName}' class='chat-image'></div>";
            } elseif (in_array($ext, ['mp4','webm'])) {
                $html .= "<div class='preview'><a href='{$path}'><video class='chat-video'><source src='{$path}' type='video/{$ext}'>Your browser doesn't support video.</video></a></div>";
            } elseif (in_array($ext, ['mp3','wav'])) {
                $html .= "<div class='preview'><a href='{$path}' class='safe-link'><div >Music.{$ext}</div></a></div>";
            } else {
                $html .= "<a href='{$path}' download class='safe-link'>📄File</a>";
            }
        }
        
        $html .= " {$msg}";
        $html .= "<span class='time'>{$time}</span>";
        $html .= "</div></div>";
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