
<?php
session_start();

// Redirect users who are already logged in
if (isset($_SESSION["user_id"])) {
    header("Location: dashboard.php");
    exit;
}

// CSRF protection
if (empty($_SESSION["register_csrf_token"])) {
    $_SESSION["register_csrf_token"] = bin2hex(random_bytes(32));
}

$error = "";
$name = "";
$email = "";

// Process registration form
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $nameInput = $_POST["full_name"] ?? "";
    $emailInput = $_POST["email"] ?? "";
    $passwordInput = $_POST["password"] ?? "";
    $confirmInput = $_POST["confirm_password"] ?? "";
    $tokenInput = $_POST["csrf_token"] ?? "";

    $name = is_string($nameInput)
        ? trim($nameInput)
        : "";

    $email = is_string($emailInput)
        ? trim($emailInput)
        : "";

    $password = is_string($passwordInput)
        ? $passwordInput
        : "";

    $confirm = is_string($confirmInput)
        ? $confirmInput
        : "";

    $token = is_string($tokenInput)
        ? $tokenInput
        : "";

        // Validate full name
        // Require at least two name parts
        // Allow letters, spaces, periods,
        // apostrophes, and hyphens
        // Do not allow numbers

        $validName =
            preg_match('/^.{1,100}$/us', $name) === 1
            &&
            preg_match(
                "/^\p{L}[\p{L}\p{M}.'-]*(?: +\p{L}[\p{L}\p{M}.'-]*)+$/u",
                $name
            ) === 1;

    // Password requirements
    $hasMinLength = preg_match(
        '/^.{12,}$/us',
        $password
    ) === 1;

    $hasUppercase = preg_match(
        '/\p{Lu}/u',
        $password
    ) === 1;

    $hasLowercase = preg_match(
        '/\p{Ll}/u',
        $password
    ) === 1;

    $hasNumber = preg_match(
        '/\p{N}/u',
        $password
    ) === 1;

    $hasSymbol = preg_match(
        '/[^\p{L}\p{N}\s]/u',
        $password
    ) === 1;

    $strongPassword =
        $hasMinLength &&
        $hasUppercase &&
        $hasLowercase &&
        $hasNumber &&
        $hasSymbol;

    // Server-side validation
    if (!hash_equals(
        $_SESSION["register_csrf_token"],
        $token
    )) {

        $error = "Invalid form request. Please refresh and try again.";

        $_SESSION["register_csrf_token"] =
            bin2hex(random_bytes(32));

    } elseif (
        $name === "" ||
        $email === "" ||
        $password === "" ||
        $confirm === ""
    ) {

        $error = "Please complete all required fields.";

    } elseif (!$validName) {

        $error = "Full name must contain 2–100 characters. "
               . "Only letters, spaces, apostrophes, "
               . "hyphens, and periods are allowed. "
               . "Numbers are not allowed.";

    } elseif (
        strlen($email) > 255 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {

        $error = "Please enter a valid email address.";

    } elseif (strlen($password) > 72) {

        $error = "Password must not exceed 72 bytes.";

    } elseif (!$strongPassword) {

        $error = "Use at least 12 characters with "
               . "an uppercase letter, lowercase letter, "
               . "number, and special character.";

    } elseif ($password !== $confirm) {

        $error = "Passwords do not match.";

    } else {

        try {

            $pdo = require __DIR__ . "/config/database.php";

            // Hash password securely
            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $pdo->beginTransaction();

            // Register user
            $stmt = $pdo->prepare(
                "INSERT INTO users
                (full_name, email, password_hash)
                VALUES (?, ?, ?)"
            );

            $stmt->execute([
                $name,
                $email,
                $hashedPassword
            ]);

            $userId = $pdo->lastInsertId();

            // Default financial categories
            $categories = [
                ["Allowance", "income"],
                ["Salary", "income"],
                ["Other Income", "income"],
                ["Food", "expense"],
                ["Transportation", "expense"],
                ["School", "expense"],
                ["Bills", "expense"],
                ["Other Expense", "expense"]
            ];

            $stmt = $pdo->prepare(
                "INSERT INTO categories
                (user_id, name, type, is_default)
                VALUES (?, ?, ?, 1)"
            );

            foreach ($categories as [$category, $type]) {
                $stmt->execute([
                    $userId,
                    $category,
                    $type
                ]);
            }

            $pdo->commit();

            $_SESSION["register_success"] =
                "Registration successful! Please log in.";

            unset($_SESSION["register_csrf_token"]);

            header("Location: login.php");
            exit;

        } catch (PDOException $e) {

            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e->getCode() === "23000") {

                $error = "Email is already registered.";

            } else {

                error_log(
                    "Registration error: " . $e->getMessage()
                );

                $error = "Registration failed. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <meta name="theme-color" content="#111514">

    <title>Register | Personal Finance Tracker</title>

    <link rel="stylesheet" href="assets/css/auth.css">

    <script src="assets/js/auth.js" defer></script>
</head>

<body>

<main class="auth-shell">

    <!-- LEFT: BRANDING PANEL -->
    <section class="brand-panel">

        <div class="brand-top">

            <a href="login.php" class="brand-logo">
                <img
                    src="assets/images/pftlogo.png"
                    alt="Personal Finance Tracker"
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
                INITIALIZE YOUR FINANCIAL SPACE
            </p>

            <h1 class="hero-title">
                Take control.<br>
                <em>Start here.</em>
            </h1>

            <p class="hero-description">
                Create your personal financial space.
                Monitor your income, organize expenses,
                and build better financial habits.
            </p>

            <div class="signal-display" aria-hidden="true">

                <div class="signal-heading">
                    <span>SYSTEM INITIALIZATION</span>
                    <span>USER / 01</span>
                </div>

                <div class="signal-bars">
                    <span style="--h:23%"></span>
                    <span style="--h:35%"></span>
                    <span style="--h:31%"></span>
                    <span style="--h:48%"></span>
                    <span style="--h:56%"></span>
                    <span style="--h:44%"></span>
                    <span style="--h:64%"></span>
                    <span style="--h:58%"></span>
                    <span style="--h:72%"></span>
                    <span style="--h:81%"></span>
                    <span style="--h:90%"></span>
                    <span style="--h:100%"></span>
                </div>

                <div class="signal-footer">
                    <span>RECORD / ORGANIZE / ANALYZE</span>
                    <strong>READY</strong>
                </div>

            </div>
        </div>

        <div class="brand-bottom">
            <span>PFT &copy; 2026</span>
            <span>YOUR MONEY. IN FOCUS.</span>
        </div>

    </section>

    <!-- RIGHT: REGISTRATION FORM -->
    <section class="form-panel">

        <div class="form-inner">

            <div class="mobile-logo">
                <a href="login.php" class="brand-logo">
                    <img
                        src="assets/images/pftlogo.png"
                        alt="Personal Finance Tracker"
                        class="pft-logo"
                    >
                </a>
            </div>

            <p class="section-tag">
                02 / CREATE ACCOUNT
            </p>

            <h2 class="form-title">
                Get started<span>.</span>
            </h2>

            <p class="form-subtitle">
                Create your account to start managing
                your personal finances.
            </p>

            <!-- PHP Error Message -->
            <?php if (!empty($error)): ?>

                <div class="alert alert-error" role="alert">
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </div>

            <?php endif; ?>

            <form
                method="POST"
                action="register.php"
                data-auth-form
            >

                <!-- CSRF Token -->
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        $_SESSION["register_csrf_token"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

                <!-- Full Name -->
                <div class="field">

                    <label for="full_name">
                        FULL NAME
                    </label>

                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        placeholder="e.g. Juan Dela Cruz"
                        value="<?= htmlspecialchars(
                            $name,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                        minlength="2"
                        maxlength="100"
                        autocomplete="name"
                        aria-describedby="nameMessage"
                        required
                    >

                    <p
                        class="field-note"
                        id="nameMessage"
                        aria-live="polite"
                    >
                        Letters only. Spaces, hyphens,
                        apostrophes, and periods are allowed.
                        No numbers.
                    </p>

                </div>

                <!-- Email -->
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
                        maxlength="255"
                        autocomplete="email"
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
                        placeholder="Create a strong password"
                        minlength="12"
                        autocomplete="new-password"
                        aria-describedby="passwordRules strengthMessage"
                        required
                    >

                    <!-- Password Strength Bars -->
                    <div
                        class="strength-track"
                        id="strengthTrack"
                        data-level="0"
                        aria-hidden="true"
                    >
                        <span class="strength-bar"></span>
                        <span class="strength-bar"></span>
                        <span class="strength-bar"></span>
                        <span class="strength-bar"></span>
                    </div>

                    <p
                        class="field-note"
                        id="strengthMessage"
                        aria-live="polite"
                    >
                        Enter a strong password.
                    </p>

                    <!-- Live Password Requirements -->
                    <ul
                        class="password-rules"
                        id="passwordRules"
                    >
                        <li data-rule="length">
                            At least 12 characters
                        </li>

                        <li data-rule="upper">
                            One uppercase letter
                        </li>

                        <li data-rule="lower">
                            One lowercase letter
                        </li>

                        <li data-rule="number">
                            One number
                        </li>

                        <li data-rule="symbol">
                            One special character (@, #, !)
                        </li>

                    </ul>

                </div>

                <!-- Confirm Password -->
                <div class="field">

                    <div class="field-header">

                        <label for="confirm_password">
                            CONFIRM PASSWORD
                        </label>

                        <button
                            type="button"
                            class="toggle-password"
                            data-toggle-password="confirm_password"
                            aria-pressed="false"
                        >
                            SHOW
                        </button>

                    </div>

                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        placeholder="Re-enter your password"
                        minlength="12"
                        autocomplete="new-password"
                        aria-describedby="matchMessage"
                        required
                    >

                    <p
                        class="field-note"
                        id="matchMessage"
                        aria-live="polite"
                    ></p>

                </div>

                <!-- Register Button -->
                <button
                    type="submit"
                    class="primary-button"
                    data-submit-button
                    data-loading-text="CREATING ACCOUNT..."
                >
                    CREATE ACCOUNT &#8599;
                </button>

            </form>

            <p class="switch-page">
                Already have an account?
                <a href="login.php">Sign in</a>
            </p>

            <p class="form-footer">
                SECURE REGISTRATION
                &bull;
                PERSONAL FINANCE TRACKER
            </p>

        </div>

    </section>

</main>

</body>
</html>
