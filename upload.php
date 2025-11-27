<?php
// Start output buffering to prevent any accidental output
ob_start();

require 'db.php';

// Set header first to ensure proper JSON response
header('Content-Type: application/json');

if (!isset($_COOKIE['visitor_id'])) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$room = $_POST['room'] ?? '';
$name = $_POST['name'] ?? '';
$user = $_COOKIE['visitor_id'];

// Validate required fields
if (empty($room) || empty($name)) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Room and name are required']);
    exit;
}

// Allowed file types
$allowed = [
    // Images
    'jpg','jpeg','png','gif','bmp','webp','svg','ico',
    // Videos
    'mp4','avi','mov','wmv','flv','webm','mkv',
    // Audio
    'mp3','wav','ogg','m4a','aac','flac',
    // Documents
    'pdf','doc','docx','xls','xlsx','ppt','pptx','txt','rtf',
    // Archives
    'zip','rar','7z','tar','gz',
    // Code
    'csv','sql','json','xml','js','css','html','php','py','java','cpp','c','cs',
    // Executables
    'exe','msi','apk','deb','rpm',
    // Other
    'psd','ai','eps','tiff'
];

$maxFileSize = 10 * 1024 * 1024; // 10MB per file
$maxTotalSize = 50 * 1024 * 1024; // 50MB total
$uploadDir = "uploads/";

// Create upload directory if it doesn't exist
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => 'Could not create upload directory']);
        exit;
    }
}

// Check if files were uploaded
if (empty($_FILES['files']['name'][0])) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'No files selected']);
    exit;
}

$files = $_FILES['files'];
$uploadResults = [];
$successCount = 0;
$errorCount = 0;
$uploadedFiles = [];
$filePaths = [];

// Process each file first (validate and move)
foreach ($files['name'] as $index => $fileName) {
    $file = [
        'name' => $files['name'][$index],
        'type' => $files['type'][$index],
        'tmp_name' => $files['tmp_name'][$index],
        'error' => $files['error'][$index],
        'size' => $files['size'][$index]
    ];

    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadResults[] = [
            'file' => $fileName, 
            'status' => 'error', 
            'message' => getUploadError($file['error'])
        ];
        $errorCount++;
        continue;
    }
    
    if ($file['size'] > $maxFileSize) {
        $uploadResults[] = [
            'file' => $fileName, 
            'status' => 'error', 
            'message' => 'File too large (max 10MB)'
        ];
        $errorCount++;
        continue;
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowed)) {
        $uploadResults[] = [
            'file' => $fileName, 
            'status' => 'error', 
            'message' => 'File type not allowed'
        ];
        $errorCount++;
        continue;
    }

    // Generate safe filename
    $safeName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = $uploadDir . $safeName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $uploadedFiles[] = [
            'original_name' => $fileName,
            'safe_name' => $safeName,
            'path' => $targetPath,
            'type' => $file['type']
        ];
        $filePaths[] = $targetPath;
        $successCount++;
    } else {
        $uploadResults[] = [
            'file' => $fileName, 
            'status' => 'error', 
            'message' => 'Failed to move uploaded file'
        ];
        $errorCount++;
    }
}

// If we have successfully uploaded files, create ONE database entry with all files
if (count($uploadedFiles) > 0) {
    // Create a message that lists all files
    $fileNames = array_map(function($file) {
        return htmlspecialchars(basename($file['original_name']), ENT_QUOTES, 'UTF-8');
    }, $uploadedFiles);
    
    $message = "📎 " . count($uploadedFiles) . " file(s): " . implode(', ', $fileNames);
    
    // Store file paths as JSON in the database
    $filePathsJson = json_encode($filePaths);
    
    $stmt = $conn->prepare("INSERT INTO messages (room_code, nickname, visitor_hash, file_path, message, file_paths) VALUES (?, ?, ?, ?, ?, ?)");
    
    if ($stmt) {
        // Use the first file path as the main file_path (for backward compatibility)
        $mainFilePath = $uploadedFiles[0]['path'];
        
        $stmt->bind_param("ssssss", $room, $name, $user, $mainFilePath, $message, $filePathsJson);
        if ($stmt->execute()) {
            // All files successfully processed
            foreach ($uploadedFiles as $file) {
                $uploadResults[] = [
                    'file' => $file['original_name'], 
                    'status' => 'success', 
                    'message' => 'Uploaded successfully'
                ];
            }
        } else {
            // Database insert failed - delete all uploaded files
            foreach ($filePaths as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
            $errorCount += count($uploadedFiles);
            $successCount = 0;
            foreach ($uploadedFiles as $file) {
                $uploadResults[] = [
                    'file' => $file['original_name'], 
                    'status' => 'error', 
                    'message' => 'Database insert failed'
                ];
            }
        }
        $stmt->close();
    } else {
        // Database preparation failed - delete all uploaded files
        foreach ($filePaths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $errorCount += count($uploadedFiles);
        $successCount = 0;
        foreach ($uploadedFiles as $file) {
            $uploadResults[] = [
                'file' => $file['original_name'], 
                'status' => 'error', 
                'message' => 'Database preparation failed'
            ];
        }
    }
}

// Update room last active if at least one file was successfully uploaded
if ($successCount > 0) {
    $update = $conn->prepare("UPDATE rooms SET last_active = NOW() WHERE code = ?");
    if ($update) {
        $update->bind_param("s", $room);
        $update->execute();
        $update->close();
    }
}

// Prepare final response
$response = [
    'status' => $successCount > 0 ? ($errorCount > 0 ? 'partial' : 'success') : 'error',
    'message' => "Uploaded {$successCount} file(s), {$errorCount} failed",
    'details' => $uploadResults,
    'success_count' => $successCount,
    'error_count' => $errorCount,
    'total_files' => count($files['name'])
];

// Clean output buffer and send JSON response
ob_end_clean();
echo json_encode($response);
exit;

/**
 * Get upload error message
 */
function getUploadError($errorCode) {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'File too large';
        case UPLOAD_ERR_PARTIAL:
            return 'File only partially uploaded';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was uploaded';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Missing temporary folder';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Failed to write file to disk';
        case UPLOAD_ERR_EXTENSION:
            return 'File upload stopped by extension';
        default:
            return 'Unknown upload error';
    }
}
?>