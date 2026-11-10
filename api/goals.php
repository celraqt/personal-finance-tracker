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

function positiveId($value) {
    $id = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1]]
    );

    return ($id === false || $id === null)
        ? null
        : (int) $id;
}

function validAmount($value) {

    if (
        !is_string($value) ||
        preg_match(
            '/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D',
            $value
        ) !== 1
    ) {
        return null;
    }

    $parts = explode(".", $value, 2);

    $amount = $parts[0] . "." .
        str_pad($parts[1] ?? "", 2, "0");

    return (float) $amount > 0
        ? $amount
        : null;
}

function validDate($value) {

    if (
        !is_string($value) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
    ) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat(
        "!Y-m-d",
        $value
    );

    return $date !== false
        && $date->format("Y-m-d") === $value
        && (int) $date->format("Y") >= 1900
        && (int) $date->format("Y") <= 2100;
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

    // =====================================
    // GET GOALS AND CONTRIBUTIONS
    // =====================================

    if ($method === "GET") {

        $stmt = $pdo->prepare(
            "SELECT
                g.goal_id,
                g.name,
                g.target_amount,
                g.target_date,
                COALESCE(
                    SUM(c.amount), 0
                ) AS saved_amount,
                COUNT(c.contribution_id)
                    AS contribution_count

             FROM savings_goals g

             LEFT JOIN goal_contributions c
                ON c.goal_id = g.goal_id

             WHERE g.user_id = ?

             GROUP BY
                g.goal_id,
                g.name,
                g.target_amount,
                g.target_date

             ORDER BY
                (g.target_date IS NULL) ASC,
                g.target_date ASC,
                g.goal_id DESC"
        );

        $stmt->execute([$userId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $goals = [];
        $totalTarget = 0.0;
        $totalSaved = 0.0;
        $totalRemaining = 0.0;
        $completedCount = 0;

        foreach ($rows as $row) {

            $target = (float) $row["target_amount"];
            $saved = (float) $row["saved_amount"];

            $remaining = max(0, $target - $saved);

            $percentage = $target > 0
                ? round(($saved / $target) * 100, 1)
                : 0;

            $completed = $saved >= $target;

            $overdue =
                !$completed &&
                $row["target_date"] !== null &&
                $row["target_date"] < date("Y-m-d");

            $status = $completed
                ? "completed"
                : ($overdue ? "overdue" : "active");

            $goals[] = [
                "goal_id" => (int) $row["goal_id"],
                "name" => $row["name"],
                "target_amount" => $target,
                "target_date" => $row["target_date"],
                "saved_amount" => $saved,
                "remaining" => round($remaining, 2),
                "percentage" => $percentage,
                "contribution_count" =>
                    (int) $row["contribution_count"],
                "status" => $status
            ];

            $totalTarget += $target;
            $totalSaved += $saved;
            $totalRemaining += $remaining;

            if ($completed) {
                $completedCount++;
            }
        }

        // Contribution history, restricted through
        // ownership of the parent savings goal.
        $stmt = $pdo->prepare(
            "SELECT
                c.contribution_id,
                c.goal_id,
                g.name AS goal_name,
                c.amount,
                c.contribution_date,
                c.note

             FROM goal_contributions c

             INNER JOIN savings_goals g
                ON g.goal_id = c.goal_id

             WHERE g.user_id = ?

             ORDER BY
                c.contribution_date DESC,
                c.contribution_id DESC"
        );

        $stmt->execute([$userId]);

        $contributions = $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

        respond([
            "goals" => $goals,
            "contributions" => $contributions,

            "summary" => [
                "total_target" => round(
                    $totalTarget, 2
                ),
                "total_saved" => round(
                    $totalSaved, 2
                ),
                "remaining" => round(
                    $totalRemaining, 2
                ),
                "completed_count" =>
                    $completedCount
            ]
        ]);
    }

    // =====================================
    // CHECK CSRF TOKEN
    // =====================================

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

    $allowedActions = [
        "create_goal",
        "update_goal",
        "delete_goal",
        "add_contribution",
        "delete_contribution"
    ];

    if (!in_array($action, $allowedActions, true)) {
        respond(["error" => "Invalid action."], 400);
    }

    // =====================================
    // CREATE / UPDATE / DELETE GOAL
    // =====================================

    if (in_array(
        $action,
        ["create_goal", "update_goal", "delete_goal"],
        true
    )) {

        $goalId = null;

        if ($action !== "create_goal") {

            $goalId = positiveId(
                $input["goal_id"] ?? null
            );

            if ($goalId === null) {
                respond(["error" => "Invalid goal."], 422);
            }

            $stmt = $pdo->prepare(
                "SELECT goal_id
                 FROM savings_goals
                 WHERE goal_id = ?
                   AND user_id = ?
                 LIMIT 1"
            );

            $stmt->execute([$goalId, $userId]);

            if (!$stmt->fetchColumn()) {
                respond(["error" => "Goal not found."], 404);
            }
        }

        // DELETE GOAL
        if ($action === "delete_goal") {

            $stmt = $pdo->prepare(
                "DELETE FROM savings_goals
                 WHERE goal_id = ?
                   AND user_id = ?"
            );

            $stmt->execute([$goalId, $userId]);

            respond([
                "success" => true,
                "message" => "Savings goal deleted."
            ]);
        }

        // VALIDATE GOAL DETAILS
        $rawName = $input["name"] ?? null;

        if (!is_string($rawName)) {
            respond(["error" => "Enter a goal name."], 422);
        }

        $name = preg_replace(
            '/\s+/u',
            ' ',
            trim($rawName)
        );

        if (
            !is_string($name) ||
            preg_match('/^.{2,100}$/us', $name) !== 1 ||
            preg_match(
                "/^[\p{L}\p{M}\p{N} .,'&()\/-]+$/u",
                $name
            ) !== 1 ||
            preg_match('/\p{L}/u', $name) !== 1
        ) {
            respond([
                "error" => "Goal name must be 2–100 characters and contain letters or numbers with standard punctuation."
            ], 422);
        }

        $amount = validAmount(
            $input["target_amount"] ?? null
        );

        if ($amount === null) {
            respond([
                "error" => "Enter a valid target amount greater than zero."
            ], 422);
        }

        $targetDate = $input["target_date"] ?? null;

        if ($targetDate === "") {
            $targetDate = null;
        }

        if (
            $targetDate !== null &&
            !validDate($targetDate)
        ) {
            respond([
                "error" => "Enter a valid target date."
            ], 422);
        }

        // CREATE GOAL
        if ($action === "create_goal") {

            $stmt = $pdo->prepare(
                "INSERT INTO savings_goals
                    (
                        user_id,
                        name,
                        target_amount,
                        target_date
                    )
                 VALUES (?, ?, ?, ?)"
            );

            $stmt->execute([
                $userId,
                $name,
                $amount,
                $targetDate
            ]);

            respond([
                "success" => true,
                "message" => "Savings goal created."
            ], 201);
        }

        // UPDATE GOAL
        $stmt = $pdo->prepare(
            "UPDATE savings_goals
             SET name = ?,
                 target_amount = ?,
                 target_date = ?
             WHERE goal_id = ?
               AND user_id = ?"
        );

        $stmt->execute([
            $name,
            $amount,
            $targetDate,
            $goalId,
            $userId
        ]);

        respond([
            "success" => true,
            "message" => "Savings goal updated."
        ]);
    }

    // =====================================
    // ADD CONTRIBUTION
    // =====================================

    if ($action === "add_contribution") {

        $goalId = positiveId(
            $input["goal_id"] ?? null
        );

        $amount = validAmount(
            $input["amount"] ?? null
        );

        $date = $input["contribution_date"] ?? null;
        $note = $input["note"] ?? "";

        if ($goalId === null) {
            respond(["error" => "Select a goal."], 422);
        }

        if ($amount === null) {
            respond([
                "error" => "Enter a valid contribution amount."
            ], 422);
        }

        if (!validDate($date)) {
            respond([
                "error" => "Enter a valid contribution date."
            ], 422);
        }

        if (
            !is_string($note) ||
            preg_match('/^.{0,255}$/us', $note) !== 1
        ) {
            respond([
                "error" => "Note must contain 255 characters or fewer."
            ], 422);
        }

        $note = trim($note);

        // Verify the goal belongs to this user
        $stmt = $pdo->prepare(
            "SELECT goal_id
             FROM savings_goals
             WHERE goal_id = ?
               AND user_id = ?
             LIMIT 1"
        );

        $stmt->execute([$goalId, $userId]);

        if (!$stmt->fetchColumn()) {
            respond(["error" => "Goal not found."], 404);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO goal_contributions
                (
                    goal_id,
                    amount,
                    contribution_date,
                    note
                )
             VALUES (?, ?, ?, ?)"
        );

        $stmt->execute([
            $goalId,
            $amount,
            $date,
            $note !== "" ? $note : null
        ]);

        respond([
            "success" => true,
            "message" => "Savings contribution recorded."
        ], 201);
    }

    // =====================================
    // DELETE CONTRIBUTION
    // =====================================

    $contributionId = positiveId(
        $input["contribution_id"] ?? null
    );

    if ($contributionId === null) {
        respond([
            "error" => "Invalid contribution."
        ], 422);
    }

    // Joined DELETE ensures the contribution
    // belongs to a goal owned by this user.
    $stmt = $pdo->prepare(
        "DELETE c
         FROM goal_contributions c
         INNER JOIN savings_goals g
            ON g.goal_id = c.goal_id
         WHERE c.contribution_id = ?
           AND g.user_id = ?"
    );

    $stmt->execute([
        $contributionId,
        $userId
    ]);

    if ($stmt->rowCount() === 0) {
        respond([
            "error" => "Contribution not found."
        ], 404);
    }

    respond([
        "success" => true,
        "message" => "Contribution deleted."
    ]);

} catch (PDOException $e) {

    error_log(
        "Savings Goals API error: " .
        $e->getMessage()
    );

    respond([
        "error" => "Unable to process savings request."
    ], 500);
}
