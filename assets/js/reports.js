
// =========================================
// PFT REPORTS & ANALYTICS
// =========================================

document.addEventListener("DOMContentLoaded", () => {

    const get = id => document.getElementById(id);

    const money = new Intl.NumberFormat("en-PH", {
        style: "currency",
        currency: "PHP"
    });

    const percentFormat = new Intl.NumberFormat("en-PH", {
        maximumFractionDigits: 1,
        minimumFractionDigits: 1
    });

    const chartColors = [
        "#e5ff68",
        "#76cdb5",
        "#8ca6f3",
        "#ffb86e",
        "#dc9df2",
        "#f48e93",
        "#98aa91",
        "#e6c46b"
    ];

    let trendChart = null;
    let categoryChart = null;
    let lastReport = null;
    let activeController = null;


    // =====================================
    // 1. THEME MANAGEMENT
    // =====================================

    let savedTheme = "dark";

    try {
        savedTheme =
            localStorage.getItem("pft-theme") || "dark";
    } catch (error) {
        // Default to dark mode
    }

    function setTheme(theme) {

        document.documentElement.dataset.theme = theme;

        const isDark = theme === "dark";

        get("themeToggle").textContent =
            isDark ? "☀ Light mode" : "☾ Dark mode";

        get("themeToggle").setAttribute(
            "aria-pressed",
            String(isDark)
        );
    }

    setTheme(savedTheme === "light" ? "light" : "dark");

    get("themeToggle").addEventListener("click", () => {

        const oldTheme =
            document.documentElement.dataset.theme;

        const newTheme =
            oldTheme === "dark" ? "light" : "dark";

        setTheme(newTheme);

        try {
            localStorage.setItem("pft-theme", newTheme);
        } catch (error) {
            // Theme still works for this page
        }

        if (lastReport) {
            renderCharts(lastReport);
        }
    });


    // =====================================
    // 2. HELPERS
    // =====================================

    function formatMoney(value) {
        return money.format(Number(value) || 0);
    }

    function formatPercent(value) {
        return percentFormat.format(Number(value)) + "%";
    }

    function chartTextColor() {
        return getComputedStyle(document.documentElement)
            .getPropertyValue("--muted")
            .trim();
    }

    function localDateString(date) {

        const year = date.getFullYear();

        const month = String(
            date.getMonth() + 1
        ).padStart(2, "0");

        const day = String(
            date.getDate()
        ).padStart(2, "0");

        return `${year}-${month}-${day}`;
    }

    function dateLabel(value) {

        const date = new Date(`${value}T12:00:00`);

        return date.toLocaleDateString("en-PH", {
            year: "numeric",
            month: "short",
            day: "numeric"
        });
    }

    function createElement(tag, className, text) {

        const element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        if (text !== undefined) {
            element.textContent = text;
        }

        return element;
    }

    function setStatus(message, error = false) {

        const status = get("reportStatus");

        status.textContent = message;
        status.className = "report-status";

        if (error) {
            status.classList.add("error");
        }
    }

    function destroyCharts() {

        if (trendChart) {
            trendChart.destroy();
            trendChart = null;
        }

        if (categoryChart) {
            categoryChart.destroy();
            categoryChart = null;
        }
    }


    // =====================================
    // 3. DATE RANGE PRESETS
    // =====================================

    function applyPreset(preset) {

        if (preset === "custom") {
            return;
        }

        const now = new Date();

        const year = now.getFullYear();
        const month = now.getMonth();

        let start;
        let end;

        switch (preset) {

            case "last-month":

                start = new Date(
                    year,
                    month - 1,
                    1
                );

                end = new Date(
                    year,
                    month,
                    0
                );

                break;

            case "last-90":

                end = new Date(
                    year,
                    month,
                    now.getDate()
                );

                start = new Date(end);
                start.setDate(start.getDate() - 89);

                break;

            case "this-year":

                start = new Date(year, 0, 1);

                end = new Date(
                    year,
                    month,
                    now.getDate()
                );

                break;

            case "this-month":
            default:

                start = new Date(
                    year,
                    month,
                    1
                );

                end = new Date(
                    year,
                    month,
                    now.getDate()
                );

                break;
        }

        get("startDate").value = localDateString(start);
        get("endDate").value = localDateString(end);
    }

    get("presetRange").addEventListener(
        "change",
        () => {
            applyPreset(get("presetRange").value);
        }
    );

    // Manual date editing makes the range custom
    ["startDate", "endDate"].forEach(id => {

        get(id).addEventListener("change", () => {
            get("presetRange").value = "custom";
        });
    });


    // =====================================
    // 4. SUMMARY CARDS
    // =====================================

    function renderSummary(summary) {

        get("reportIncome").textContent =
            formatMoney(summary.income);

        get("reportExpenses").textContent =
            formatMoney(summary.expenses);

        get("reportNet").textContent =
            formatMoney(summary.net);

        const savingsValue = get("savingsRate");

        if (summary.savings_rate === null) {

            savingsValue.textContent = "—";

            get("savingsDescription").textContent =
                "Unavailable when no income is recorded.";

        } else {

            savingsValue.textContent =
                formatPercent(summary.savings_rate);

            get("savingsDescription").textContent =
                "Percentage of income remaining.";
        }
    }


    // =====================================
    // 5. SPENDING INSIGHTS
    // =====================================

    function renderInsights(data) {

        const insights = data.insights;
        const summary = data.summary;

        // Top spending category
        const top = insights.top_category;

        if (top) {

            get("topCategory").textContent =
                top.category;

            const share = summary.expenses > 0
                ? (Number(top.total) /
                    Number(summary.expenses)) * 100
                : 0;

            get("topCategoryDetails").textContent =
                `${formatMoney(top.total)} · ` +
                `${formatPercent(share)} of expenses`;

        } else {

            get("topCategory").textContent =
                "No expenses";

            get("topCategoryDetails").textContent =
                "No category data available.";
        }

        // Daily average spending
        get("dailyAverage").textContent =
            formatMoney(insights.daily_average);

        // Largest transaction classified as expense
        const largest = insights.largest_expense;

        if (largest) {

            get("largestExpense").textContent =
                formatMoney(largest.amount);

            get("largestExpenseDetails").textContent =
                `${largest.category} · ` +
                dateLabel(largest.date);

        } else {

            get("largestExpense").textContent = "—";

            get("largestExpenseDetails").textContent =
                "No expenses recorded.";
        }

        // Total transaction count
        const count = Number(summary.transaction_count);

        get("transactionCount").textContent =
            count.toLocaleString("en-PH");

        const expenseCount =
            Number(summary.expense_count);

        const incomeCount = count - expenseCount;

        get("transactionDetails").textContent =
            `${incomeCount} income · ` +
            `${expenseCount} expense entries`;
    }


    // =====================================
    // 6. CATEGORY PROGRESS BREAKDOWN
    // =====================================

    function renderCategoryBreakdown(data) {

        const container = get("categoryBreakdown");

        container.replaceChildren();

        const categories = data.categories;

        if (!categories.length) {

            container.append(
                createElement(
                    "p",
                    "breakdown-empty",
                    "Your spending breakdown will appear here."
                )
            );

            return;
        }

        const totalExpenses =
            Number(data.summary.expenses);

        categories.forEach((category, index) => {

            const share = totalExpenses > 0
                ? (Number(category.total) /
                    totalExpenses) * 100
                : 0;

            const item = createElement(
                "div",
                "breakdown-item"
            );

            const top = createElement(
                "div",
                "breakdown-top"
            );

            const name = createElement(
                "span",
                "breakdown-name",
                category.category
            );

            const amount = createElement(
                "span",
                "breakdown-amount",
                formatMoney(category.total)
            );

            top.append(name, amount);

            const track = createElement(
                "div",
                "breakdown-track"
            );

            const fill = createElement(
                "div",
                "breakdown-fill"
            );

            fill.style.width =
                `${Math.max(0, Math.min(100, share))}%`;

            fill.style.backgroundColor =
                chartColors[index % chartColors.length];

            track.append(fill);

            const bottom = createElement(
                "div",
                "breakdown-bottom"
            );

            bottom.append(
                createElement(
                    "span",
                    "",
                    `${Number(category.count)} transactions`
                ),

                createElement(
                    "span",
                    "",
                    formatPercent(share)
                )
            );

            item.append(top, track, bottom);
            container.append(item);
        });
    }


    // =====================================
    // 7. CASH FLOW AND CATEGORY CHARTS
    // =====================================

    function renderCharts(data) {

        destroyCharts();

        const trendCanvas = get("reportTrendChart");
        const categoryCanvas = get("reportCategoryChart");

        const trendEmpty = get("trendEmpty");
        const categoryEmpty = get("categoryEmpty");

        const chartAvailable =
            typeof Chart !== "undefined";

        const hasTrend = data.trend.some(row =>
            Number(row.income) > 0 ||
            Number(row.expenses) > 0
        );

        const hasCategories =
            data.categories.length > 0;

        trendCanvas.hidden =
            !chartAvailable || !hasTrend;

        categoryCanvas.hidden =
            !chartAvailable || !hasCategories;

        trendEmpty.hidden =
            chartAvailable && hasTrend;

        categoryEmpty.hidden =
            chartAvailable && hasCategories;

        get("trendRangeCaption").textContent =
            data.trend_type === "daily"
                ? "DAILY TREND"
                : "MONTHLY TREND";

        if (!chartAvailable) {

            trendEmpty.textContent =
                "Chart library unavailable. " +
                "Financial data is still shown below.";

            categoryEmpty.textContent =
                "Chart library unavailable.";

            return;
        }

        trendEmpty.textContent =
            "No financial activity for this period.";

        categoryEmpty.textContent =
            "No expenses for this period.";

        const textColor = chartTextColor();

        // Cash flow bar chart
        if (hasTrend) {

            trendChart = new Chart(trendCanvas, {

                type: "bar",

                data: {

                    labels: data.trend.map(
                        row => row.label
                    ),

                    datasets: [

                        {
                            label: "Income",

                            data: data.trend.map(
                                row => Number(row.income)
                            ),

                            backgroundColor: "#a5e6ae",

                            borderRadius: 4
                        },

                        {
                            label: "Expenses",

                            data: data.trend.map(
                                row => Number(row.expenses)
                            ),

                            backgroundColor: "#ff9797",

                            borderRadius: 4
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
                                color: textColor
                            }
                        },

                        tooltip: {

                            callbacks: {

                                label: context =>
                                    context.dataset.label +
                                    ": " +
                                    formatMoney(
                                        context.parsed.y
                                    )
                            }
                        }
                    },

                    scales: {

                        x: {

                            ticks: {
                                color: textColor,
                                autoSkip: true,
                                maxTicksLimit: 8,
                                maxRotation: 0
                            },

                            grid: {
                                display: false
                            }
                        },

                        y: {

                            beginAtZero: true,

                            ticks: {
                                color: textColor,

                                callback: value =>
                                    "₱" +
                                    Number(value)
                                        .toLocaleString("en-PH")
                            },

                            grid: {
                                color:
                                    "rgba(130,145,130,0.15)"
                            }
                        }
                    }
                }
            });
        }

        // Spending category doughnut chart
        if (hasCategories) {

            categoryChart = new Chart(categoryCanvas, {

                type: "doughnut",

                data: {

                    labels: data.categories.map(
                        category => category.category
                    ),

                    datasets: [{

                        data: data.categories.map(
                            category =>
                                Number(category.total)
                        ),

                        backgroundColor:
                            data.categories.map(
                                (_, index) =>
                                    chartColors[
                                        index % chartColors.length
                                    ]
                            ),

                        borderWidth: 0,
                        spacing: 3
                    }]
                },

                options: {

                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: "70%",

                    plugins: {

                        legend: {
                            display: false
                        },

                        tooltip: {

                            callbacks: {

                                label: context =>
                                    context.label +
                                    ": " +
                                    formatMoney(
                                        context.parsed
                                    )
                            }
                        }
                    }
                }
            });
        }
    }


    // =====================================
    // 8. RECENT TRANSACTIONS
    // =====================================

    function renderTransactions(transactions) {

        const tbody = get("reportTransactions");

        tbody.replaceChildren();

        if (transactions.length === 0) {

            const row = document.createElement("tr");

            const cell = createElement(
                "td",
                "table-empty",
                "No transactions in this reporting period."
            );

            cell.colSpan = 5;

            row.append(cell);
            tbody.append(row);

            return;
        }

        transactions.forEach(transaction => {

            const row = document.createElement("tr");

            row.append(
                createElement(
                    "td",
                    "",
                    dateLabel(transaction.transaction_date)
                ),

                createElement(
                    "td",
                    "",
                    transaction.category
                ),

                createElement(
                    "td",
                    "",
                    transaction.description || "—"
                )
            );

            const typeCell =
                document.createElement("td");

            const pill = createElement(
                "span",
                "type-pill " + transaction.type,
                transaction.type === "income"
                    ? "Income"
                    : "Expense"
            );

            typeCell.append(pill);
            row.append(typeCell);

            const amountCell = createElement(
                "td",
                transaction.type === "income"
                    ? "income-color"
                    : "expense-color",

                (transaction.type === "income"
                    ? "+"
                    : "−") +
                    formatMoney(transaction.amount)
            );

            row.append(amountCell);
            tbody.append(row);
        });
    }


    // =====================================
    // 9. LOAD REPORT FROM PHP
    // =====================================

    async function loadReport() {

        const form = get("reportFilterForm");

        if (!form.reportValidity()) {
            return;
        }

        const start = get("startDate").value;
        const end = get("endDate").value;

        if (!start || !end) {

            setStatus(
                "Please select both report dates.",
                true
            );

            return;
        }

        if (start > end) {

            setStatus(
                "Start date cannot be after end date.",
                true
            );

            return;
        }

        // Check inclusive date span.
        const startParts = start.split("-").map(Number);
        const endParts = end.split("-").map(Number);

        const startUTC = Date.UTC(
            startParts[0],
            startParts[1] - 1,
            startParts[2]
        );

        const endUTC = Date.UTC(
            endParts[0],
            endParts[1] - 1,
            endParts[2]
        );

        const dayCount =
            Math.round(
                (endUTC - startUTC) / 86400000
            ) + 1;

        if (dayCount > 731) {

            setStatus(
                "Select a period of two years or less.",
                true
            );

            return;
        }

        if (activeController) {
            activeController.abort();
        }

        const controller = new AbortController();
        activeController = controller;

        lastReport = null;

        get("exportCsv").disabled = true;
        get("applyReport").disabled = true;

        setStatus("Generating your financial report...");

        try {

            const params = new URLSearchParams({
                start,
                end
            });

            const response = await fetch(
                "api/reports.php?" + params.toString(),
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
                    data.error ||
                    "Unable to generate report."
                );
            }

            lastReport = data;

            renderSummary(data.summary);
            renderInsights(data);
            renderCategoryBreakdown(data);
            renderCharts(data);
            renderTransactions(data.recent);

            get("exportCsv").disabled = false;

            setStatus(
                `Showing ${dateLabel(data.start)} to ` +
                `${dateLabel(data.end)} · ` +
                `${data.summary.transaction_count} transactions`
            );

        } catch (error) {

            if (error.name === "AbortError") {
                return;
            }

            console.error(
                "Reports loading error:",
                error
            );

            setStatus(
                "Could not load report: " +
                error.message +
                " Previously displayed figures may be from " +
                "an earlier date range.",
                true
            );

        } finally {

            if (activeController === controller) {
                activeController = null;
                get("applyReport").disabled = false;
            }
        }
    }


    // =====================================
    // 10. APPLY FILTER
    // =====================================

    get("reportFilterForm").addEventListener(
        "submit",
        event => {

            event.preventDefault();
            loadReport();
        }
    );


    // =====================================
    // 11. CSV EXPORT
    // =====================================

    get("exportCsv").addEventListener(
        "click",
        () => {

            if (!lastReport) {
                return;
            }

            const params = new URLSearchParams({
                start: lastReport.start,
                end: lastReport.end,
                format: "csv"
            });

            // Exports the exact date range
            // from the most recent successful report.
            window.location.href =
                "api/reports.php?" + params.toString();
        }
    );


    // =====================================
    // 12. INITIAL LOAD
    // =====================================

    loadReport();

});
