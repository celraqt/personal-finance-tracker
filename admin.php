<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
session_start();

if (empty($_SESSION["admin_action_csrf"])) {
    $_SESSION["admin_action_csrf"] = bin2hex(random_bytes(32));
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

try {
    $pdo = require __DIR__ . '/config/database.php';

    // Check admin privileges directly in the database.
    $check = $pdo->prepare(
        'SELECT role, account_status
        FROM users
        WHERE user_id = ?
        LIMIT 1'
    );
    $check->execute([(int) $_SESSION['user_id']]);
    $currentUser = $check->fetch(PDO::FETCH_ASSOC);

    if (
        !$currentUser ||
        $currentUser["role"] !== "admin" ||
        $currentUser["account_status"] !== "active"
    ) {
        http_response_code(403);
        exit("403 Forbidden: administrator access only.");
    }

    $totalUsers = (int) $pdo->query(
        'SELECT COUNT(*) FROM users'
    )->fetchColumn();

    $newThisMonth = (int) $pdo->query(
        'SELECT COUNT(*) FROM users
         WHERE created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")'
    )->fetchColumn();

    $active30Days = (int) $pdo->query(
        'SELECT COUNT(*) FROM users
         WHERE last_login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
    )->fetchColumn();

    $users = $pdo->query(
        'SELECT user_id, full_name, email,
                role, account_status, created_at, last_login_at
         FROM users
         ORDER BY created_at DESC, user_id DESC
         LIMIT 200'
    )->fetchAll(PDO::FETCH_ASSOC);

    $pendingAppealCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM account_appeals WHERE status = 'pending'"
    )->fetchColumn();

    $appeals = $pdo->query(
        "SELECT ap.appeal_id, ap.message, ap.status,
                ap.created_at, ap.reviewed_at,
                u.full_name AS user_name, u.email AS user_email,
                r.email AS reviewer_email
         FROM account_appeals ap
         JOIN users u ON u.user_id = ap.user_id
         LEFT JOIN users r ON r.user_id = ap.reviewed_by
         ORDER BY (ap.status = 'pending') DESC,
                  ap.created_at DESC, ap.appeal_id DESC
         LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);

    $auditLogs = $pdo->query(
        "SELECT l.action, l.created_at,
                a.email AS admin_email,
                u.email AS target_email
         FROM admin_audit_logs l
         JOIN users a ON a.user_id = l.admin_user_id
         JOIN users u ON u.user_id = l.target_user_id
         ORDER BY l.log_id DESC
         LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('PFT admin error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load the admin dashboard.');
}

function e(?string $value): string {
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function displayDate(?string $value): string {
    return $value
        ? date('M j, Y g:i A', strtotime($value))
        : 'Never recorded';
}



?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PFT / Admin Dashboard</title>

<style>
:root {
    color-scheme: dark;
    --bg: #101310;
    --panel: #1b201c;
    --line: #343d34;
    --lime: #c4ff5e;
    --muted: #a3afa1;
    --text: #f3f7ef;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font: 15px/1.5 Arial, sans-serif;
}

.container {
    max-width: 1150px;
    margin: auto;
    padding: 26px 18px 54px;
}

header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 35px;
}

.brand {
    font-weight: 900;
    letter-spacing: .15em;
    color: var(--lime);
}

nav {
    display: flex;
    gap: 14px;
}

a {
    color: var(--text);
    text-decoration: none;
}

a:hover { color: var(--lime); }

.eyebrow {
    color: var(--lime);
    font-size: 12px;
    letter-spacing: .17em;
    text-transform: uppercase;
}

h1 {
    font-size: clamp(27px, 4vw, 43px);
    margin: 8px 0;
}

.intro {
    color: var(--muted);
    margin-bottom: 24px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin: 26px 0;
}

.card, .panel {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 15px;
}

.card { padding: 22px; }

.card small {
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .08em;
}

.number {
    font-size: 34px;
    font-weight: 800;
    margin-top: 8px;
    color: var(--lime);
}

.panel { padding: 22px; }

.toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 18px;
}

h2 {
    margin: 0;
    font-size: 20px;
}

