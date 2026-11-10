<?php
session_start();

// Redirect already signed-in active users.
if (isset($_SESSION["user_id"])) {
    try {
        $sessionDb = require __DIR__ . "/config/database.php";

        $sessionStmt = $sessionDb->prepare(
            "SELECT role, account_status
             FROM users
             WHERE user_id = ?
             LIMIT 1"
        );

        $sessionStmt->execute([(int) $_SESSION["user_id"]]);
        $sessionUser = $sessionStmt->fetch(PDO::FETCH_ASSOC);

        if (
            $sessionUser &&
            $sessionUser["account_status"] === "active"
        ) {
            if ($sessionUser["role"] === "admin") {
                header("Location: admin.php");
                exit;
            }

            if ($sessionUser["role"] === "user") {
                header("Location: dashboard.php");
                exit;
            }
        }

        unset(
            $_SESSION["user_id"],
            $_SESSION["full_name"]
        );

    } catch (Throwable $e) {
        error_log("PFT login session check failed.");
        http_response_code(503);
        exit("Login temporarily unavailable.");
    }
}

// CSRF protection
if (empty($_SESSION["login_csrf_token"])) {
    $_SESSION["login_csrf_token"] =
        bin2hex(random_bytes(32));
}

$error = "";
$email = "";
$isSuspended = false;

// Registration success message
$success = $_SESSION["register_success"] ?? "";
unset($_SESSION["register_success"]);

