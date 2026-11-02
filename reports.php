
<?php
session_start();

if (empty($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

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

$today = date("Y-m-d");
$monthStart = date("Y-m-01");
?>

<!DOCTYPE html>
<html lang="en" data-theme="dark">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <meta name="theme-color" content="#111514">

    <title>Reports | Personal Finance Tracker</title>

    <link rel="stylesheet"
          href="assets/css/dashboard.css">

    <link rel="stylesheet"
          href="assets/css/reports.css">

    <script
        src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"
        defer>
    </script>

    <script src="assets/js/reports.js?v=1" defer></script>
</head>

<body>

<div class="app-layout">

    <!-- SIDEBAR -->
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

        <nav class="sidebar-nav"
             aria-label="Main navigation">

            <a href="dashboard.php" class="nav-item">
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

            <a href="reports.php"
               class="nav-item active"
               aria-current="page">
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


    <!-- MAIN CONTENT -->
    <main class="main-content">

        <header class="topbar">

            <div>

                <span class="eyebrow">
                    PERSONAL FINANCE TRACKER / REPORTS
                </span>

                <h1>
                    Financial <span>insights.</span>
                </h1>

                <p class="page-description">
                    Understand where your money comes from,
                    where it goes, and how your finances
                    change over time.
                </p>

            </div>

            <div class="header-actions">

                <button
                    type="button"
                    class="outline-button"
                    id="themeToggle"
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


        <!-- REPORT FILTERS -->
        <section class="report-filter-panel">

            <div class="filter-heading">

                <div>
                    <span class="section-code">
                        01 / REPORT CONFIGURATION
                    </span>

                    <h2>Select reporting period</h2>
                </div>

                <span class="filter-note">
                    UP TO 2 YEARS
                </span>

            </div>

            <form id="reportFilterForm" class="report-filters">

                <label class="report-field">

                    DATE RANGE

                    <select id="presetRange">
                        <option value="this-month">
                            This month
                        </option>

                        <option value="last-month">
                            Last month
                        </option>

                        <option value="last-90">
                            Last 90 days
                        </option>

                        <option value="this-year">
                            This year
                        </option>

                        <option value="custom">
                            Custom range
                        </option>
                    </select>

                </label>

                <label class="report-field">

                    START DATE

                    <input
                        type="date"
                        id="startDate"
                        value="<?= escape($monthStart) ?>"
                        required
                    >

                </label>

                <label class="report-field">

                    END DATE

                    <input
                        type="date"
                        id="endDate"
                        value="<?= escape($today) ?>"
                        required
                    >

                </label>

                <button
                    type="submit"
                    class="report-apply"
                    id="applyReport"
                >
                    APPLY FILTER ↗
                </button>

                <button
                    type="button"
                    class="report-export"
                    id="exportCsv"
                    disabled
                >
                    ↓ EXPORT CSV
                </button>

            </form>

            <p
                class="report-status"
                id="reportStatus"
                role="status"
                aria-live="polite"
            >
                Loading report...
            </p>

        </section>


        <!-- SUMMARY -->
        <div class="report-section-heading">

            <span class="section-code">
                02 / FINANCIAL PERFORMANCE
            </span>

            <h2>Period summary</h2>

        </div>

        <section
            class="stats-grid"
            aria-label="Report financial summary"
        >

            <article class="stat-card">

                <div class="stat-header">
                    <span>TOTAL INCOME</span>
                    <span class="income-color">+</span>
                </div>

                <div
                    class="stat-value income-color"
                    id="reportIncome"
                >
                    ₱0.00
                </div>

                <p>Money received during the period</p>

            </article>

            <article class="stat-card">

                <div class="stat-header">
                    <span>TOTAL EXPENSES</span>
                    <span class="expense-color">−</span>
                </div>

                <div
                    class="stat-value expense-color"
                    id="reportExpenses"
                >
                    ₱0.00
                </div>

                <p>Total money spent during the period</p>

            </article>

            <article class="stat-card highlight">

                <div class="stat-header">
                    <span>NET CASH FLOW</span>
                    <span>◈</span>
                </div>

                <div
                    class="stat-value"
                    id="reportNet"
                >
                    ₱0.00
                </div>

                <p>Income minus expenses</p>

            </article>

            <article class="stat-card">

                <div class="stat-header">
                    <span>SAVINGS RATE</span>
                    <span>%</span>
                </div>

                <div
                    class="stat-value"
                    id="savingsRate"
                >
                    —
                </div>

                <p id="savingsDescription">
                    Percentage of income remaining
                </p>

            </article>

        </section>


        <!-- CHARTS -->
        <div class="charts-grid">

            <section class="panel">

                <div class="panel-header">

                    <div>
                        <span class="section-code">
                            03 / MONEY MOVEMENT
                        </span>
                        <h2>Income vs. expenses</h2>
                    </div>

                    <span
                        class="panel-label"
                        id="trendRangeCaption"
                    >
                        CASH FLOW TREND
                    </span>

                </div>

                <div class="chart-container">

                    <canvas
                        id="reportTrendChart"
                        role="img"
                        aria-label="Income and expense trend"
                    ></canvas>

                    <p
                        id="trendEmpty"
                        class="empty-chart"
                        hidden
                    >
                        No financial activity for this period.
                    </p>

                </div>

            </section>


            <section class="panel">

                <div class="panel-header">

                    <div>
                        <span class="section-code">
                            04 / SPENDING STRUCTURE
                        </span>
                        <h2>Expense breakdown</h2>
                    </div>

                </div>

                <div class="chart-container">

                    <canvas
                        id="reportCategoryChart"
                        role="img"
                        aria-label="Expense distribution by category"
                    ></canvas>

                    <p
                        id="categoryEmpty"
                        class="empty-chart"
                        hidden
                    >
                        No expenses for this period.
                    </p>

                </div>

                <div
                    id="categoryBreakdown"
                    class="report-category-breakdown"
                ></div>

            </section>

        </div>


        <!-- INSIGHTS -->
        <div class="report-section-heading insight-heading">

            <span class="section-code">
                05 / SPENDING INTELLIGENCE
            </span>

            <h2>Key insights</h2>

        </div>

        <section class="insight-grid">

            <article class="insight-card">

                <span class="insight-label">
                    TOP SPENDING CATEGORY
                </span>

                <strong id="topCategory">
                    —
                </strong>

                <p id="topCategoryDetails">
                    No category data available.
                </p>

            </article>

            <article class="insight-card">

                <span class="insight-label">
                    AVERAGE DAILY SPENDING
                </span>

                <strong id="dailyAverage">
                    ₱0.00
                </strong>

                <p>
                    Average expense per calendar day
                    in the selected period.
                </p>

            </article>

            <article class="insight-card">

                <span class="insight-label">
                    LARGEST SINGLE EXPENSE
                </span>

                <strong id="largestExpense">
                    —
                </strong>

                <p id="largestExpenseDetails">
                    No expenses recorded.
                </p>

            </article>

            <article class="insight-card">

                <span class="insight-label">
                    TOTAL TRANSACTIONS
                </span>

                <strong id="transactionCount">
                    0
                </strong>

                <p id="transactionDetails">
                    Transactions within this period.
                </p>

            </article>

        </section>


        <!-- RECENT TRANSACTIONS -->
        <section class="panel report-transactions-panel">

            <div class="panel-header">

                <div>
                    <span class="section-code">
                        06 / REPORT ACTIVITY
                    </span>

                    <h2>Recent transactions</h2>
                </div>

                <span class="panel-label">
                    LATEST 10 IN SELECTED PERIOD
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

                    <tbody id="reportTransactions">

                        <tr>
                            <td colspan="5"
                                class="table-empty">
                                Loading transactions...
                            </td>
                        </tr>

                    </tbody>

                </table>

            </div>

            <p class="report-table-footnote">
                Export CSV to download all transactions
                in the selected reporting period.
            </p>

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
