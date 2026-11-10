<?php
session_start();

require_once __DIR__ . "/../config/finance-access.php";
pftRequireFinanceUser(true);

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

// Allow GET requests only
if ($_SERVER["REQUEST_METHOD"] !== "GET") {

    http_response_code(405);
    header("Allow: GET");

    echo json_encode([
        "error" => "Method not allowed."
    ]);

    exit;
}

// Require authentication
if (empty($_SESSION["user_id"])) {

    http_response_code(401);

    echo json_encode([
        "error" => "Please log in."
    ]);

    exit;
}

$userId = (int) $_SESSION["user_id"];

session_write_close();

date_default_timezone_set("Asia/Manila");

// Validate selected month
$month = $_GET["month"] ?? date("Y-m");

if (
    !is_string($month) ||
    !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ||
    (int) substr($month, 0, 4) < 1000
) {

    http_response_code(422);

    echo json_encode([
        "error" => "Invalid month."
    ]);

    exit;
}

$monthStart = new DateTimeImmutable($month . "-01");

$nextMonth = $monthStart->modify("+1 month");

$startDate = $monthStart->format("Y-m-d");
$endDate = $nextMonth->format("Y-m-d");

try {

    $pdo = require __DIR__ . "/../config/database.php";


    // =====================================
    // 1. ALL-TIME BALANCE
    // =====================================

    $stmt = $pdo->prepare(
        "SELECT COALESCE(
            SUM(
                CASE
                    WHEN type = 'income' THEN amount
                    ELSE -amount
                END
            ), 0
        ) AS balance
        FROM transactions
        WHERE user_id = ?"
    );

    $stmt->execute([$userId]);

    $balance = (float) $stmt->fetchColumn();


    // =====================================
    // 2. MONTHLY SUMMARY
    // =====================================

    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(
                CASE
                    WHEN type = 'income'
                    THEN amount ELSE 0
                END
            ), 0) AS income,

            COALESCE(SUM(
                CASE
                    WHEN type = 'expense'
                    THEN amount ELSE 0
                END
            ), 0) AS expenses

        FROM transactions

        WHERE user_id = ?
          AND transaction_date >= ?
          AND transaction_date < ?"
    );

    $stmt->execute([
        $userId,
        $startDate,
        $endDate
    ]);

    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    $income = (float) $summary["income"];
    $expenses = (float) $summary["expenses"];


    // =====================================
    // 3. SPENDING BY CATEGORY
    // =====================================

    $stmt = $pdo->prepare(
        "SELECT
            c.name AS category,
            SUM(t.amount) AS total

        FROM transactions t

        INNER JOIN categories c
            ON c.category_id = t.category_id
           AND c.user_id = t.user_id
           AND c.type = t.type

        WHERE t.user_id = ?
          AND t.type = 'expense'
          AND t.transaction_date >= ?
          AND t.transaction_date < ?

        GROUP BY c.category_id, c.name

        ORDER BY total DESC"
    );

    $stmt->execute([
        $userId,
        $startDate,
        $endDate
    ]);

    $categories = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {

        $categories[] = [
            "category" => $row["category"],
            "total" => (float) $row["total"]
        ];
    }


    // =====================================
    // 4. RECENT TRANSACTIONS
    // =====================================

    $stmt = $pdo->prepare(
        "SELECT
            t.transaction_date,
            t.description,
            t.type,
            t.amount,
            c.name AS category

        FROM transactions t

        INNER JOIN categories c
            ON c.category_id = t.category_id
           AND c.user_id = t.user_id
           AND c.type = t.type

        WHERE t.user_id = ?
          AND t.transaction_date >= ?
          AND t.transaction_date < ?

        ORDER BY
            t.transaction_date DESC,
            t.transaction_id DESC

        LIMIT 8"
    );

    $stmt->execute([
        $userId,
        $startDate,
        $endDate
    ]);

    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);


    // =====================================
    // 5. LAST SIX MONTHS CASH FLOW
    // =====================================

    $trendStart = $monthStart->modify("-5 months");

    $stmt = $pdo->prepare(
        "SELECT
            DATE_FORMAT(
                transaction_date, '%Y-%m'
            ) AS month_key,

            SUM(
                CASE
                    WHEN type = 'income'
                    THEN amount ELSE 0
                END
            ) AS income,

            SUM(
                CASE
                    WHEN type = 'expense'
                    THEN amount ELSE 0
                END
            ) AS expenses

        FROM transactions

        WHERE user_id = ?
          AND transaction_date >= ?
          AND transaction_date < ?

        GROUP BY month_key
        ORDER BY month_key"
    );

    $stmt->execute([
        $userId,
        $trendStart->format("Y-m-d"),
        $endDate
    ]);

    $trendRows = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $trendRows[$row["month_key"]] = $row;
    }

    $trend = [];

    for ($i = 0; $i < 6; $i++) {

        $date = $trendStart->modify("+$i months");

        $key = $date->format("Y-m");

        $row = $trendRows[$key] ?? null;

        $trend[] = [
            "month" => $key,
            "label" => $date->format("M Y"),
            "income" => $row
                ? (float) $row["income"]
                : 0,
            "expenses" => $row
                ? (float) $row["expenses"]
                : 0
        ];
    }


    // =====================================
    // 6. SEND JSON RESPONSE
    // =====================================

    echo json_encode([
        "month" => $month,

        "summary" => [
            "balance" => $balance,
            "income" => $income,
            "expenses" => $expenses,
            "net" => $income - $expenses
        ],

        "categories" => $categories,
        "recent" => $recent,
        "trend" => $trend
    ]);

} catch (PDOException $e) {

    error_log(
        "Dashboard API error: " . $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "error" => "Unable to retrieve dashboard data."
    ]);
}
