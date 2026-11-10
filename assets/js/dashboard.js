// =========================================
// PFT DASHBOARD
// =========================================

const element = id => document.getElementById(id);

const peso = new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP"
});

const colors = [
    "#e5ff68",
    "#76cdb5",
    "#8ca6f3",
    "#ffb86e",
    "#dc9df2",
    "#f48e93",
    "#98aa91",
    "#e6c46b"
];

let cashFlowChart = null;
let spendingChart = null;
let latestData = null;
let currentRequest = null;


// =========================================
// 1. DARK / LIGHT THEME
// =========================================

const themeButton = element("themeToggle");

let savedTheme = "dark";

try {
    savedTheme = localStorage.getItem("pft-theme") || "dark";
} catch (error) {
    // Local storage may be unavailable
}

setTheme(savedTheme === "light" ? "light" : "dark");

themeButton.addEventListener("click", () => {

    const currentTheme =
        document.documentElement.dataset.theme;

    const nextTheme =
        currentTheme === "dark" ? "light" : "dark";

    setTheme(nextTheme);

    try {
        localStorage.setItem("pft-theme", nextTheme);
    } catch (error) {
        // Theme still works for this page
    }

    if (latestData) {
        displayCharts(latestData);
    }
});

function setTheme(theme) {

    document.documentElement.dataset.theme = theme;

    const isDark = theme === "dark";

    themeButton.textContent = isDark
        ? "☀ Light mode"
        : "☾ Dark mode";

    themeButton.setAttribute(
        "aria-pressed",
        String(isDark)
    );
}


// =========================================
// 2. CURRENCY AND CHART HELPERS
// =========================================

function formatMoney(value) {
    return peso.format(Number(value) || 0);
}

function chartTextColor() {
    return getComputedStyle(document.documentElement)
        .getPropertyValue("--muted")
        .trim();
}

function destroyCharts() {

    if (cashFlowChart) {
        cashFlowChart.destroy();
        cashFlowChart = null;
    }

    if (spendingChart) {
        spendingChart.destroy();
        spendingChart = null;
    }
}


// =========================================
// 3. UPDATE FINANCIAL CARDS
// =========================================

function displaySummary(summary) {

    element("totalBalance").textContent =
        formatMoney(summary.balance);

    element("monthlyIncome").textContent =
        formatMoney(summary.income);

    element("monthlyExpenses").textContent =
        formatMoney(summary.expenses);

    element("monthlyNet").textContent =
        formatMoney(summary.net);
}


// =========================================
// 4. DISPLAY CHARTS
// =========================================

function displayCharts(data) {

    destroyCharts();

    const cashCanvas = element("cashFlowChart");
    const spendingCanvas = element("spendingChart");

    const cashEmpty = element("cashFlowEmpty");
    const spendingEmpty = element("spendingEmpty");

    const legend = element("categoryLegend");
    legend.replaceChildren();

    const hasCashFlow = data.trend.some(row =>
        Number(row.income) > 0 ||
        Number(row.expenses) > 0
    );

    const hasSpending = data.categories.length > 0;

    const chartAvailable = typeof Chart !== "undefined";

    cashCanvas.hidden = !hasCashFlow || !chartAvailable;
    spendingCanvas.hidden = !hasSpending || !chartAvailable;

    cashEmpty.hidden = hasCashFlow && chartAvailable;
    spendingEmpty.hidden = hasSpending && chartAvailable;

    if (!chartAvailable) {
        cashEmpty.textContent = "Chart library unavailable.";
        spendingEmpty.textContent = "Chart library unavailable.";
        return;
    }

    cashEmpty.textContent =
        "No income or expenses recorded for this period.";

    spendingEmpty.textContent =
        "No expenses recorded for this month.";

    const labelColor = chartTextColor();

    // Six-month income and expense chart
    if (hasCashFlow) {

        cashFlowChart = new Chart(cashCanvas, {

            type: "bar",

            data: {
                labels: data.trend.map(row => row.label),

                datasets: [
                    {
                        label: "Income",
                        data: data.trend.map(row =>
                            Number(row.income)
                        ),
                        backgroundColor: "#a5e6ae",
                        borderRadius: 5
                    },
                    {
                        label: "Expenses",
                        data: data.trend.map(row =>
                            Number(row.expenses)
                        ),
                        backgroundColor: "#ff9797",
                        borderRadius: 5
                    }
                ]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,

                plugins: {
                    legend: {
                        position: "bottom",
                        labels: {
                            color: labelColor
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: context =>
                                `${context.dataset.label}: ` +
                                formatMoney(context.parsed.y)
                        }
                    }
                },

                scales: {
                    x: {
                        ticks: {
                            color: labelColor
                        },
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: labelColor,
                            callback: value =>
                                "₱" + Number(value).toLocaleString("en-PH")
                        },
                        grid: {
                            color: "rgba(130,145,130,0.15)"
                        }
                    }
                }
            }
        });
    }

    // Spending breakdown doughnut chart
    if (hasSpending) {

        spendingChart = new Chart(spendingCanvas, {

            type: "doughnut",

            data: {
                labels: data.categories.map(row =>
                    row.category
                ),

                datasets: [{
                    data: data.categories.map(row =>
                        Number(row.total)
                    ),

                    backgroundColor: data.categories.map(
                        (_, index) => colors[index % colors.length]
                    ),

                    borderWidth: 0,
                    spacing: 3
                }]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: "72%",

                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: context =>
                                `${context.label}: ` +
                                formatMoney(context.parsed)
                        }
                    }
                }
            }
        });

        // Accessible category breakdown below chart
        data.categories.forEach((row, index) => {

            const item = document.createElement("span");
            item.className = "legend-item";

            const marker = document.createElement("span");
            marker.className = "legend-color";

            marker.style.backgroundColor =
                colors[index % colors.length];

            const label = document.createElement("span");

            label.textContent =
                `${row.category}: ${formatMoney(row.total)}`;

            item.append(marker, label);

            legend.append(item);
        });
    }
}


