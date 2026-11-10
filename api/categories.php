<?php
session_start();

// Only regular users can access this API
require_once __DIR__ . "/../config/finance-access.php";
pftRequireFinanceUser(true);

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Require authentication
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

    // =====================================
    // GET ALL USER CATEGORIES
    // =====================================

    if ($method === "GET") {

        $stmt = $pdo->prepare(
            "SELECT
                c.category_id,
                c.name,
                c.type,
                c.is_default,
                COUNT(t.transaction_id) AS transaction_count,

                (
                    SELECT COUNT(*)
                    FROM budgets b
                    WHERE b.category_id = c.category_id
                    AND b.user_id = c.user_id
                ) AS budget_count

            FROM categories c

            LEFT JOIN transactions t
                ON t.category_id = c.category_id
               AND t.user_id = c.user_id
               AND t.type = c.type

            WHERE c.user_id = ?

            GROUP BY
                c.category_id,
                c.name,
                c.type,
                c.is_default

            ORDER BY
                c.type ASC,
                c.is_default DESC,
                c.name ASC"
        );

        $stmt->execute([$userId]);

        respond([
            "categories" => $stmt->fetchAll(
                PDO::FETCH_ASSOC
            )
        ]);
    }

    // =====================================
    // VERIFY CSRF TOKEN
    // =====================================

    $submittedToken =
        $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "";

    if (
        !is_string($submittedToken) ||
        $csrfToken === "" ||
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

    // =====================================
    // FIND CATEGORY FOR EDIT OR DELETE
    // =====================================

    $category = null;
    $categoryId = null;

    if ($action !== "create") {

        $categoryId = filter_var(
            $input["category_id"] ?? null,
            FILTER_VALIDATE_INT,
            ["options" => ["min_range" => 1]]
        );

        if ($categoryId === false) {
            respond(["error" => "Invalid category."], 422);
        }

        $stmt = $pdo->prepare(
            "SELECT
                category_id,
                name,
                type,
                is_default

             FROM categories

             WHERE category_id = ?
               AND user_id = ?

             LIMIT 1"
        );

        $stmt->execute([$categoryId, $userId]);

        $category = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$category) {
            respond(["error" => "Category not found."], 404);
        }

        // Keep built-in categories protected
        if ((int) $category["is_default"] === 1) {
            respond([
                "error" => "Built-in categories cannot be edited or deleted."
            ], 403);
        }
    }


    // =====================================
    // DELETE CUSTOM CATEGORY
    // =====================================

    if ($action === "delete") {

        // 1. Check if category has transactions
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM transactions
             WHERE user_id = ?
               AND category_id = ?"
        );

        $stmt->execute([$userId, $categoryId]);

        $usageCount = (int) $stmt->fetchColumn();

        if ($usageCount > 0) {
            respond([
                "error" => "This category is used by " .
                           $usageCount .
                           " transaction(s) and cannot be deleted."
            ], 409);
        }

        // 2. Check if category has monthly budgets
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM budgets
             WHERE user_id = ?
               AND category_id = ?"
        );

        $stmt->execute([$userId, $categoryId]);

        $budgetCount = (int) $stmt->fetchColumn();

        if ($budgetCount > 0) {
            respond([
                "error" => "This category has monthly budgets. " .
                           "Delete those budgets first."
            ], 409);
        }

        // 3. Delete category if it is not in use
        $stmt = $pdo->prepare(
            "DELETE FROM categories
             WHERE category_id = ?
               AND user_id = ?
               AND is_default = 0"
        );

        $stmt->execute([$categoryId, $userId]);

        if ($stmt->rowCount() === 0) {
            respond([
                "error" => "Category not found."
            ], 404);
        }

        respond([
            "success" => true,
            "message" => "Category deleted successfully."
        ]);
    }

    // =====================================
    // VALIDATE NAME
    // =====================================

    $rawName = $input["name"] ?? null;

    if (!is_string($rawName)) {
        respond(["error" => "Enter a category name."], 422);
    }

    // Normalize spaces
    $name = preg_replace('/\s+/u', ' ', $rawName);
    $name = trim($name ?? "");

    $validName =
        preg_match('/^.{2,50}$/us', $name) === 1
        &&
        preg_match(
            '/^[\p{L}\p{M}\p{N} .&\'()\/-]+$/u',
            $name
        ) === 1
        &&
        preg_match('/\p{L}/u', $name) === 1;

    if (!$validName) {
        respond([
            "error" => "Use 2–50 characters. Category names may contain letters, numbers, spaces, periods, apostrophes, hyphens, ampersands, slashes, and parentheses."
        ], 422);
    }

    // =====================================
    // DETERMINE CATEGORY TYPE
    // =====================================

    if ($action === "create") {

        $type = $input["type"] ?? "";

        if (!in_array(
            $type,
            ["income", "expense"],
            true
        )) {
            respond([
                "error" => "Choose Income or Expense."
            ], 422);
        }

    } else {

        // Renaming does not change category type
        $type = $category["type"];
    }

    // =====================================
    // CHECK FOR DUPLICATE CATEGORY NAME
    // =====================================

    if ($action === "create") {

        $stmt = $pdo->prepare(
            "SELECT 1
             FROM categories
             WHERE user_id = ?
               AND type = ?
               AND LOWER(name) = LOWER(?)
             LIMIT 1"
        );

        $stmt->execute([
            $userId,
            $type,
            $name
        ]);

    } else {

        $stmt = $pdo->prepare(
            "SELECT 1
             FROM categories
             WHERE user_id = ?
               AND type = ?
               AND LOWER(name) = LOWER(?)
               AND category_id <> ?
             LIMIT 1"
        );

        $stmt->execute([
            $userId,
            $type,
            $name,
            $categoryId
        ]);
    }

    if ($stmt->fetchColumn()) {
        respond([
            "error" => "A category with this name already exists."
        ], 409);
    }

    // =====================================
    // CREATE CUSTOM CATEGORY
    // =====================================

    if ($action === "create") {

        $stmt = $pdo->prepare(
            "INSERT INTO categories
                (user_id, name, type, is_default)
             VALUES (?, ?, ?, 0)"
        );

        $stmt->execute([
            $userId,
            $name,
            $type
        ]);

        respond([
            "success" => true,
            "message" => "Category created successfully."
        ], 201);
    }

    // =====================================
    // UPDATE CUSTOM CATEGORY NAME
    // =====================================

    $stmt = $pdo->prepare(
        "UPDATE categories
         SET name = ?
         WHERE category_id = ?
           AND user_id = ?
           AND is_default = 0"
    );

    $stmt->execute([
        $name,
        $categoryId,
        $userId
    ]);

    respond([
        "success" => true,
        "message" => "Category updated successfully."
    ]);

} catch (PDOException $e) {

    // Foreign-key protection if a transaction
    // is inserted while deletion is taking place
    if ($e->getCode() === "23000") {

        respond([
            "error" => "This category is in use or conflicts with an existing record."
        ], 409);
    }

    error_log(
        "Category API error: " . $e->getMessage()
    );

    respond([
        "error" => "Unable to process category request."
    ], 500);
}
