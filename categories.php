
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

function escape($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

$fullName = $_SESSION["full_name"] ?? "User";
$firstName = explode(" ", trim($fullName))[0];
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

    <title>Categories | Personal Finance Tracker</title>

    <link rel="stylesheet"
          href="assets/css/dashboard.css">

    <link rel="stylesheet"
          href="assets/css/categories.css">

    <script src="assets/js/categories.js?v=1" defer></script>
</head>

<body>

<div class="app-layout">

    <!-- =============================
         SIDEBAR
         ============================= -->

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

            <a href="categories.php"
               class="nav-item active"
               aria-current="page">
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
                    <strong><?= escape($fullName) ?></strong>
                    <small>PERSONAL ACCOUNT</small>
                </div>

            </div>

        </div>

    </aside>


    <!-- =============================
         MAIN PAGE
         ============================= -->

    <main class="main-content">

        <header class="topbar">

            <div>

                <span class="eyebrow">
                    PERSONAL FINANCE TRACKER / CATEGORIES
                </span>

                <h1>
                    Your <span>categories.</span>
                </h1>

                <p class="page-description">
                    Organize your financial activity
                    with categories that make sense to you.
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


        <div class="category-layout">

            <!-- ADD / RENAME CATEGORY -->
            <section class="panel category-form-panel">

                <div class="panel-header">

                    <div>
                        <span class="section-code">
                            01 / CATEGORY SETUP
                        </span>

                        <h2 id="categoryFormTitle">
                            Create category
                        </h2>
                    </div>

                </div>

                <p class="category-description">
                    Add your own spending or income
                    categories to personalize your tracker.
                </p>

                <form id="categoryForm">

                    <div class="category-field">

                        <label for="categoryName">
                            CATEGORY NAME
                        </label>

                        <input
                            type="text"
                            id="categoryName"
                            placeholder="e.g. Entertainment"
                            minlength="2"
                            maxlength="50"
                            autocomplete="off"
                            aria-describedby="nameHint"
                            required
                        >

                        <small id="nameHint">
                            Use a short, descriptive category name.
                        </small>

                    </div>

                    <div class="category-field">

                        <label for="categoryType">
                            CATEGORY TYPE
                        </label>

                        <select
                            id="categoryType"
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

                    <p
                        id="categoryMessage"
                        class="category-message"
                        role="status"
                        aria-live="polite"
                    ></p>

                    <button
                        type="submit"
                        id="saveCategory"
                        class="category-primary"
                    >
                        CREATE CATEGORY ↗
                    </button>

                    <button
                        type="button"
                        id="cancelCategoryEdit"
                        class="category-secondary"
                        hidden
                    >
                        CANCEL EDIT
                    </button>

                </form>

                <div class="category-tip">

                    <strong>GOOD TO KNOW</strong>

                    <p>
                        Built-in categories are protected.
                        Custom categories can be renamed,
                        but categories used by transactions
                        cannot be deleted.
                    </p>

                </div>

            </section>


            <!-- CATEGORY LISTS -->
            <div class="category-lists" id="categoryLists">

                <!-- EXPENSE CATEGORIES -->
                <section class="panel category-list-panel">

                    <div class="panel-header">

                        <div>
                            <span class="section-code">
                                02 / OUTGOING
                            </span>

                            <h2>Expense categories</h2>
                        </div>

                        <span class="category-count"
                              id="expenseCount">
                            0 CATEGORIES
                        </span>

                    </div>

                    <p class="list-description">
                        Group your spending into meaningful categories.
                    </p>

                    <div
                        id="expenseCategoryList"
                        class="category-items"
                        aria-live="polite"
                    >
                        <p class="category-empty">
                            Loading expense categories...
                        </p>
                    </div>

                </section>


                <!-- INCOME CATEGORIES -->
                <section class="panel category-list-panel">

                    <div class="panel-header">

                        <div>
                            <span class="section-code">
                                03 / INCOMING
                            </span>

                            <h2>Income categories</h2>
                        </div>

                        <span class="category-count"
                              id="incomeCount">
                            0 CATEGORIES
                        </span>

                    </div>

                    <p class="list-description">
                        Identify where your money comes from.
                    </p>

                    <div
                        id="incomeCategoryList"
                        class="category-items"
                        aria-live="polite"
                    >
                        <p class="category-empty">
                            Loading income categories...
                        </p>
                    </div>

                </section>

            </div>

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
