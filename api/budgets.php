<?php
session_start();

require_once __DIR__ . "/../config/finance-access.php";
pftRequireFinanceUser(true);

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

date_default_timezone_set("Asia/Manila");

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function validMonth($value) {
    return is_string($value)
        && preg_match(
            '/^20\d{2}-(0[1-9]|1[0-2])$/',
            $value
        ) === 1;
}

function positiveId($value) {
    $result = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1]]
    );

    return $result === false ? null : $result;
}

if (empty($_SESSION["user_id"])) {
    respond(["error" => "Please log in."], 401);
}

$userId = (int) $_SESSION["user_id"];
$csrfToken = $_SESSION["csrf_token"] ?? "";

session_write_close();

$method = $_SERVER["REQUEST_METHOD"];

if (!in_array($method, ["GET", "POST"], true)) {
    header("Allow: GET, POST");
    respond(["error" => "Method not allowed."], 405);
}

try {
    $pdo = require __DIR__ . "/../config/database.php";

    // ==================================
    // GET BUDGETS AND ACTIVE ALERTS
    // ==================================

    if ($method === "GET") {

        $month = $_GET["month"] ?? date("Y-m");

        if (!validMonth($month)) {
            respond(["error" => "Invalid month."], 422);
        }

        $startDate = $month . "-01";

        $nextDate = (new DateTimeImmutable($startDate))
            ->modify("+1 month")
            ->format("Y-m-d");

        // Only expense categories belonging to user
        $stmt = $pdo->prepare(
            "SELECT category_id, name
             FROM categories
             WHERE user_id = ?
               AND type = 'expense'
             ORDER BY name ASC"
        );

        $stmt->execute([$userId]);

        $categories = $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

        // Spending is calculated from existing
        // transactions for this exact month.
        $stmt = $pdo->prepare(
            "SELECT
                b.budget_id,
                b.category_id,
                b.amount,
                c.name AS category_name,

                COALESCE(s.spent, 0) AS spent

             FROM budgets b

             INNER JOIN categories c
                ON c.category_id = b.category_id
               AND c.user_id = b.user_id
               AND c.type = 'expense'

             LEFT JOIN (
                SELECT
                    category_id,
                    user_id,
                    SUM(amount) AS spent

                FROM transactions

                WHERE user_id = ?
                  AND type = 'expense'
                  AND transaction_date >= ?
                  AND transaction_date < ?

                GROUP BY user_id, category_id
             ) s
                ON s.category_id = b.category_id
               AND s.user_id = b.user_id

             WHERE b.user_id = ?
               AND b.budget_month = ?

             ORDER BY c.name ASC"
        );

        $stmt->execute([
            $userId,
            $startDate,
            $nextDate,
            $userId,
            $startDate
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $budgets = [];
        $alerts = [];

        $totalLimit = 0.0;
        $totalSpent = 0.0;

        foreach ($rows as $row) {

            $limit = (float) $row["amount"];
            $spent = (float) $row["spent"];

            $remaining = round($limit - $spent, 2);
            $percentage = $limit > 0
                ? round(($spent / $limit) * 100, 1)
                : 0;

            $totalLimit += $limit;
            $totalSpent += $spent;

            $status = "safe";

            if ($spent >= $limit) {
                $status = "danger";
            } elseif ($spent >= $limit * 0.8) {
                $status = "warning";
            }

            $budgets[] = [
                "budget_id" => (int) $row["budget_id"],
                "category_id" => (int) $row["category_id"],
                "category_name" => $row["category_name"],
                "amount" => $limit,
                "spent" => $spent,
                "remaining" => $remaining,
                "percentage" => $percentage,
                "status" => $status
            ];

            // Active in-system notifications
            if ($status !== "safe") {

                if ($spent > $limit) {
                    $message = "You have exceeded your " .
                        $row["category_name"] .
                        " budget.";
                } elseif ($spent >= $limit) {
                    $message = "You have reached your " .
                        $row["category_name"] .
                        " budget limit.";
                } else {
                    $message = "You have used " .
                        number_format($percentage, 1) .
                        "% of your " .
                        $row["category_name"] .
                        " budget.";
                }

                $alerts[] = [
                    "budget_id" => (int) $row["budget_id"],
                    "category" => $row["category_name"],
                    "message" => $message,
                    "status" => $status,
                    "percentage" => $percentage
                ];
            }
        }

        // Show all monthly expenses, including
        // categories without a budget.
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM transactions
             WHERE user_id = ?
               AND type = 'expense'
               AND transaction_date >= ?
               AND transaction_date < ?"
        );

        $stmt->execute([
            $userId,
            $startDate,
            $nextDate
        ]);

        $monthlyExpenses = (float) $stmt->fetchColumn();

        respond([
            "month" => $month,
            "categories" => $categories,
            "budgets" => $budgets,
            "alerts" => $alerts,

            "summary" => [
                "total_limit" => round($totalLimit, 2),
                "total_spent" => round($totalSpent, 2),
                "remaining" => round(
                    $totalLimit - $totalSpent,
                    2
                ),
                "monthly_expenses" => $monthlyExpenses,
                "unbudgeted_spending" => round(
                    max(
                        0,
                        $monthlyExpenses - $totalSpent
                    ),
                    2
                ),
                "alert_count" => count($alerts)
            ]
        ]);
    }

    // ==================================
    // VERIFY CSRF FOR WRITES
    // ==================================

    $submittedToken =
        $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "";

    if (
        $csrfToken === "" ||
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        respond([
            "error" => "Invalid request. Refresh and try again."
        ], 403);
    }

    $input = json_decode(
        file_get_contents("php://input"),
        true
    );

    if (!is_array($input)) {
        respond(["error" => "Invalid request data."], 400);
    }

    $action = $input["action"] ?? "";

    if (!in_array(
        $action,
        ["create", "update", "delete"],
        true
    )) {
        respond(["error" => "Invalid action."], 400);
    }

    // ==================================
    // UPDATE OR DELETE AN EXISTING BUDGET
    // ==================================

    $budgetId = null;

    if ($action !== "create") {

        $budgetId = positiveId(
            $input["budget_id"] ?? null
        );

        if ($budgetId === null) {
            respond(["error" => "Invalid budget ID."], 422);
        }

        $stmt = $pdo->prepare(
            "SELECT budget_id
             FROM budgets
             WHERE budget_id = ?
               AND user_id = ?
             LIMIT 1"
        );

        $stmt->execute([$budgetId, $userId]);

        if (!$stmt->fetchColumn()) {
            respond(["error" => "Budget not found."], 404);
        }
    }

    if ($action === "delete") {

        $stmt = $pdo->prepare(
            "DELETE FROM budgets
             WHERE budget_id = ?
               AND user_id = ?"
        );

        $stmt->execute([$budgetId, $userId]);

        respond([
            "success" => true,
            "message" => "Budget deleted successfully."
        ]);
    }

    // ==================================
    // VALIDATE BUDGET LIMIT
    // ==================================

    $rawAmount = $input["amount"] ?? null;

    if (
        !is_string($rawAmount) ||
        !preg_match(
            '/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/',
            $rawAmount
        ) ||
        (float) $rawAmount <= 0
    ) {
        respond([
            "error" => "Enter a valid budget amount greater than zero."
        ], 422);
    }

    $parts = explode(".", $rawAmount, 2);

    $amount = $parts[0] . "." .
        str_pad($parts[1] ?? "", 2, "0");

    // ==================================
    // CREATE BUDGET
    // ==================================

    if ($action === "create") {

        $month = $input["month"] ?? null;

        if (!validMonth($month)) {
            respond(["error" => "Invalid budget month."], 422);
        }

        $categoryId = positiveId(
            $input["category_id"] ?? null
        );

        if ($categoryId === null) {
            respond(["error" => "Select a category."], 422);
        }

        // Category must belong to user and be an expense
        $stmt = $pdo->prepare(
            "SELECT category_id
             FROM categories
             WHERE category_id = ?
               AND user_id = ?
               AND type = 'expense'
             LIMIT 1"
        );

        $stmt->execute([
            $categoryId,
            $userId
        ]);

        if (!$stmt->fetchColumn()) {
            respond([
                "error" => "Invalid expense category."
            ], 422);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO budgets
                (user_id, category_id, budget_month, amount)
             VALUES (?, ?, ?, ?)"
        );

        $stmt->execute([
            $userId,
            $categoryId,
            $month . "-01",
            $amount
        ]);

        respond([
            "success" => true,
            "message" => "Monthly budget created."
        ], 201);
    }

    // ==================================
    // UPDATE BUDGET LIMIT
    // ==================================

    $stmt = $pdo->prepare(
        "UPDATE budgets
         SET amount = ?
         WHERE budget_id = ?
           AND user_id = ?"
    );

    $stmt->execute([
        $amount,
        $budgetId,
        $userId
    ]);

    respond([
        "success" => true,
        "message" => "Budget updated successfully."
    ]);

} catch (PDOException $e) {

    if ($e->getCode() === "23000") {
        respond([
            "error" => "This category already has a budget for that month, or the category is not available."
        ], 409);
    }

    error_log(
        "Budgets API error: " . $e->getMessage()
    );

    respond([
        "error" => "Unable to process budget request."
    ], 500);
}