// =========================================
// 5. DISPLAY RECENT TRANSACTIONS
// =========================================

function displayTransactions(transactions) {

    const tableBody = element("recentTransactions");

    tableBody.replaceChildren();

    if (!transactions.length) {

        const row = document.createElement("tr");
        const cell = document.createElement("td");

        cell.colSpan = 5;
        cell.className = "table-empty";

        cell.textContent =
            "No transactions for the selected month yet.";

        row.append(cell);
        tableBody.append(row);

        return;
    }

    transactions.forEach(transaction => {

        const row = document.createElement("tr");

        // Date
        const dateCell = document.createElement("td");
        dateCell.textContent = transaction.transaction_date;

        // Category
        const categoryCell = document.createElement("td");
        categoryCell.textContent = transaction.category;

        // Description
        const descriptionCell = document.createElement("td");
        descriptionCell.textContent =
            transaction.description || "—";

        // Type
        const typeCell = document.createElement("td");

        const pill = document.createElement("span");

        pill.className =
            "type-pill " + transaction.type;

        pill.textContent =
            transaction.type === "income"
                ? "Income"
                : "Expense";

        typeCell.append(pill);

        // Amount
        const amountCell = document.createElement("td");

        amountCell.className =
            transaction.type === "income"
                ? "income-color"
                : "expense-color";

        amountCell.textContent =
            (transaction.type === "income" ? "+" : "−") +
            formatMoney(transaction.amount);

        row.append(
            dateCell,
            categoryCell,
            descriptionCell,
            typeCell,
            amountCell
        );

        tableBody.append(row);
    });
}


// =========================================
// 6. FETCH DASHBOARD DATA
// =========================================

async function loadDashboard() {

    const month = element("monthInput").value;

    if (currentRequest) {
        currentRequest.abort();
    }

    const controller = new AbortController();
    currentRequest = controller;

    const status = element("dashboardStatus");

    status.textContent = "Loading financial data...";

    try {

        const response = await fetch(
            `api/dashboard.php?month=${encodeURIComponent(month)}`,
            {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                signal: controller.signal
            }
        );

        if (response.status === 401) {
            window.location.href = "login.php";
            return;
        }

        const data = await response.json();

        if (!response.ok) {
            throw new Error(
                data.error || "Unable to load dashboard."
            );
        }

        latestData = data;

        displaySummary(data.summary);
        displayCharts(data);
        displayTransactions(data.recent);

        status.textContent = "";

    } catch (error) {

        if (error.name === "AbortError") {
            return;
        }

        status.textContent =
            "Unable to load your financial information. " +
            "Please refresh the page.";

        console.error("Dashboard error:", error);

    } finally {

        if (currentRequest === controller) {
            currentRequest = null;
        }
    }
}


// Reload when user selects a month
element("monthInput").addEventListener(
    "change",
    loadDashboard
);

// First load
loadDashboard();
