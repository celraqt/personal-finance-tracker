
<?php
session_start();

if (empty($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
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

    <title>Transactions | Personal Finance Tracker</title>

    <link rel="stylesheet"
          href="assets/css/dashboard.css">

    <link rel="stylesheet" href="assets/css/transactions.css?v=4">

    <script src="assets/js/transactions.js?v=2" defer></script>

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

            <a href="dashboard.php"
               class="nav-item">

                <span class="nav-icon">▦</span>
                Dashboard

            </a>

            <a href="transactions.php"
               class="nav-item active"
               aria-current="page">

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

                    <strong>
                        <?= escape($fullName) ?>
                    </strong>

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
                    PERSONAL FINANCE TRACKER / TRANSACTIONS
                </span>

                <h1>
                    Your <span>transactions.</span>
                </h1>

                <p class="page-description">
                    Record your income, track your spending,
                    and keep your finances organized.
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
                        class="outline-button logout-button"
                        type="submit"
                    >
                        Log out
                    </button>

                </form>

            </div>

        </header>


        <div class="transaction-grid">

            <!-- ADD / EDIT FORM -->
            <section class="panel entry-panel">

                <div class="panel-header">

                    <div>
                        <span class="section-code">
                            01 / FINANCIAL ENTRY
                        </span>

                        <h2 id="formHeading">
                            Add transaction
                        </h2>
                    </div>

                </div>

                <p class="entry-description">
                    Fill in the details below to record
                    your income or expense.
                </p>

                <form id="transactionForm">

                    <div class="transaction-field">

                        <label for="transactionType">
                            TRANSACTION TYPE
                        </label>

                        <select
                            id="transactionType"
                            required
                        >
                            <option value="expense">
                                Expense
                            </option>

                            <option value="income">
                                Income
                            </option>
                        </select>

                    </div>

                    <div class="transaction-field">

                        <label for="transactionCategory">
                            CATEGORY
                        </label>

                        <select
                            id="transactionCategory"
                            required
                        >
                            <option value="">
                                Loading categories...
                            </option>
                        </select>

                    </div>

                    <div class="transaction-field">

                        <label for="transactionAmount">
                            AMOUNT (PHP)
                        </label>

                        <input
                            type="number"
                            id="transactionAmount"
                            min="0.01"
                            max="9999999999.99"
                            step="0.01"
                            placeholder="0.00"
                            required
                        >

                    </div>

                    <div class="transaction-field">

                        <label for="transactionDate">
                            TRANSACTION DATE
                        </label>

                        <input
                            type="date"
                            id="transactionDate"
                            value="<?= escape($today) ?>"
                            required
                        >

                    </div>

                    <div class="transaction-field">

                        <label for="transactionDescription">
                            DESCRIPTION (OPTIONAL)
                        </label>

                        <textarea
                            id="transactionDescription"
                            placeholder="e.g. Lunch at school"
                            rows="3"
                            maxlength="255"
                        ></textarea>

                    </div>

                    <p
                        id="formMessage"
                        class="form-message"
                        role="status"
                        aria-live="polite"
                    ></p>

                    <button
                        type="submit"
                        id="saveTransaction"
                        class="transaction-primary"
                    >
                        ADD TRANSACTION ↗
                    </button>

                    <button
                        type="button"
                        id="cancelEdit"
                        class="transaction-secondary"
                        hidden
                    >
                        CANCEL EDIT
                    </button>

                </form>

            </section>


            <!-- TRANSACTION HISTORY -->
            <section class="panel history-panel">

                <div class="panel-header history-header">

                    <div>

                        <span class="section-code">
                            02 / FINANCIAL RECORDS
                        </span>

                        <h2>
                            Transaction history
                        </h2>

                    </div>

                    <label class="month-filter">

                        SELECT MONTH

                        <input
                            type="month"
                            id="monthInput"
                            value="<?= escape(
                                $currentMonth
                            ) ?>"
                        >

                    </label>

                </div>
    

                

<!-- LIVE TRANSACTION FILTERS -->
<div class="transaction-filters">

    <div class="filter-heading">

        <div>
            <span class="section-code">
                SEARCH / FILTER
            </span>

            <p class="filter-intro">
                Find and organize your financial records.
            </p>
        </div>

        <button
            type="button"
            id="clearFilters"
            class="filter-clear-button"
        >
            ↻ RESET FILTERS
        </button>

    </div>


    <!-- PRIMARY FILTERS -->
    <div class="filter-main-row">

        <div class="transaction-filter-field search-field">

            <label for="filterSearch">
                SEARCH TRANSACTIONS
            </label>

            <div class="filter-search-wrap">

                <span class="filter-search-icon"
                      aria-hidden="true">
                    ⌕
                </span>

                <input
                    type="search"
                    id="filterSearch"
                    placeholder="Search your transactions..."
                    autocomplete="off"
                >

            </div>

        </div>


        <div class="transaction-filter-field">

            <label for="filterType">
                TYPE
            </label>

            <select id="filterType">
                <option value="all">All Types</option>
                <option value="income">Income</option>
                <option value="expense">Expense</option>
            </select>

        </div>


        <div class="transaction-filter-field">

            <label for="filterCategory">
                CATEGORY
            </label>

            <select id="filterCategory">
                <option value="all">All Categories</option>
            </select>

        </div>

    </div>


    <!-- EXPANDABLE ADVANCED FILTERS -->
    <details class="advanced-filters">

        <summary>

            <span class="advanced-filter-title">
                <span>ADVANCED FILTERS</span>
                <small>AMOUNT RANGE / SORTING</small>
            </span>

            <span class="filter-chevron"
                  aria-hidden="true">⌄</span>

        </summary>

        <div class="filter-advanced-grid">

            <div class="transaction-filter-field">

                <label for="filterMinAmount">
                    MINIMUM AMOUNT
                </label>

                <input
                    type="number"
                    id="filterMinAmount"
                    placeholder="₱0.00"
                    min="0"
                    step="0.01"
                >

            </div>


            <div class="transaction-filter-field">

                <label for="filterMaxAmount">
                    MAXIMUM AMOUNT
                </label>

                <input
                    type="number"
                    id="filterMaxAmount"
                    placeholder="No limit"
                    min="0"
                    step="0.01"
                >

            </div>


            <div class="transaction-filter-field">

                <label for="filterSort">
                    SORT BY
                </label>

                <select id="filterSort">
                    <option value="newest">
                        Newest First
                    </option>

                    <option value="oldest">
                        Oldest First
                    </option>

                    <option value="highest">
                        Highest Amount
                    </option>

                    <option value="lowest">
                        Lowest Amount
                    </option>

                    <option value="category">
                        Category A–Z
                    </option>
                </select>

            </div>

        </div>

    </details>


    <!-- VALIDATION FEEDBACK -->
    <p
        id="filterNotice"
        class="filter-notice"
        role="status"
        aria-live="polite"
    ></p>


    <!-- FILTERED FINANCIAL TOTALS -->
    <div class="filtered-totals">

        <div class="filtered-total">

            <span>FILTERED INCOME</span>

            <strong id="filteredIncome">
                ₱0.00
            </strong>

        </div>


        <div class="filtered-total">

            <span>FILTERED EXPENSES</span>

            <strong id="filteredExpenses">
                ₱0.00
            </strong>

        </div>


        <div class="filtered-total">

            <span>FILTERED NET</span>

            <strong id="filteredNet">
                ₱0.00
            </strong>

        </div>

    </div>

</div>


                <div class="history-summary">

                    <span id="transactionCount">
                        0 transactions
                    </span>

                    <span id="historyStatus"
                          aria-live="polite">
                        Loading...
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
                                <th>ACTIONS</th>
                            </tr>

                        </thead>

                        <tbody id="transactionRows">

                            <tr>

                                <td colspan="6"
                                    class="table-empty">
                                    Loading transactions...
                                </td>

                            </tr>

                        </tbody>

                    </table>

                </div>

            </section>

        </div>


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