input {
    width: min(100%, 320px);
    padding: 12px 14px;
    border-radius: 9px;
    border: 1px solid #667160;
    background: #111610;
    color: var(--text);
}

input:focus-visible,
a:focus-visible {
    outline: 2px solid var(--lime);
    outline-offset: 3px;
}

.scroll { overflow-x: auto; }

table {
    border-collapse: collapse;
    width: 100%;
    min-width: 750px;
    text-align: left;
}

th, td {
    padding: 13px 12px;
    border-bottom: 1px solid var(--line);
}

th {
    color: var(--muted);
    font-size: 12px;
    text-transform: uppercase;
}

td { overflow-wrap: anywhere; }

.tag {
    color: var(--lime);
    background: #293820;
    padding: 5px 9px;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 700;
}

.regular {
    color: var(--muted);
    background: #2a302a;
}

.note {
    font-size: 13px;
    color: var(--muted);
    margin-top: 16px;
}

tr[hidden] { display: none; }

@media(max-width: 650px) {
    .stats { grid-template-columns: 1fr; }
    .panel { padding: 14px; }
}
.user-action {
    background: #293820;
    color: var(--lime);
    border: 1px solid var(--line);
    padding: 8px 12px;
    border-radius: 8px;
    font: inherit;
    cursor: pointer;
}

.user-action:hover {
    background: #394c2b;
}

