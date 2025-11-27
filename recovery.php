<?php
require 'db.php';

// ✅ If user already logged in → redirect
if (isset($_COOKIE['visitor_id'])) {
    header("Location: index.php");
    exit;
}

$error = "";
$success = "";

// ✅ Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode']; // "create" or "login"
    $recovery_raw = htmlspecialchars($_POST['recovery']);
    $name = htmlspecialchars($_POST['name']);

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $recovery_raw)) {
        $error = "❌ Invalid phrase! Only letters, numbers, hyphens, and underscores allowed.";
    } else {
        $recovery_hashed = hash('sha256', $recovery_raw);

        if ($mode === 'create') {
            // ✅ Check if phrase already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE recovery_hash = ? AND name = ?");
            $stmt->bind_param("ss", $recovery_hashed , $name);
            $stmt->execute();
            $check = $stmt->get_result();

            if ($check->num_rows > 0) {
                header("location:index.php");
            } else {
                // ✅ Insert new phrase
                $stmt = $conn->prepare("INSERT INTO users (name,recovery_phrase, recovery_hash, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->bind_param("sss",$name, $recovery_raw, $recovery_hashed);
                $stmt->execute();

                setcookie('visitor_id', $recovery_hashed, time() + (10*365*24*60*60), "/");
                setcookie('nickname', $name, time() + (10*365*24*60*60), "/");
                $_COOKIE['visitor_id'] = $recovery_hashed;

                header("Location: index.php?success=Welcome!+Your+recovery+phrase+was+created.");
                exit;
            }
        } elseif ($mode === 'login') {
            // ✅ Check if phrase exists in DB
            $stmt = $conn->prepare("SELECT id FROM users WHERE recovery_hash = ? AND name = ?");
            $stmt->bind_param("ss", $recovery_hashed ,$name);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                // ✅ Success: Set cookie
                setcookie('nickname', $name, time() + (10*365*24*60*60), "/");
                setcookie('visitor_id', $recovery_hashed, time() + (10*365*24*60*60), "/");
                
                $_COOKIE['visitor_id'] = $recovery_hashed;
                header("Location: index.php?success=Welcome+back!");
                exit;
            } else {
                $error = "Phrase not found or unmach phrase and name! Please try again or create a new one.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recovery Access</title>
<link rel="stylesheet" href="style.css?v<?php echo time();?>">

<style>
body {
  padding: 40px;
  font-family: Arial, sans-serif;
}
.hidden {
  display: none;
}
button{
    margin: 10px;
}
input{
  margin: 10px;
}
small{
  color: brown;
}
</style>
</head>
<body>

<div class="input-group">
  <h2 id="formTitle">Create Recovery Phrase</h2>
  <form method="POST" id="recoveryForm">
    <input type="hidden" name="mode" id="mode" value="create">
    <input type="text" name="recovery" id="recoveryInput" placeholder="e.g. my_safe_key" required pattern="[a-zA-Z0-9_-]+">
      <div class="input-group">
        <input type="text" id="nameinp" name="name" placeholder="Enter your display name" required maxlength="50">
        <small>this name uses every time you join or create rooms!</small>
      </div>
    <button type="submit" id="submitBtn" class="btn-primary">Create & Continue</button>
    <?php if (!empty($error)) echo "<div class='alert error'>$error</div>"; ?>
    <?php if (!empty($success)) echo "<div class='alert success'>$success</div>"; ?>
  </form>
  <div class="toggle">
    <button id="toggleLink" onclick="toggleMode()" class="btn-primary">Already have one? Enter here</button>
  </div>
<div class="form-section">
  <p class="info-text">
Your <strong>Recovery Phrase</strong> helps you return to your chat rooms anytime.
Enter the same recovery phrase you used before, and you’ll instantly regain access to all your joined and created rooms. 
Keep this phrase private and easy to remember. 
If you’re new, simply type any unique phrase to start fresh.
</p>
<ul class="tips">
  <li>Use letters, numbers, hyphens, or underscores only.</li>
  <li>Example: <code>chatroom_2025</code> or <code>my-safe-key</code>.</li>
  <li>Keep it private — it’s your access key to your rooms.</li>
</ul>
</div>
</div>
<script>
function toggleMode() {
  const modeInput = document.getElementById('mode');
  const title = document.getElementById('formTitle');
  const btn = document.getElementById('submitBtn');
  const link = document.getElementById('toggleLink');

  if (modeInput.value === 'create') {
    modeInput.value = 'login';
    title.textContent = 'Enter Existing Phrase';
    btn.textContent = 'Login & Continue';
    link.textContent = 'Create a new recovery phrase';
  } else {
    modeInput.value = 'create';
    title.textContent = 'Create Recovery Phrase';
    btn.textContent = 'Create & Continue';
    link.textContent = 'Already have one? Enter here';
  }
}
</script>

</body>
</html>
