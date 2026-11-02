
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
$currentMonth = date("Y-m");
?>

<!DOCTYPE html>
<html lang="en" data-theme="dark">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <meta name="theme-color" content="#111514">

    <meta name="csrf-token"
          content="<?= escape($_SESSION["csrf_token"]) ?>">

    <title>Budgets | Personal Finance Tracker</title>

    <link rel="stylesheet"
          href="assets/css/dashboard.css">

    <link rel="stylesheet"
          href="assets/css/budgets.css">

    <script src="assets/js/budgets.js?v=1" defer></script>
</head>

<body>

<div class="app-layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">

        <a href="dashboard.php"
           class="sidebar-logo"
           aria-label="Personal Finance Tracker home">

            <img src="assets/images/pftlogo.png"
                 alt="Personal Finance Tracker">
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

            <a href="reports.php" class="nav-item">
                <span class="nav-icon">▥</span>
                Reports
            </a>

            <a href="budgets.php"
               class="nav-item active"
               aria-current="page">
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
                    PERSONAL FINANCE TRACKER / BUDGETS
                </span>

                <h1>
                    Plan your <span>spending.</span>
                </h1>

                <p class="page-description">
                    Set monthly spending limits, monitor
                    your progress, and stay aware of
                    upcoming budget limits.
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


        <!-- MONTH SELECTOR -->
        <div class="section-toolbar">

            <div>
                <span class="section-code">
                    01 / BUDGET OVERVIEW
                </span>
                <h2>Monthly budget summary</h2>
            </div>

            <label class="month-filter">
                SELECT MONTH

                <input
                    type="month"
                    id="budgetMonth"
                    value="<?= escape($currentMonth) ?>"
                    min="2000-01"
                    max="2099-12"
                >
            </label>

        </div>

        <p id="budgetStatus"
           class="budget-status"
           role="status"
           aria-live="polite">
            Loading your budgets...
        </p>


        <!-- SUMMARY CARDS -->
        <section class="stats-grid">

            <article class="stat-card highlight">
                <div class="stat-header">
                    <span>TOTAL BUDGET</span>
                    <span>◎</span>
                </div>

                <div class="stat-value"
                     id="totalBudget">
                    ₱0.00
                </div>

                <p>Combined monthly budget limits</p>
            </article>

            <article class="stat-card">
                <div class="stat-header">
                    <span>BUDGETED SPENDING</span>
                    <span>↗</span>
                </div>

                <div class="stat-value expense-color"
                     id="budgetSpent">
                    ₱0.00
                </div>

                <p>Expenses in budgeted categories</p>
            </article>

            <article class="stat-card">
                <div class="stat-header">
                    <span>NET REMAINING</span>
                    <span>◈</span>
                </div>

                <div class="stat-value"
                     id="budgetRemaining">
                    ₱0.00
                </div>

                <p>Budget limits minus budgeted spending</p>
            </article>

            <article class="stat-card">
                <div class="stat-header">
                    <span>ACTIVE ALERTS</span>
                    <span>♧</span>
                </div>

                <div class="stat-value"
                     id="budgetAlertCount">
                    0
                </div>

                <p>Categories near or above their limit</p>
            </article>

        </section>


        <!-- MAIN BUDGET AREA -->
        <div class="budget-layout">

            <!-- CREATE / EDIT FORM -->
            <section class="panel budget-form-panel">

                <div class="panel-header">
                    <div>
                        <span class="section-code">
                            02 / BUDGET SETUP
                        </span>
                        <h2 id="budgetFormTitle">
                            Create budget
                        </h2>
                    </div>
                </div>

                <p class="budget-description">
                    Choose an expense category and set
                    the maximum amount you want to spend
                    during the selected month.
                </p>

                <form id="budgetForm">

                    <div class="budget-field">
                        <label for="budgetCategory">
                            EXPENSE CATEGORY
                        </label>

                        <select id="budgetCategory" required>
                            <option value="">
                                Loading categories...
                            </option>
                        </select>
                    </div>

                    <div class="budget-field">
                        <label for="budgetAmount">
                            MONTHLY LIMIT (PHP)
                        </label>

                        <input
                            type="number"
                            id="budgetAmount"
                            min="0.01"
                            max="9999999999.99"
                            step="0.01"
                            placeholder="e.g. 5000.00"
                            required
                        >
                    </div>

                    <p class="budget-form-message"
                       id="budgetFormMessage"
                       role="status"
                       aria-live="polite"></p>

                    <button
                        type="submit"
                        class="budget-primary"
                        id="saveBudget"
                    >
                        CREATE BUDGET ↗
                    </button>

                    <button
                        type="button"
                        class="budget-secondary"
                        id="cancelBudgetEdit"
                        hidden
                    >
                        CANCEL EDIT
                    </button>

                </form>

                <div class="budget-tip">
                    <strong>HOW ALERTS WORK</strong>

                    <p>
                        PFT shows a warning when spending
                        reaches 80% of a category budget
                        and a critical alert at 100%.
                    </p>
                </div>

            </section>


            <div class="budget-content">

                <!-- ACTIVE IN-SYSTEM NOTIFICATIONS -->
                <section class="panel budget-alert-panel">

                    <div class="panel-header">

                        <div>
                            <span class="section-code">
                                03 / NOTIFICATION CENTER
                            </span>
                            <h2>Spending alerts</h2>
                        </div>

                        <span class="budget-count"
                              id="alertCountLabel">
                            0 ACTIVE
                        </span>

                    </div>

                    <div id="budgetAlerts"
                         class="budget-alert-list"
                         aria-live="polite">

                        <p class="budget-empty">
                            Checking your budget limits...
                        </p>
                    </div>

                </section>


                <!-- BUDGET LIST -->
                <section class="panel">

                    <div class="panel-header">

                        <div>
                            <span class="section-code">
                                04 / BUDGET TRACKING
                            </span>
                            <h2>Category budgets</h2>
                        </div>

                        <span class="budget-count"
                              id="budgetCount">
                            0 BUDGETS
                        </span>

                    </div>

                    <div id="budgetList"
                         class="budget-list">

                        <p class="budget-empty">
                            Loading category budgets...
                        </p>

                    </div>

                    <p class="unbudgeted-note"
                       id="unbudgetedNote"></p>

                </section>

            </div>

        </div>


        <footer class="page-footer">
            <span>PFT © <?= date("Y") ?></span>
            <span>YOUR MONEY. IN FOCUS.</span>
        </footer>

    </main>
</div>

</body>
</html>
