<?php
ini_set('session.use_strict_mode', '1');
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
$token = $_POST['csrf_token'] ?? null;
if (!is_string($token)
    || empty($_SESSION['admin_action_csrf'])
    || !hash_equals($_SESSION['admin_action_csrf'], $token)) {
    http_response_code(403);
    exit('Invalid security token.');
}
$appealId = filter_var($_POST['appeal_id'] ?? null, FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]);
$action = $_POST['action'] ?? null;
if (!$appealId || !is_string($action) || !in_array($action, ['approve', 'reject'], true)) {
    http_response_code(400);
    exit('Invalid request.');
}

$pdo = null;
try {
    $pdo = require __DIR__ . '/config/database.php';
    $adminId = (int) $_SESSION['user_id'];
    $adminStmt = $pdo->prepare(
        'SELECT role, account_status FROM users WHERE user_id = ? LIMIT 1'
    );
    $adminStmt->execute([$adminId]);
    $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin || $admin['role'] !== 'admin' || $admin['account_status'] !== 'active') {
        http_response_code(403);
        exit('Administrator access required.');
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'SELECT appeal_id, user_id, status FROM account_appeals
         WHERE appeal_id = ? FOR UPDATE'
    );
    $stmt->execute([$appealId]);
    $appeal = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$appeal || $appeal['status'] !== 'pending') {
        $pdo->rollBack();
        http_response_code(409);
        exit('This appeal is no longer pending.');
    }

    $accountStmt = $pdo->prepare(
        'SELECT role, account_status FROM users WHERE user_id = ? FOR UPDATE'
    );
    $accountStmt->execute([(int) $appeal['user_id']]);
    $target = $accountStmt->fetch(PDO::FETCH_ASSOC);
    if (!$target || $target['role'] !== 'user') {
        $pdo->rollBack();
        http_response_code(409);
        exit('The target is not a regular user account.');
    }
    if ($action === 'approve' && $target['account_status'] !== 'suspended') {
        $pdo->rollBack();
        http_response_code(409);
        exit('The account is no longer suspended. Refresh the Admin Dashboard.');
    }

    if ($action === 'approve') {
        $update = $pdo->prepare(
            "UPDATE users SET account_status = 'active' WHERE user_id = ?"
        );
        $update->execute([(int) $appeal['user_id']]);
        // The existing audit table already supports the 'reactivate' action.
        $audit = $pdo->prepare(
            "INSERT INTO admin_audit_logs (admin_user_id, target_user_id, action)
             VALUES (?, ?, 'reactivate')"
        );
        $audit->execute([$adminId, (int) $appeal['user_id']]);
    }

    $review = $pdo->prepare(
        'UPDATE account_appeals
         SET status = ?, reviewed_by = ?, reviewed_at = NOW()
         WHERE appeal_id = ? AND status = ?'
    );
    $review->execute([
        $action === 'approve' ? 'approved' : 'rejected',
        $adminId,
        $appealId,
        'pending'
    ]);
    $pdo->commit();
    header('Location: admin.php?appeal_result=' . ($action === 'approve' ? 'approved' : 'rejected') . '#account-appeals', true, 303);
    exit;
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('PFT admin appeal action failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to review this appeal.');
}
