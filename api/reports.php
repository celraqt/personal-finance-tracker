<?php
session_start();

require_once __DIR__ . "/../config/finance-access.php";
pftRequireFinanceUser(true);

header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

date_default_timezone_set("Asia/Manila");

function respond($data, $status = 200) {
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($data);
    exit;
}

if (empty($_SESSION["user_id"])) {
    respond(["error" => "Please log in."], 401);
}

$userId = (int) $_SESSION["user_id"];

session_write_close();

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");
    respond(["error" => "Method not allowed."], 405);
}

// =====================================
// VALIDATE DATE RANGE
// =====================================

function parseReportDate($value) {
    if (
        !is_string($value) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
    ) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat(
        "!Y-m-d",
        $value
    );

    if (!$date || $date->format("Y-m-d") !== $value) {
        return null;
    }

    $year = (int) $date->format("Y");

    if ($year < 1900 || $year > 2100) {
        return null;
    }

    return $date;
}

$startInput = $_GET["start"] ?? date("Y-m-01");
$endInput = $_GET["end"] ?? date("Y-m-d");

$startDate = parseReportDate($startInput);
$endDate = parseReportDate($endInput);

if (!$startDate || !$endDate) {
    respond([
        "error" => "Please select valid report dates."
    ], 422);
}

if ($startDate > $endDate) {
    respond([
        "error" => "Start date cannot be after end date."
    ], 422);
}

$numberOfDays = (int) $startDate
    ->diff($endDate)
    ->format("%a") + 1;

if ($numberOfDays > 731) {
    respond([
        "error" => "Select a period of two years or less."
    ], 422);
}

$start = $startDate->format("Y-m-d");

// Exclusive upper bound includes the whole end date
$endExclusive = $endDate
    ->modify("+1 day")
    ->format("Y-m-d");

$format = $_GET["format"] ?? "json";

if (!is_string($format) ||
    !in_array($format, ["json", "csv"], true)) {
    respond(["error" => "Invalid report format."], 422);
}

