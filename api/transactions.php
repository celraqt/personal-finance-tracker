
<?php
session_start();

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

date_default_timezone_set("Asia/Manila");

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
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
    // GET TRANSACTIONS AND CATEGORIES
    // ==================================

    if ($method === "GET") {

        $month = $_GET["month"] ?? date("Y-m");

        if (
            !is_string($month) ||
            !preg_match(
                '/^\d{4}-(0[1-9]|1[0-2])$/',
                $month
            ) ||
            (int) substr($month, 0, 4) < 1000
        ) {
            respond(["error" => "Invalid month."], 422);
        }

        $monthStart = new DateTimeImmutable(
            $month . "-01"
        );

        $nextMonth = $monthStart->modify("+1 month");

        // Only this user's categories
        $stmt = $pdo->prepare(
            "SELECT category_id, name, type
             FROM categories
             WHERE user_id = ?
             ORDER BY
                CASE WHEN type = 'expense'
                     THEN 0 ELSE 1 END,
                name ASC"
        );

        $stmt->execute([$userId]);

        $categories = $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

        // Only this user's transactions
        $stmt = $pdo->prepare(
            "SELECT
                t.transaction_id,
                t.category_id,
                t.type,
                t.amount,
                t.transaction_date,
                t.description,
                c.name AS category_name

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
                t.transaction_id DESC"
        );

        $stmt->execute([
            $userId,
            $monthStart->format("Y-m-d"),
            $nextMonth->format("Y-m-d")
        ]);

        $transactions = $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

        respond([
            "month" => $month,
            "categories" => $categories,
            "transactions" => $transactions
        ]);
    }

    // ==================================
    // POST: CSRF PROTECTION
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

    // ==================================
    // DELETE TRANSACTION
    // ==================================

    if ($action === "delete") {

        $transactionId = filter_var(
            $input["transaction_id"] ?? null,
            FILTER_VALIDATE_INT,
            ["options" => ["min_range" => 1]]
        );

        if ($transactionId === false) {
            respond(["error" => "Invalid transaction."], 422);
        }

        $stmt = $pdo->prepare(
            "DELETE FROM transactions
             WHERE transaction_id = ?
               AND user_id = ?"
        );

        $stmt->execute([
            $transactionId,
            $userId
        ]);

        if ($stmt->rowCount() === 0) {
            respond([
                "error" => "Transaction not found."
            ], 404);
        }

        respond([
            "success" => true,
            "message" => "Transaction deleted."
        ]);
    }

    // ==================================
    // CREATE OR UPDATE TRANSACTION
    // ==================================

    if (!in_array(
        $action,
        ["create", "update"],
        true
    )) {
        respond(["error" => "Invalid action."], 400);
    }

    $type = $input["type"] ?? "";
    $amountRaw = $input["amount"] ?? "";
    $dateInput = $input["transaction_date"] ?? "";
    $description = $input["description"] ?? "";

    $categoryId = filter_var(
        $input["category_id"] ?? null,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1]]
    );

    if (!in_array($type, ["income", "expense"], true)) {
        respond(["error" => "Invalid transaction type."], 422);
    }

    // DECIMAL(12,2): max 10 digits before decimal
    if (
        !is_string($amountRaw) ||
        !preg_match(
            '/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/',
            $amountRaw
        ) ||
        (float) $amountRaw <= 0
    ) {
        respond([
            "error" => "Enter a valid amount greater than zero."
        ], 422);
    }

    // Convert to a two-decimal string
    $amountParts = explode(".", $amountRaw, 2);
    $amount = $amountParts[0] . "." .
        str_pad($amountParts[1] ?? "", 2, "0");

    if (!is_string($dateInput)) {
        respond(["error" => "Invalid date."], 422);
    }

    $date = DateTimeImmutable::createFromFormat(
        "!Y-m-d",
        $dateInput
    );

    if (
        !$date ||
        $date->format("Y-m-d") !== $dateInput ||
        (int) $date->format("Y") < 1000
    ) {
        respond(["error" => "Enter a valid date."], 422);
    }

    if (
        !is_string($description) ||
        preg_match('/^.{0,255}$/us', $description) !== 1
    ) {
        respond([
            "error" => "Description must be 255 characters or fewer."
        ], 422);
    }

    $description = trim($description);

    if ($categoryId === false) {
        respond(["error" => "Select a category."], 422);
    }

    // Verify that this category belongs
    // to the logged-in user and matches type
    $stmt = $pdo->prepare(
        "SELECT category_id
         FROM categories
         WHERE category_id = ?
           AND user_id = ?
           AND type = ?
         LIMIT 1"
    );

    $stmt->execute([
        $categoryId,
        $userId,
        $type
    ]);

    if (!$stmt->fetchColumn()) {
        respond([
            "error" => "Invalid category for this transaction."
        ], 422);
    }

    if ($action === "create") {

        $stmt = $pdo->prepare(
            "INSERT INTO transactions
                (
                    user_id,
                    category_id,
                    type,
                    amount,
                    transaction_date,
                    description
                )
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $userId,
            $categoryId,
            $type,
            $amount,
            $dateInput,
            $description
        ]);

        respond([
            "success" => true,
            "message" => "Transaction added successfully."
        ], 201);
    }

    // ==================================
    // UPDATE: CHECK RECORD OWNERSHIP
    // ==================================

    $transactionId = filter_var(
        $input["transaction_id"] ?? null,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1]]
    );

    if ($transactionId === false) {
        respond(["error" => "Invalid transaction."], 422);
    }

    $stmt = $pdo->prepare(
        "SELECT transaction_id
         FROM transactions
         WHERE transaction_id = ?
           AND user_id = ?
         LIMIT 1"
    );

    $stmt->execute([
        $transactionId,
        $userId
    ]);

    if (!$stmt->fetchColumn()) {
        respond([
            "error" => "Transaction not found."
        ], 404);
    }

    $stmt = $pdo->prepare(
        "UPDATE transactions
         SET category_id = ?,
             type = ?,
             amount = ?,
             transaction_date = ?,
             description = ?
         WHERE transaction_id = ?
           AND user_id = ?"
    );

    $stmt->execute([
        $categoryId,
        $type,
        $amount,
        $dateInput,
        $description,
        $transactionId,
        $userId
    ]);

    respond([
        "success" => true,
        "message" => "Transaction updated successfully."
    ]);

} catch (PDOException $e) {

    error_log(
        "Transaction API error: " . $e->getMessage()
    );

    respond([
        "error" => "Database operation failed."
    ], 500);
}
