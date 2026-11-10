<?php
// A short-lived appeal permission is set only after a suspended user
// provides the correct password on login.php.
ini_set('session.use_strict_mode', '1');
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

date_default_timezone_set('Asia/Manila');
function appealEscape(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$sent = isset($_GET['submitted']) && $_GET['submitted'] === '1'
    && !empty($_SESSION['appeal_just_submitted']);
if ($sent) {
    unset($_SESSION['appeal_just_submitted']);
}

$authorized = !empty($_SESSION['suspended_appeal_user_id'])
    && !empty($_SESSION['suspended_appeal_expires'])
    && time() <= (int) $_SESSION['suspended_appeal_expires'];

if (!$authorized && !$sent) {
    http_response_code(403);
    exit('Please sign in with your suspended account and correct password before requesting a review.');
}

if ($authorized && empty($_SESSION['appeal_csrf_token'])) {
    $_SESSION['appeal_csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$message = '';

if ($authorized && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $input = $_POST['message'] ?? null;
    $message = is_string($input) ? trim($input) : '';

    if (!is_string($token) || !hash_equals($_SESSION['appeal_csrf_token'], $token)) {
        $error = 'Your form expired. Please reload and try again.';
    } elseif (strlen($message) < 20 || strlen($message) > 4000) {
        $error = 'Please explain your concern in 20–1,000 characters.';
    } else {
        $pdo = null;
        try {
            $pdo = require __DIR__ . '/config/database.php';
            $pdo->beginTransaction();
            $uid = (int) $_SESSION['suspended_appeal_user_id'];

            // Serializes simultaneous submissions for the same user.
            $account = $pdo->prepare(
                'SELECT role, account_status FROM users WHERE user_id = ? FOR UPDATE'
            );
            $account->execute([$uid]);
            $user = $account->fetch(PDO::FETCH_ASSOC);
            if (!$user || $user['role'] !== 'user' || $user['account_status'] !== 'suspended') {
                $pdo->rollBack();
                $error = 'This account is not currently eligible to submit a suspension appeal.';
            } else {
                // At most one pending appeal and one submission in any 24 hours.
                $recent = $pdo->prepare(
                    "SELECT appeal_id FROM account_appeals
                     WHERE user_id = ? AND
                           (status = 'pending' OR created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR))
                     LIMIT 1"
                );
                $recent->execute([$uid]);
                if ($recent->fetchColumn()) {
                    $pdo->rollBack();
                    $error = 'You already have a pending or recent appeal. Please allow time for review.';
                } else {
                    $insert = $pdo->prepare(
                        'INSERT INTO account_appeals (user_id, message) VALUES (?, ?)'
                    );
                    $insert->execute([$uid, $message]);
                    $pdo->commit();
                    unset(
                        $_SESSION['suspended_appeal_user_id'],
                        $_SESSION['suspended_appeal_expires'],
                        $_SESSION['appeal_csrf_token']
                    );
                    $_SESSION['appeal_just_submitted'] = true;
                    header('Location: account-appeal.php?submitted=1', true, 303);
                    exit;
                }
            }
        } catch (Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('PFT account appeal error: ' . $e->getMessage());
            $error = 'Unable to submit right now. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Account Review | PFT</title>
<style>
:root { color-scheme: dark; --lime:#c4ff5e; --muted:#afbaae; }
* { box-sizing:border-box; }
body { margin:0; min-height:100vh; display:grid; place-items:center;
       padding:20px; background:#101310; color:#f3f7ef; font:15px/1.6 Arial,sans-serif; }
main { width:min(100%,600px); padding:32px; background:#1b201c;
       border:1px solid #343d34; border-radius:16px; }
.logo { color:var(--lime); font-weight:800; letter-spacing:.16em; }
h1 { font-size:clamp(24px,4vw,34px); margin:20px 0 10px; }
p { color:var(--muted); }
label { display:block; font-weight:700; margin:24px 0 8px; }
textarea { display:block; width:100%; min-height:155px; background:#111610;
           color:#fff; padding:14px; border:1px solid #65715e; border-radius:9px;
           font:inherit; resize:vertical; }
button { background:var(--lime); color:#111610; border:0; border-radius:9px;
         cursor:pointer; margin-top:16px; padding:13px 18px; font-weight:800; }
a { color:var(--lime); }
.error { color:#ffb6aa; }
button:focus-visible,textarea:focus-visible,a:focus-visible { outline:2px solid var(--lime); outline-offset:3px; }
</style>
</head>
<body>
<main>
    <div class="logo">PFT / ACCOUNT SUPPORT</div>
    <?php if ($sent): ?>
        <h1>Appeal submitted</h1>
        <p>Your request is now pending review in the PFT Admin Dashboard.</p>
        <p>You will need to try signing in later to check whether your account has been reactivated.</p>
        <p><a href="login.php">Return to login</a></p>
    <?php else: ?>
        <h1>Request account review</h1>
        <p>Your account is suspended. Explain why you believe it should be reactivated.
           Do not include passwords, financial records, or other sensitive information.</p>
        <?php if ($error !== ''): ?>
            <p class="error" role="alert"><?= appealEscape($error) ?></p>
        <?php endif; ?>
        <form action="account-appeal.php" method="POST">
            <input type="hidden" name="csrf_token"
                   value="<?= appealEscape($_SESSION['appeal_csrf_token']) ?>">
            <label for="message">Your concern</label>
            <textarea name="message" id="message" minlength="20" maxlength="1000"
                      required placeholder="Explain your request (20–1,000 characters)"><?= appealEscape($message) ?></textarea>
            <button type="submit">Submit appeal</button>
        </form>
        <p><a href="login.php">Back to login</a></p>
    <?php endif; ?>
</main>
</body>
</html>