// Handle login form
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $emailInput = $_POST["email"] ?? "";
    $passwordInput = $_POST["password"] ?? "";
    $tokenInput = $_POST["csrf_token"] ?? "";

    $email = is_string($emailInput)
        ? trim($emailInput)
        : "";

    $password = is_string($passwordInput)
        ? $passwordInput
        : "";

    $token = is_string($tokenInput)
        ? $tokenInput
        : "";

    // Validate CSRF token
    if (!hash_equals(
        $_SESSION["login_csrf_token"],
        $token
    )) {

        $error = "Invalid form request. Please try again.";

        $_SESSION["login_csrf_token"] =
            bin2hex(random_bytes(32));

    } elseif ($email === "" || $password === "") {

        $error = "Please complete all fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } else {

        try {
            // Database connection
            $pdo = require __DIR__ . "/config/database.php";

            // Find user account
            $stmt = $pdo->prepare(
                "SELECT
                    user_id,
                    full_name,
                    password_hash,
                    role,
                    account_status
                 FROM users
                 WHERE email = ?
                 LIMIT 1"
            );

            $stmt->execute([$email]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verify password securely
            $validPassword = $user !== false
                && password_verify(
                    $password,
                    $user["password_hash"]
                );

            // Suspended account
            if (
                $validPassword &&
                $user["account_status"] === "suspended"
            ) {

                session_regenerate_id(true);

                unset(
                    $_SESSION["user_id"],
                    $_SESSION["full_name"]
                );

                // Allow limited account appeal access
                $_SESSION["suspended_appeal_user_id"] =
                    (int) $user["user_id"];

                $_SESSION["suspended_appeal_expires"] =
                    time() + 900;

                unset($_SESSION["appeal_csrf_token"]);

                $isSuspended = true;

                $error = "Your account has been suspended. "
                    . "You may request an account review.";

            // Successful login
            } elseif (
                $validPassword &&
                $user["account_status"] === "active"
            ) {

                // Record last successful login
                try {
                    $updateLogin = $pdo->prepare(
                        "UPDATE users
                         SET last_login_at = NOW()
                         WHERE user_id = ?"
                    );

                    $updateLogin->execute([
                        (int) $user["user_id"]
                    ]);

                } catch (PDOException $e) {
                    error_log(
                        "PFT login timestamp update failed."
                    );
                }

                // Prevent session fixation
                session_regenerate_id(true);

                // Store authenticated account
                $_SESSION["user_id"] =
                    (int) $user["user_id"];

                $_SESSION["full_name"] =
                    $user["full_name"];

                // Clear temporary session values
                unset(
                    $_SESSION["login_csrf_token"],
                    $_SESSION["suspended_appeal_user_id"],
                    $_SESSION["suspended_appeal_expires"],
                    $_SESSION["appeal_csrf_token"]
                );

                // Redirect based on account role
                if ($user["role"] === "admin") {
                    header("Location: admin.php");
                } else {
                    header("Location: dashboard.php");
                }

                exit;

            } else {

                $error = "Invalid email or password.";
            }

        } catch (PDOException $e) {

            error_log(
                "Login error: " . $e->getMessage()
            );

            $error = "Login is temporarily unavailable.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta name="theme-color" content="#111514">

    <title>Login | PFT</title>

    <!-- PFT Shared CSS -->
    <link rel="stylesheet" href="assets/css/auth.css">

    <!-- PFT Shared JavaScript -->
    <script src="assets/js/auth.js" defer></script>
</head>

<body>

<main class="auth-shell">

    <!-- LEFT PANEL: BRANDING -->
    <section class="brand-panel">

        <div class="brand-top">

            <a href="login.php" class="brand-logo">
                <img
                    src="assets/images/pftlogo.png"
                    alt="Personal Finance Tracker Logo"
                    class="pft-logo"
                >
            </a>

            <span class="micro-label">
                PERSONAL FINANCE TRACKER
            </span>

        </div>

        <div>

            <p class="eyebrow">
                <span class="status-dot"></span>
                YOUR FINANCIAL CONTROL CENTER
            </p>

            <h1 class="hero-title">
                Every peso.<br>
                <em>A clearer signal.</em>
            </h1>

            <p class="hero-description">
                Understand where your money goes.
                Record your income, monitor your expenses,
                and take control of your financial activity
                with a clearer view of your money.
            </p>

            <!-- Decorative Financial Signal -->
            <div
                class="signal-display"
                aria-hidden="true"
            >

                <div class="signal-heading">

                    <span>
                        FINANCIAL SIGNAL
                    </span>

                    <span>
                        VISUAL SYSTEM / 01
                    </span>

                </div>

                <div class="signal-bars">

                    <span style="--h:28%"></span>
                    <span style="--h:48%"></span>
                    <span style="--h:35%"></span>
                    <span style="--h:62%"></span>
                    <span style="--h:45%"></span>
                    <span style="--h:73%"></span>
                    <span style="--h:56%"></span>
                    <span style="--h:40%"></span>
                    <span style="--h:81%"></span>
                    <span style="--h:67%"></span>
                    <span style="--h:91%"></span>
                    <span style="--h:76%"></span>

                </div>

                <div class="signal-footer">

                    <span>
                        RECORD / ANALYZE / UNDERSTAND
                    </span>

                    <strong>
                        SYSTEM READY
                    </strong>

                </div>

            </div>

        </div>

        <div class="brand-bottom">

            <span>
                PFT &copy; 2026
            </span>

            <span>
                BUILT FOR BETTER MONEY HABITS
            </span>

        </div>

    </section>

    <!-- RIGHT PANEL: LOGIN -->
    <section class="form-panel">

        <div class="form-inner">

            <!-- Mobile Logo -->
            <div class="mobile-logo">

                <a href="login.php" class="brand-logo">
                    SIGNAL<span>/01</span>
                </a>

            </div>

            <!-- Form Heading -->
            <p class="section-tag">
                01 / ACCOUNT ACCESS
            </p>

            <h2 class="form-title">
                Welcome to <span>PFT.</span>
            </h2>

            <p class="form-subtitle">
                Your money, in focus. Sign in to access
                your financial dashboard.
            </p>

            <!-- Registration Success -->
            <?php if (!empty($success)): ?>

                <div
                    class="alert alert-success"
                    role="status"
                >
                    <?= htmlspecialchars(
                        $success,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </div>

            <?php endif; ?>

            <!-- Login Error -->
            <?php if (!empty($error)): ?>

                <div
                    class="alert alert-error"
                    role="alert"
                >
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </div>

            <?php endif; ?>

            <!-- Suspended Account Appeal -->
            <?php if ($isSuspended): ?>

                <p class="switch-page">
                    Think your suspension was a mistake?

                    <a href="account-appeal.php">
                        Request Account Review
                    </a>
                </p>

            <?php endif; ?>

            <!-- Login Form -->
            <form
                method="POST"
                action="login.php"
                data-auth-form
            >

                <!-- CSRF Token -->
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        $_SESSION["login_csrf_token"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

                <!-- Email Address -->
                <div class="field">

                    <label for="email">
                        EMAIL ADDRESS
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="name@example.com"
                        value="<?= htmlspecialchars(
                            $email,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                        autocomplete="email"
                        maxlength="255"
                        required
                    >

                </div>

                <!-- Password -->
                <div class="field">

                    <div class="field-header">

                        <label for="password">
                            PASSWORD
                        </label>

                        <button
                            type="button"
                            class="toggle-password"
                            data-toggle-password="password"
                            aria-pressed="false"
                        >
                            SHOW
                        </button>

                    </div>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >

                </div>

                <!-- Login Button -->
                <button
                    type="submit"
                    class="primary-button"
                    data-submit-button
                    data-loading-text="SIGNING IN..."
                >
                    ENTER DASHBOARD &#8599;
                </button>

            </form>

            <!-- Registration Link -->
            <p class="switch-page">

                New to PFT?

                <a href="register.php">
                    Create an account
                </a>

            </p>

            <!-- Form Footer -->
            <p class="form-footer">
                SECURE SIGN-IN
                &bull;
                PERSONAL FINANCE TRACKER
            </p>

        </div>

    </section>

</main>

</body>
</html>