.user-action:focus-visible {
    outline: 2px solid var(--lime);
    outline-offset: 2px;
}
/* Account appeals */
.appeal-list { display:grid; gap:14px; margin-top:18px; }
.appeal-card { border:1px solid var(--line); border-radius:12px;
               background:#141914; padding:16px; }
.appeal-card p { white-space:pre-wrap; overflow-wrap:anywhere; }
.appeal-meta { color:var(--muted); font-size:13px; }
.appeal-actions { display:flex; flex-wrap:wrap; gap:9px; margin-top:12px; }
.appeal-actions form { display:inline; }
.appeal-actions button { background:var(--lime); color:#111610; border:0;
    border-radius:8px; padding:9px 12px; font-weight:700; cursor:pointer; }
.appeal-actions .reject { background:#3e453f; color:#f3f7ef; }
.appeal-actions button:focus-visible { outline:2px solid var(--lime); outline-offset:2px; }
.admin-notice { margin:14px 0; color:var(--lime); }
</style>
</head>

<body>
<div class="container">

<header>
    <div class="brand">PFT / ADMIN</div>
    <nav>

    <form action="logout.php" method="POST">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($_SESSION['csrf_token']) ?>"
        >

        <button
            type="submit"
            style="background:none; border:0; color:inherit;
                font:inherit; cursor:pointer;"
        >
            Logout
        </button>
    </form>

    </nav>
</header>

<div class="eyebrow">System Administration / Overview</div>

<h1>User Management</h1>

<p class="intro">
    Monitor registrations and account sign-ins
    without accessing private financial transactions.
</p>

<section class="stats">
    <div class="card">
        <small>Registered Users</small>
        <div class="number"><?= $totalUsers ?></div>
    </div>

    <div class="card">
        <small>Joined This Month</small>
        <div class="number"><?= $newThisMonth ?></div>
    </div>

    <div class="card">
        <small>Logged In / Last 30 Days</small>
        <div class="number"><?= $active30Days ?></div>
    </div>

</section>





<section class="panel" id="account-appeals" style="margin-bottom:18px;">
    <div class="toolbar">
        <h2>Account Appeals (<?= $pendingAppealCount ?> pending)</h2>
        <span class="tag">ADMIN NOTIFICATIONS</span>
    </div>

    <?php if (($_GET['appeal_result'] ?? '') === 'approved'): ?>
        <p class="admin-notice" role="status">Appeal approved; account reactivated.</p>
    <?php elseif (($_GET['appeal_result'] ?? '') === 'rejected'): ?>
        <p class="admin-notice" role="status">Appeal rejected; account remains suspended.</p>
    <?php endif; ?>

    <?php if (empty($appeals)): ?>
        <p class="appeal-meta">No account appeals have been submitted.</p>
    <?php else: ?>
        <div class="appeal-list">
            <?php foreach ($appeals as $appeal): ?>
                <article class="appeal-card">
                    <strong><?= e($appeal['user_name']) ?></strong>
                    <div class="appeal-meta">
                        <?= e($appeal['user_email']) ?> ·
                        <?= e(displayDate($appeal['created_at'])) ?> ·
                        <?= e(ucfirst($appeal['status'])) ?>
                    </div>
                    <p><?= e($appeal['message']) ?></p>
                    <?php if ($appeal['status'] === 'pending'): ?>
                        <div class="appeal-actions">
                            <form action="admin-appeal-action.php" method="POST"
                                  onsubmit="return confirm('Approve and reactivate this account?');">
                                <input type="hidden" name="csrf_token"
                                       value="<?= e($_SESSION['admin_action_csrf']) ?>">
                                <input type="hidden" name="appeal_id"
                                       value="<?= (int) $appeal['appeal_id'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit">Approve &amp; Reactivate</button>
                            </form>
                            <form action="admin-appeal-action.php" method="POST"
                                  onsubmit="return confirm('Reject this appeal?');">
                                <input type="hidden" name="csrf_token"
                                       value="<?= e($_SESSION['admin_action_csrf']) ?>">
                                <input type="hidden" name="appeal_id"
                                       value="<?= (int) $appeal['appeal_id'] ?>">
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="reject">Reject</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="appeal-meta">
                            Reviewed <?= e(displayDate($appeal['reviewed_at'])) ?>
                            <?php if (!empty($appeal['reviewer_email'])): ?>
                                by <?= e($appeal['reviewer_email']) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="panel">

    <div class="toolbar">
        <h2>Registered Accounts</h2>
        <input
            id="search"
            type="search"
            placeholder="Search name or email"
            aria-label="Search users"
        >
    </div>

    <div class="scroll">
        <table id="users-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Last Login</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= (int) $user['user_id'] ?></td>
                        <td><?= e($user['full_name']) ?></td>
                        <td><?= e($user['email']) ?></td>

                        <td>
                            <span class="tag <?= $user['role'] === 'admin' ? '' : 'regular' ?>">
                                <?= e(ucfirst($user['role'])) ?>
                            </span>
                        </td>

                        <td><?= e(ucfirst($user['account_status'])) ?></td>
                        <td><?= e(displayDate($user['created_at'])) ?></td>
                        <td><?= e(displayDate($user['last_login_at'])) ?></td>

                        <td>
                            <?php if ($user['role'] === 'user'): ?>
                                <form
                                    action="admin-action.php"
                                    method="POST"
                                    onsubmit="return confirm('Are you sure you want to change this account status?');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($_SESSION['admin_action_csrf']) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="target_id"
                                        value="<?= (int) $user['user_id'] ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="<?= $user['account_status'] === 'active' ? 'suspend' : 'reactivate' ?>"
                                    >
                                    <button type="submit" class="user-action">
                                        <?= $user['account_status'] === 'active'
                                            ? 'Suspend'
                                            : 'Reactivate' ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="note">
        Showing up to 200 newest accounts.
        Last login is updated by the login tracking code.
    </p>

</section>

<section class="panel" style="margin-top: 18px;">
    <h2>Recent Admin Actions</h2>

    <div class="scroll">
        <table>
            <thead>
                <tr>
                    <th>Administrator</th>
                    <th>Account</th>
                    <th>Action</th>
                    <th>Date</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($auditLogs as $log): ?>
                    <tr>
                        <td><?= e($log["admin_email"]) ?></td>
                        <td><?= e($log["target_email"]) ?></td>
                        <td><?= e(ucfirst($log["action"])) ?></td>
                        <td><?= e(displayDate($log["created_at"])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
</div>

<script>
const search = document.getElementById('search');

const rows = [
    ...document.querySelectorAll('#users-table tbody tr')
];

search.addEventListener('input', () => {
    const query = search.value.trim().toLowerCase();

    rows.forEach(row => {
        row.hidden = !row.textContent
            .toLowerCase()
            .includes(query);
    });
});
</script>

</body>
</html>
