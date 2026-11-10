<?php

function pftRequireFinanceUser(bool $isApi = false): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $reject = static function (
        int $status,
        string $message
    ) use ($isApi): void {
        http_response_code($status);

        if ($isApi) {
            header("Content-Type: application/json; charset=utf-8");

            echo json_encode([
                "success" => false,
                "message" => $message
            ]);
        } else {
            echo htmlspecialchars(
                $message,
                ENT_QUOTES,
                "UTF-8"
            );
        }

        exit;
    };

    if (empty($_SESSION["user_id"])) {
        if ($isApi) {
            $reject(401, "Authentication required.");
        }

        header("Location: login.php");
        exit;
    }

    try {
        $pdo = require __DIR__ . "/database.php";

        $stmt = $pdo->prepare(
            "SELECT role, account_status
             FROM users
             WHERE user_id = ?
             LIMIT 1"
        );

        $stmt->execute([
            (int) $_SESSION["user_id"]
        ]);

        $account = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {
        error_log("PFT authorization check failed.");
        $reject(503, "Service temporarily unavailable.");
    }

    if (
        !$account ||
        $account["account_status"] !== "active"
    ) {
        $reject(403, "Account access is unavailable.");
    }

    if ($account["role"] === "admin" && !$isApi) {
        header("Location: admin.php");
        exit;
    }

    if ($account["role"] !== "user") {
        $reject(403, "Access denied.");
    }
}
