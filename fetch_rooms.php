<?php
// fetch_rooms.php
require 'db.php';
header('Content-Type: application/json');

$visitor_id = $_COOKIE['visitor_id'] ?? '';

try {
    // Get active rooms (only public rooms or rooms the user has joined)
    $query = "
        SELECT r.code, r.participants,r.participant_limit, r.last_active, ur.nickname, r.status, r.logo_path
        FROM rooms r 
        LEFT JOIN user_rooms ur ON r.code = ur.room_code AND ur.user_id = ?
        WHERE r.status = 'public' OR ur.user_id = ?
        ORDER BY r.last_active DESC 
        LIMIT 20
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $visitor_id, $visitor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $rooms = [];


    while ($room = $result->fetch_assoc()) {   
 if($room['participant_limit'] === 0){
        $par_limit = "N/A";
    }else{
        $par_limit = $room['participant_limit'];
    }
        $rooms[] = [
            'code' => $room['code'],
            'participants' => $room['participants'],
            'last_active' => $room['last_active'],
            'nickname' => $room['nickname'],
            'status' => $room['status'],
            'logo_path' => $room['logo_path'],
            'user_limits'=>$par_limit
        ];
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'rooms' => $rooms,
        'count' => count($rooms)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching rooms: ' . $e->getMessage()
    ]);
}
?>