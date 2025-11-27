<?php
// destroy_cookies.php
// List cookie names you want to remove
setcookie('visitor_id', '', time() - 3600, "/");
setcookie('nickname', '', time() - 3600, "/");

// Clear session data
session_start();
session_destroy();

header("Location: index.php?success=you+logged+out+succesfully");
exit;