try {
    $pdo = require __DIR__ . "/../config/database.php";

    // =====================================
    // CSV EXPORT
    // =====================================

    if ($format === "csv") {

        $stmt = $pdo->prepare(
            "SELECT
                t.transaction_date,
                t.type,
                c.name AS category,
                t.description,
                t.amount

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
            $start,
            $endExclusive
        ]);

        $filename =
            "PFT_Report_" .
            $startInput . "_to_" . $endInput . ".csv";

        header("Content-Type: text/csv; charset=utf-8");

        header(
            'Content-Disposition: attachment; filename="' .
            $filename . '"'
        );

        $output = fopen("php://output", "w");

        // UTF-8 BOM helps Excel read Unicode names
        fwrite($output, "\xEF\xBB\xBF");

        fputcsv(
            $output,
            ["Date", "Type", "Category", "Description", "Amount (PHP)"],
            ",",
            '"',
            ""
        );

        // Prevent spreadsheet formula injection
        function safeCsvText($value) {
            $text = (string) ($value ?? "");

            if (preg_match(
                '/^[\x00-\x20]*[=+\-@]/',
                $text
            ) === 1) {
                return "'" . $text;
            }

            return $text;
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

            fputcsv(
                $output,
                [
                    $row["transaction_date"],
                    $row["type"],
                    safeCsvText($row["category"]),
                    safeCsvText($row["description"]),
                    $row["amount"]
                ],
                ",",
                '"',
                ""
            );
        }

        fclose($output);
        exit;
    }

    // =====================================
    // 1. FINANCIAL SUMMARY
    // =====================================

    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(
                CASE WHEN type = 'income'
                THEN amount ELSE 0 END
            ), 0) AS income,

            COALESCE(SUM(
                CASE WHEN type = 'expense'
                THEN amount ELSE 0 END
            ), 0) AS expenses,

            COUNT(*) AS transaction_count,

            COUNT(
                CASE WHEN type = 'expense'
                THEN 1 END
            ) AS expense_count

         FROM transactions

         WHERE user_id = ?
           AND transaction_date >= ?
           AND transaction_date < ?"
    );

    $stmt->execute([
        $userId,
        $start,
        $endExclusive
    ]);

    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    $income = (float) $summary["income"];
    $expenses = (float) $summary["expenses"];
    $net = $income - $expenses;

    // Null means the rate cannot be computed
    // because there is no income in the period.
    $savingsRate = $income > 0
        ? ($net / $income) * 100
        : null;

    // =====================================
    // 2. EXPENSES BY CATEGORY
    // =====================================

    $stmt = $pdo->prepare(
        "SELECT
            c.name AS category,
            SUM(t.amount) AS total,
            COUNT(*) AS transaction_count

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

         ORDER BY total DESC, c.name ASC"
    );

    $stmt->execute([
        $userId,
        $start,
        $endExclusive
    ]);

    $categories = [];

    foreach (
        $stmt->fetchAll(PDO::FETCH_ASSOC) as $row
    ) {
        $categories[] = [
            "category" => $row["category"],
            "total" => (float) $row["total"],
            "count" => (int) $row["transaction_count"]
        ];
    }

    // =====================================
    // 3. LARGEST SINGLE EXPENSE
    // =====================================

    $stmt = $pdo->prepare(
        "SELECT
            t.amount,
            t.description,
            t.transaction_date,
            c.name AS category

         FROM transactions t

         INNER JOIN categories c
            ON c.category_id = t.category_id
           AND c.user_id = t.user_id
           AND c.type = t.type

         WHERE t.user_id = ?
           AND t.type = 'expense'
           AND t.transaction_date >= ?
           AND t.transaction_date < ?

         ORDER BY
            t.amount DESC,
            t.transaction_id DESC

         LIMIT 1"
    );

    $stmt->execute([
        $userId,
        $start,
        $endExclusive
    ]);

    $largestRow = $stmt->fetch(PDO::FETCH_ASSOC);

    $largestExpense = $largestRow
        ? [
            "amount" => (float) $largestRow["amount"],
            "category" => $largestRow["category"],
            "description" => $largestRow["description"],
            "date" => $largestRow["transaction_date"]
        ]
        : null;

    // =====================================
    // 4. CASH FLOW TREND
    // =====================================

    // Short ranges: daily comparison.
    // Longer ranges: monthly comparison.
    $daily = $numberOfDays <= 45;

    $dateExpression = $daily
        ? "DATE_FORMAT(transaction_date, '%Y-%m-%d')"
        : "DATE_FORMAT(transaction_date, '%Y-%m')";

    // $dateExpression is one of two fixed
    // server-defined SQL expressions above.
    $stmt = $pdo->prepare(
        "SELECT
            $dateExpression AS period,

            COALESCE(SUM(
                CASE WHEN type = 'income'
                THEN amount ELSE 0 END
            ), 0) AS income,

            COALESCE(SUM(
                CASE WHEN type = 'expense'
                THEN amount ELSE 0 END
            ), 0) AS expenses

         FROM transactions

         WHERE user_id = ?
           AND transaction_date >= ?
           AND transaction_date < ?

         GROUP BY period
         ORDER BY period"
    );

    $stmt->execute([
        $userId,
        $start,
        $endExclusive
    ]);

    $trendRows = [];

    foreach (
        $stmt->fetchAll(PDO::FETCH_ASSOC) as $row
    ) {
        $trendRows[$row["period"]] = $row;
    }

    $cursor = $daily
        ? $startDate
        : $startDate->modify("first day of this month");

    $lastPeriod = $daily
        ? $endDate
        : $endDate->modify("first day of this month");

    $trend = [];

    while ($cursor <= $lastPeriod) {

        $key = $cursor->format(
            $daily ? "Y-m-d" : "Y-m"
        );

        $row = $trendRows[$key] ?? null;

        $trend[] = [
            "period" => $key,
            "label" => $cursor->format(
                $daily ? "M j" : "M Y"
            ),
            "income" => $row
                ? (float) $row["income"]
                : 0,
            "expenses" => $row
                ? (float) $row["expenses"]
                : 0
        ];

        $cursor = $cursor->modify(
            $daily ? "+1 day" : "+1 month"
        );
    }

    // =====================================
    // 5. LATEST 10 TRANSACTIONS
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

         LIMIT 10"
    );

    $stmt->execute([
        $userId,
        $start,
        $endExclusive
    ]);

    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // =====================================
    // 6. JSON RESPONSE
    // =====================================

    respond([
        "start" => $startInput,
        "end" => $endInput,
        "days" => $numberOfDays,

        "summary" => [
            "income" => $income,
            "expenses" => $expenses,
            "net" => $net,
            "savings_rate" => $savingsRate,
            "transaction_count" =>
                (int) $summary["transaction_count"],
            "expense_count" =>
                (int) $summary["expense_count"]
        ],

        "insights" => [
            "top_category" =>
                $categories[0] ?? null,

            "daily_average" =>
                $expenses / $numberOfDays,

            "largest_expense" =>
                $largestExpense
        ],

        "categories" => $categories,

        "trend_type" => $daily
            ? "daily"
            : "monthly",

        "trend" => $trend,
        "recent" => $recent
    ]);

} catch (PDOException $e) {

    error_log(
        "Reports API error: " . $e->getMessage()
    );

    respond([
        "error" => "Unable to retrieve report data."
    ], 500);
}
