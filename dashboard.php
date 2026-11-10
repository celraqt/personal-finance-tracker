<?php
session_start();

// Protect the dashboard
if (empty($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

// Check the logged-in user's role
try {
    $pdo = require __DIR__ . "/config/database.php";

    $roleStmt = $pdo->prepare(
        "SELECT role, account_status FROM users WHERE user_id = ? LIMIT 1"
    );

    $roleStmt->execute([(int) $_SESSION["user_id"]]);

    
    $account = $roleStmt->fetch(PDO::FETCH_ASSOC);

    if (
        !$account ||
        $account["account_status"] !== "active"
    ) {
        http_response_code(403);
        exit("Account access is unavailable.");
    }

    $role = $account["role"];


    // Administrators use the Admin Dashboard
    if ($role === "admin") {
        header("Location: admin.php");
        exit;
    }

    // Only regular users can access this dashboard
    if ($role !== "user") {
        http_response_code(403);
        exit("Access denied.");
    }

} catch (Throwable $e) {
    error_log("PFT dashboard authorization failed.");
    http_response_code(500);
    exit("Unable to load dashboard.");
}



/* Check if the logged-in account is an administrator */
$isAdmin = false;

try {
    $pdo = require __DIR__ . "/config/database.php";

    $stmt = $pdo->prepare(
        "SELECT role FROM users WHERE user_id = ? LIMIT 1"
    );

    $stmt->execute([(int) $_SESSION["user_id"]]);

    $isAdmin = ($stmt->fetchColumn() === "admin");

} catch (Throwable $e) {
    error_log("PFT admin role check failed.");
}

// Logout CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

date_default_timezone_set("Asia/Manila");

function escape($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

$fullName = $_SESSION["full_name"] ?? "User";
$firstName = explode(" ", trim($fullName))[0];

$currentMonth = date("Y-m");
?>

<!DOCTYPE html>
<html lang="en" data-theme="dark">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <meta name="theme-color" content="#111514">

    <title>Dashboard | Personal Finance Tracker</title>

    <link rel="stylesheet"
          href="assets/css/dashboard.css">

    <!-- Chart.js -->
    <script
        src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"
        defer>
    </script>

    <script src="assets/js/dashboard.js" defer></script>

    <script
        src="assets/js/dashboard-budget-alerts.js?v=1"
        defer>
    </script>

</head>

<body>

<div class="app-layout">

    <!-- ==============================
         SIDEBAR
         ============================== -->

    <aside class="sidebar">

        <a href="dashboard.php"
           class="sidebar-logo"
           aria-label="Personal Finance Tracker home">

            <img
                src="assets/images/pftlogo.png"
                alt="Personal Finance Tracker"
            >

        </a>

        <div class="sidebar-label">
            WORKSPACE / 01
        </div>

        
        <nav class="sidebar-nav" aria-label="Main navigation">

            <a href="dashboard.php"
            class="nav-item active"
            aria-current="page">
                <span class="nav-icon">▦</span>
                Dashboard
            </a>

            <a href="transactions.php" class="nav-item">
                <span class="nav-icon">⇄</span>
                Transactions
            </a>

            <a href="categories.php" class="nav-item">
                <span class="nav-icon">◫</span>
                Categories
            </a>

            <a href="reports.php" class="nav-item">
                <span class="nav-icon">▥</span>
                Reports
            </a>

            <a href="budgets.php" class="nav-item">
                <span class="nav-icon">◎</span>
                Budgets
            </a>

            <a href="goals.php" class="nav-item">
                <span class="nav-icon">◈</span>
                Savings Goals
            </a>

        </nav>
            

            <?php if ($isAdmin): ?>
                <a href="admin.php" class="nav-item">
                    <span class="nav-icon">✦</span>
                    Admin Dashboard
                </a>
            <?php endif; ?>


        <?php if ($isAdmin): ?>
            <a href="admin.php" class="nav-item">
                <span class="nav-icon">✦</span>
                Admin Dashboard
            </a>
        <?php endif; ?>

        </nav>


        <div class="sidebar-bottom">

            <div class="system-indicator">
                <span class="status-dot"></span>
                SYSTEM READY
            </div>

            <div class="user-panel">

                <div class="avatar">
                    <?= escape(strtoupper(
                        substr($firstName, 0, 1)
                    )) ?>
                </div>

                <div class="user-info">
                    <strong><?= escape($fullName) ?></strong>
                    
                <small>PERSONAL ACCOUNT</small>

                </div>

            </div>

        </div>

    </aside>


    <!-- ==============================
         MAIN CONTENT
         ============================== -->

    <main class="main-content">

        <!-- Top Header -->
        <header class="topbar">

            <div>
                <span class="eyebrow">
                    PERSONAL FINANCE TRACKER / DASHBOARD
                </span>

                <h1>
                    Financial <span>overview.</span>
                </h1>

                <p class="page-description">
                    Welcome, <?= escape($firstName) ?>.
                    Here's a clearer view of your money.
                </p>
            </div>

            <div class="header-actions">

                <button
                    type="button"
                    id="themeToggle"
                    class="outline-button"
                    aria-pressed="true"
                >
                    ☀ Light mode
                </button>

                <form action="logout.php" method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= escape(
                            $_SESSION["csrf_token"]
                        ) ?>"
                    >

                    <button
                        type="submit"
                        class="outline-button logout-button"
                    >
                        Log out
                    </button>

                </form>

            </div>

        </header>


        <!-- Month Selection -->
        <div class="section-toolbar">

            <div>
                <span class="section-code">
                    01 / FINANCIAL SUMMARY
                </span>
                <h2>Account snapshot</h2>
            </div>

            <label class="month-filter">
                SELECT MONTH

                <input
                    type="month"
                    id="monthInput"
                    value="<?= escape($currentMonth) ?>"
                >
            </label>

        </div>


        <!-- Loading / Error Feedback -->
        <p id="dashboardStatus"
           class="dashboard-status"
           role="status"
           aria-live="polite">
            Loading your financial data...
        </p>


        <!-- Financial Cards -->
        <section class="stats-grid"
                 aria-label="Financial summary">

            <article class="stat-card highlight">

                <div class="stat-header">
                    <span>TOTAL BALANCE</span>
                    <span>↗</span>
                </div>

                <div class="stat-value"
                     id="totalBalance">
                    ₱0.00
                </div>

                <p>All-time income minus expenses</p>

            </article>

            <article class="stat-card">

                <div class="stat-header">
                    <span>MONTHLY INCOME</span>
                    <span class="income-color">+</span>
                </div>

                <div class="stat-value income-color"
                     id="monthlyIncome">
                    ₱0.00
                </div>

                <p>Money received this month</p>

            </article>

            <article class="stat-card">

                <div class="stat-header">
                    <span>MONTHLY EXPENSES</span>
                    <span class="expense-color">−</span>
                </div>

                <div class="stat-value expense-color"
                     id="monthlyExpenses">
                    ₱0.00
                </div>

                <p>Money spent this month</p>

            </article>

            <article class="stat-card">

                <div class="stat-header">
                    <span>MONTHLY NET</span>
                    <span>◈</span>
                </div>

                <div class="stat-value"
                     id="monthlyNet">
                    ₱0.00
                </div>

                <p>Monthly income minus expenses</p>

            </article>

        </section>

        <!-- Active budget notification -->
        <section
            class="dashboard-budget-alert"
            id="dashboardBudgetAlert"
            role="status"
            aria-live="polite"
            hidden
        >
            <div>
                <strong id="dashboardBudgetAlertTitle">
                    Budget notification
                </strong>

                <p id="dashboardBudgetAlertText"></p>
            </div>

            <a href="budgets.php">
                VIEW BUDGETS ↗
            </a>
        </section>

        <!-- Charts -->
        <div class="charts-grid">

            <section class="panel">

                <div class="panel-header">

                    <div>
                        <span class="section-code">
                            02 / ACTIVITY
                        </span>
                        <h2>Cash flow</h2>
                    </div>

                    <span class="panel-label">
                        LAST 6 MONTHS
                    </span>

                </div>

                <div class="chart-container">
                    <canvas
                        id="cashFlowChart"
                        role="img"
                        aria-label="Income and expense comparison"
                    ></canvas>

                    <p class="empty-chart"
                       id="cashFlowEmpty"
                       hidden>
                        No financial activity to display.
                    </p>
                </div>

            </section>


            <section class="panel">

                <div class="panel-header">

                    <div>
                        <span class="section-code">
                            03 / BREAKDOWN
                        </span>
                        <h2>Spending by category</h2>
                    </div>

                </div>

                <div class="chart-container doughnut-container">

                    <canvas
                        id="spendingChart"
                        role="img"
                        aria-label="Expenses by category"
                    ></canvas>

                    <p class="empty-chart"
                       id="spendingEmpty"
                       hidden>
                        No expenses recorded for this month.
                    </p>

                </div>

                <div id="categoryLegend"
                     class="category-legend"></div>

            </section>

        </div>


        <!-- Recent Transactions -->
        <section class="panel transactions-panel">

            <div class="panel-header">

                <div>
                    <span class="section-code">
                        04 / TRANSACTION HISTORY
                    </span>
                    <h2>Recent transactions</h2>
                </div>

                <span class="panel-label">
                    SELECTED MONTH
                </span>

            </div>

            <div class="table-scroll">

                <table class="transactions-table">

                    <thead>
                        <tr>
                            <th>DATE</th>
                            <th>CATEGORY</th>
                            <th>DESCRIPTION</th>
                            <th>TYPE</th>
                            <th>AMOUNT</th>
                        </tr>
                    </thead>

                    <tbody id="recentTransactions">

                        <tr>
                            <td colspan="5"
                                class="table-empty">
                                Loading transactions...
                            </td>
                        </tr>

                    </tbody>

                </table>

            </div>

        </section>


        <footer class="page-footer">

            <span>
                PFT © <?= date("Y") ?>
            </span>

            <span>
                YOUR MONEY. IN FOCUS.
            </span>

        </footer>

    </main>

</div>

</body>
</html>
