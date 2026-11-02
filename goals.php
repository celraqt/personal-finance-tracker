
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

    <title>Savings Goals | Personal Finance Tracker</title>

    <link
        rel="stylesheet"
        href="assets/css/dashboard.css"
    >

    <link
        rel="stylesheet"
        href="assets/css/goals.css?v=1"
    >

    <script src="assets/js/goals.js?v=1" defer></script>

</head>

<body>

<div class="app-layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">

        <a
            href="dashboard.php"
            class="sidebar-logo"
            aria-label="Personal Finance Tracker home"
        >
            <img
                src="assets/images/pftlogo.png"
                alt="Personal Finance Tracker"
            >
        </a>

        <div class="sidebar-label">
            WORKSPACE / 01
        </div>

        <nav class="sidebar-nav" aria-label="Main navigation">

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

            <a href="budgets.php" class="nav-item">
                <span class="nav-icon">◎</span>
                Budgets
            </a>

            <a
                href="goals.php"
                class="nav-item active"
                aria-current="page"
            >
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
                    PERSONAL FINANCE TRACKER / SAVINGS GOALS
                </span>

                <h1>
                    Build your <span>future.</span>
                </h1>

                <p class="page-description">
                    Set meaningful savings targets,
                    record contributions, and track
                    every step toward your financial goals.
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


        <!-- SUMMARY -->
        <div class="goal-section-heading">
            <span class="section-code">
                01 / SAVINGS OVERVIEW
            </span>
            <h2>Your savings snapshot</h2>
        </div>

        <p
            id="goalsStatus"
            class="goals-status"
            role="status"
            aria-live="polite"
        >
            Loading savings goals...
        </p>

        <section
            class="stats-grid"
            aria-label="Savings summary"
        >

            <article class="stat-card highlight">

                <div class="stat-header">
                    <span>TOTAL SAVINGS TARGET</span>
                    <span>◈</span>
                </div>

                <div
                    class="stat-value"
                    id="totalGoalTarget"
                >
                    ₱0.00
                </div>

                <p>Combined target of all goals</p>

            </article>

            <article class="stat-card">

                <div class="stat-header">
                    <span>CONTRIBUTIONS RECORDED</span>
                    <span>+</span>
                </div>

                <div
                    class="stat-value income-color"
                    id="totalGoalSaved"
                >
                    ₱0.00
                </div>

                <p>Total savings allocated to goals</p>

            </article>

            <article class="stat-card">

                <div class="stat-header">
                    <span>STILL TO SAVE</span>
                    <span>↗</span>
                </div>

                <div
                    class="stat-value"
                    id="totalGoalRemaining"
                >
                    ₱0.00
                </div>

                <p>Amount needed to reach your goals</p>

            </article>

            <article class="stat-card">

                <div class="stat-header">
                    <span>GOALS COMPLETED</span>
                    <span>✓</span>
                </div>

                <div
                    class="stat-value"
                    id="completedGoalsCount"
                >
                    0
                </div>

                <p>Targets you've reached</p>

            </article>

        </section>


        <!-- WORKSPACE -->
        <div class="goals-workspace">

            <!-- LEFT COLUMN -->
            <div class="goals-forms-column">

                <!-- CREATE / EDIT GOAL -->
                <section class="panel" id="goalEditorPanel">

                    <div class="panel-header">

                        <div>
                            <span class="section-code">
                                02 / GOAL SETUP
                            </span>

                            <h2 id="goalFormHeading">
                                Create savings goal
                            </h2>
                        </div>

                    </div>

                    <p class="goals-description">
                        Give your goal a name and set
                        the amount you want to save.
                    </p>

                    <form id="goalForm">

                        <div class="goal-field">

                            <label for="goalName">
                                GOAL NAME
                            </label>

                            <input
                                type="text"
                                id="goalName"
                                placeholder="e.g. Laptop Fund"
                                minlength="2"
                                maxlength="100"
                                required
                            >

                        </div>

                        <div class="goal-field">

                            <label for="goalTarget">
                                TARGET AMOUNT (PHP)
                            </label>

                            <input
                                type="number"
                                id="goalTarget"
                                min="0.01"
                                max="9999999999.99"
                                step="0.01"
                                placeholder="30000.00"
                                required
                            >

                        </div>

                        <div class="goal-field">

                            <label for="goalDeadline">
                                TARGET DATE (OPTIONAL)
                            </label>

                            <input
                                type="date"
                                id="goalDeadline"
                            >

                        </div>

                        <p
                            id="goalMessage"
                            class="goals-message"
                            role="status"
                            aria-live="polite"
                        ></p>

                        <button
                            type="submit"
                            class="goals-primary"
                            id="saveGoal"
                        >
                            CREATE GOAL ↗
                        </button>

                        <button
                            type="button"
                            class="goals-secondary"
                            id="cancelGoalEdit"
                            hidden
                        >
                            CANCEL EDIT
                        </button>

                    </form>

                </section>


                <!-- ADD CONTRIBUTION -->
                <section
                    class="panel"
                    id="contributionEditorPanel"
                >

                    <div class="panel-header">

                        <div>
                            <span class="section-code">
                                03 / SAVINGS ENTRY
                            </span>

                            <h2>Add contribution</h2>
                        </div>

                    </div>

                    <p class="goals-description">
                        Record money you've set aside
                        for an existing goal.
                    </p>

                    <form id="contributionForm">

                        <div class="goal-field">

                            <label for="contributionGoal">
                                SAVINGS GOAL
                            </label>

                            <select
                                id="contributionGoal"
                                required
                            >
                                <option value="">
                                    Loading goals...
                                </option>
                            </select>

                        </div>

                        <div class="goal-field">

                            <label for="contributionAmount">
                                CONTRIBUTION AMOUNT (PHP)
                            </label>

                            <input
                                type="number"
                                id="contributionAmount"
                                min="0.01"
                                max="9999999999.99"
                                step="0.01"
                                placeholder="500.00"
                                required
                            >

                        </div>

                        <div class="goal-field">

                            <label for="contributionDate">
                                CONTRIBUTION DATE
                            </label>

                            <input
                                type="date"
                                id="contributionDate"
                                value="<?= escape($today) ?>"
                                required
                            >

                        </div>

                        <div class="goal-field">

                            <label for="contributionNote">
                                NOTE (OPTIONAL)
                            </label>

                            <textarea
                                id="contributionNote"
                                rows="3"
                                maxlength="255"
                                placeholder="e.g. Saved from my allowance"
                            ></textarea>

                        </div>

                        <p
                            class="goals-message"
                            id="contributionMessage"
                            role="status"
                            aria-live="polite"
                        ></p>

                        <button
                            type="submit"
                            class="goals-primary"
                            id="saveContribution"
                        >
                            ADD SAVINGS ↗
                        </button>

                    </form>

                    <p class="goals-info">
                        Contributions track savings
                        progress only. They do not change
                        your income or expense records.
                    </p>

                </section>

            </div>


            <!-- RIGHT COLUMN -->
            <div class="goals-content-column">

                <!-- GOAL CARDS -->
                <section class="panel">

                    <div class="panel-header">

                        <div>
                            <span class="section-code">
                                04 / SAVINGS PROGRESS
                            </span>

                            <h2>Your goals</h2>
                        </div>

                        <span
                            class="goals-count"
                            id="goalsCount"
                        >
                            0 GOALS
                        </span>

                    </div>

                    <div
                        id="goalsList"
                        class="goals-list"
                        aria-live="polite"
                    >
                        <p class="goals-empty">
                            Loading goals...
                        </p>
                    </div>

                </section>


                <!-- CONTRIBUTION HISTORY -->
                <section class="panel">

                    <div class="panel-header goals-history-header">

                        <div>
                            <span class="section-code">
                                05 / SAVINGS ACTIVITY
                            </span>

                            <h2>Contribution history</h2>
                        </div>

                        <label class="goal-history-filter">
                            VIEW GOAL

                            <select id="historyGoalFilter">
                                <option value="all">
                                    All Goals
                                </option>
                            </select>
                        </label>

                    </div>

                    <div
                        class="goals-contribution-list"
                        id="contributionHistory"
                    >
                        <p class="goals-empty">
                            Loading contributions...
                        </p>
                    </div>

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
