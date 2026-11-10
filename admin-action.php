<?php

ini_set("session.use_strict_mode", "1");
session_start();

header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

if (empty($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("Method not allowed.");
}

// Verify the form's security token.
$token = $_POST["csrf_token"] ?? null;

if (
    !is_string($token) ||
    empty($_SESSION["admin_action_csrf"]) ||
    !hash_equals($_SESSION["admin_action_csrf"], $token)
) {
    http_response_code(403);
    exit("Invalid security token.");
}

$targetId = filter_var(
    $_POST["target_id"] ?? null,
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

$action = $_POST["action"] ?? null;

if (
    !$targetId ||
    !is_string($action) ||
    !in_array($action, ["suspend", "reactivate"], true)
) {
    http_response_code(400);
    exit("Invalid request.");
}

$pdo = null;

try {
    $pdo = require __DIR__ . "/config/database.php";
    $adminId = (int) $_SESSION["user_id"];

    // Verify administrator permission using MySQL.
    $adminStmt = $pdo->prepare(
        "SELECT role, account_status
         FROM users
         WHERE user_id = ?
         LIMIT 1"
    );

    $adminStmt->execute([$adminId]);
    $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);

    if (
        !$admin ||
        $admin["role"] !== "admin" ||
        $admin["account_status"] !== "active"
    ) {
        http_response_code(403);
        exit("Administrator access required.");
    }

    // Perform the update and audit log atomically.
    $pdo->beginTransaction();

    $userStmt = $pdo->prepare(
        "SELECT role, account_status
         FROM users
         WHERE user_id = ?
         FOR UPDATE"
    );

    $userStmt->execute([$targetId]);
    $target = $userStmt->fetch(PDO::FETCH_ASSOC);

    // Protect administrator accounts.
    if (
        !$target ||
        $target["role"] !== "user" ||
        $targetId === $adminId
    ) {
        $pdo->rollBack();

        http_response_code(400);
        exit("Only regular user accounts may be changed.");
    }

    $newStatus = $action === "suspend"
        ? "suspended"
        : "active";

    // No database update if status is already correct.
    if ($target["account_status"] === $newStatus) {
        $pdo->rollBack();

        header("Location: admin.php?result=unchanged", true, 303);
        exit;
    }

    $updateStmt = $pdo->prepare(
        "UPDATE users
         SET account_status = ?
         WHERE user_id = ?"
    );

    $updateStmt->execute([
        $newStatus,
        $targetId
    ]);

    // Record the administrative action.
    $logStmt = $pdo->prepare(
        "INSERT INTO admin_audit_logs
         (admin_user_id, target_user_id, action)
         VALUES (?, ?, ?)"
    );

    $logStmt->execute([
        $adminId,
        $targetId,
        $action
    ]);

    $pdo->commit();

    header("Location: admin.php?result=updated", true, 303);
    exit;

} catch (Throwable $e) {

    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("PFT admin action failed: " . $e->getMessage());

    http_response_code(500);
    exit("Unable to update account.");
}
