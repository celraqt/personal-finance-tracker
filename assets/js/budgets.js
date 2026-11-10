// =========================================
// PFT BUDGET MANAGEMENT
// =========================================

document.addEventListener("DOMContentLoaded", () => {

    const get = id => document.getElementById(id);

    const csrfToken = document.querySelector(
        'meta[name="csrf-token"]'
    ).content;

    const peso = new Intl.NumberFormat("en-PH", {
        style: "currency",
        currency: "PHP"
    });

    let budgetData = null;
    let editingId = null;
    let activeRequest = null;


    // =====================================
    // 1. THEME
    // =====================================

    let savedTheme = "dark";

    try {
        savedTheme =
            localStorage.getItem("pft-theme") || "dark";
    } catch (error) {
        // Use dark theme
    }

    function setTheme(theme) {

        document.documentElement.dataset.theme = theme;

        const dark = theme === "dark";

        get("themeToggle").textContent = dark
            ? "☀ Light mode"
            : "☾ Dark mode";

        get("themeToggle").setAttribute(
            "aria-pressed",
            String(dark)
        );
    }

    setTheme(savedTheme === "light" ? "light" : "dark");

    get("themeToggle").addEventListener("click", () => {

        const next =
            document.documentElement.dataset.theme === "dark"
                ? "light"
                : "dark";

        setTheme(next);

        try {
            localStorage.setItem("pft-theme", next);
        } catch (error) {
            // Ignore unavailable storage
        }
    });


    // =====================================
    // 2. HELPERS
    // =====================================

    function money(value) {
        return peso.format(Number(value) || 0);
    }

    function make(tag, className = "", text = "") {

        const element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        element.textContent = text;

        return element;
    }

    function showMessage(message, type = "") {

        const target = get("budgetFormMessage");

        target.textContent = message;
        target.className = "budget-form-message";

        if (type) {
            target.classList.add(type);
        }
    }

    function showStatus(message, error = false) {

        const target = get("budgetStatus");

        target.textContent = message;
        target.className = error
            ? "budget-status error"
            : "budget-status";
    }

    function emptyMessage(container, message) {

        container.replaceChildren(
            make("p", "budget-empty", message)
        );
    }


    // =====================================
    // 3. EXPENSE CATEGORY OPTIONS
    // =====================================

    function updateCategoryOptions(selectedId = null) {

        const select = get("budgetCategory");

        select.replaceChildren();

        if (!budgetData) {
            return;
        }

        const usedCategoryIds = new Set(
            budgetData.budgets.map(
                budget => Number(budget.category_id)
            )
        );

        const available = budgetData.categories.filter(
            category => {
                const id = Number(category.category_id);

                return !usedCategoryIds.has(id) ||
                    id === Number(selectedId);
            }
        );

        if (available.length === 0) {

            const option = make(
                "option",
                "",
                "All categories have budgets"
            );

            option.value = "";

            select.append(option);
            select.disabled = true;

            return;
        }

        select.disabled = editingId !== null;

        available.forEach(category => {

            const option = make(
                "option",
                "",
                category.name
            );

            option.value = String(category.category_id);

            select.append(option);
        });

        if (selectedId !== null) {
            select.value = String(selectedId);
        }
    }


    // =====================================
    // 4. RESET CREATE / EDIT FORM
    // =====================================

    function resetForm() {

        editingId = null;

        get("budgetForm").reset();

        get("budgetFormTitle").textContent =
            "Create budget";

        get("saveBudget").textContent =
            "CREATE BUDGET ↗";

        get("cancelBudgetEdit").hidden = true;

        get("budgetCategory").disabled = false;

        updateCategoryOptions();
    }

    get("cancelBudgetEdit").addEventListener(
        "click",
        () => {
            resetForm();
            showMessage("");
        }
    );


    // =====================================
    // 5. FINANCIAL SUMMARY
    // =====================================

    function renderSummary(summary) {

        get("totalBudget").textContent =
            money(summary.total_limit);

        get("budgetSpent").textContent =
            money(summary.total_spent);

        get("budgetRemaining").textContent =
            money(summary.remaining);

        get("budgetAlertCount").textContent =
            String(summary.alert_count);

        const unbudgeted = Number(
            summary.unbudgeted_spending
        );

        get("unbudgetedNote").textContent =
            unbudgeted > 0
                ? "Note: " + money(unbudgeted) +
                  " of this month's expenses belong to " +
                  "categories without a budget."
                : "Budget totals include only categories " +
                  "with a budget for the selected month.";
    }


    // =====================================
    // 6. SPENDING NOTIFICATIONS
    // =====================================

    function renderAlerts(alerts) {

        const container = get("budgetAlerts");

        container.replaceChildren();

        get("alertCountLabel").textContent =
            `${alerts.length} ACTIVE`;

        if (alerts.length === 0) {

            emptyMessage(
                container,
                "No active spending alerts. " +
                "Your budgeted categories are below 80%."
            );

            return;
        }

        alerts.forEach(alert => {

            const row = make(
                "div",
                "budget-alert " + alert.status
            );

            const icon = make(
                "span",
                "budget-alert-icon",
                alert.status === "danger" ? "⚠" : "♧"
            );

            icon.setAttribute("aria-hidden", "true");

            const content = make(
                "div",
                "budget-alert-content"
            );

            const title = make(
                "strong",
                "budget-alert-title",
                alert.status === "danger"
                    ? "Budget limit reached"
                    : "Approaching budget limit"
            );

            const message = make(
                "span",
                "budget-alert-message",
                alert.message
            );

            content.append(title, message);
            row.append(icon, content);

            container.append(row);
        });
    }


    // =====================================
    // 7. CATEGORY BUDGET CARDS
    // =====================================

    function renderBudgets(budgets) {

        const container = get("budgetList");

        container.replaceChildren();

        get("budgetCount").textContent =
            `${budgets.length} ` +
            (budgets.length === 1 ? "BUDGET" : "BUDGETS");

        if (!budgets.length) {

            emptyMessage(
                container,
                "No budgets set for this month. " +
                "Create your first budget using the form."
            );

            return;
        }

        budgets.forEach(budget => {

            const card = make(
                "article",
                "budget-item " + budget.status
            );

            const header = make(
                "div",
                "budget-item-header"
            );

            const left = make("div");

            left.append(
                make(
                    "div",
                    "budget-category",
                    budget.category_name
                ),
                make(
                    "div",
                    "budget-subtitle",
                    money(budget.spent) + " spent"
                )
            );

            const amount = make(
                "span",
                "budget-amount",
                money(budget.amount)
            );

            header.append(left, amount);

            // Progress track
            const track = make("div", "budget-track");

            track.setAttribute(
                "role",
                "progressbar"
            );

            track.setAttribute(
                "aria-label",
                budget.category_name + " budget usage"
            );

            track.setAttribute("aria-valuemin", "0");
            track.setAttribute("aria-valuemax", "100");

            track.setAttribute(
                "aria-valuenow",
                String(Math.min(
                    100,
                    Math.max(0, Number(budget.percentage))
                ))
            );

            const fill = make("div", "budget-fill");

            fill.style.width =
                Math.min(
                    100,
                    Math.max(0, Number(budget.percentage))
                ) + "%";

            track.append(fill);

            // Remaining money and percentage
            const footer = make(
                "div",
                "budget-progress-footer"
            );

            const remaining = Number(budget.remaining);

            const remainingText = remaining >= 0
                ? money(remaining) + " remaining"
                : money(Math.abs(remaining)) + " over budget";

            const remainingElement = make(
                "span",
                "",
                remainingText
            );

            const percentElement = make(
                "strong",
                "budget-percent",
                Number(budget.percentage)
                    .toFixed(1) + "% used"
            );

            footer.append(
                remainingElement,
                percentElement
            );

            // Edit and delete
            const actions = make(
                "div",
                "budget-actions"
            );

            const editButton = make(
                "button",
                "budget-action",
                "Edit"
            );

            editButton.type = "button";
            editButton.dataset.action = "edit";
            editButton.dataset.id = budget.budget_id;

            const deleteButton = make(
                "button",
                "budget-action delete",
                "Delete"
            );

            deleteButton.type = "button";
            deleteButton.dataset.action = "delete";
            deleteButton.dataset.id = budget.budget_id;

            actions.append(editButton, deleteButton);

            card.append(
                header,
                track,
                footer,
                actions
            );

            container.append(card);
        });
    }


    // =====================================
    // 8. FETCH BUDGETS
    // =====================================

    async function loadBudgets() {

        if (activeRequest) {
            activeRequest.abort();
        }

        const controller = new AbortController();

        activeRequest = controller;

        const month = get("budgetMonth").value;

        showStatus("Loading budget information...");

        try {

            const response = await fetch(
                "api/budgets.php?month=" +
                encodeURIComponent(month),
                {
                    method: "GET",
                    credentials: "same-origin",
                    cache: "no-store",
                    signal: controller.signal
                }
            );

            if (response.status === 401) {
                window.location.href = "login.php";
                return false;
            }

            const data = await response.json();

            if (!response.ok) {
                throw new Error(
                    data.error || "Unable to load budgets."
                );
            }

            budgetData = data;

            renderSummary(data.summary);
            renderAlerts(data.alerts);
            renderBudgets(data.budgets);

            updateCategoryOptions();

            showStatus("");

            return true;

        } catch (error) {

            if (error.name === "AbortError") {
                return false;
            }

            console.error("Budget loading error:", error);

            budgetData = null;

            get("totalBudget").textContent = "—";
            get("budgetSpent").textContent = "—";
            get("budgetRemaining").textContent = "—";
            get("budgetAlertCount").textContent = "—";

            emptyMessage(
                get("budgetList"),
                "Unable to load budgets."
            );

            emptyMessage(
                get("budgetAlerts"),
                "Unable to load spending alerts."
            );

            get("budgetCategory").replaceChildren();
            get("budgetCategory").disabled = true;

            showStatus(
                "Unable to load budgets: " + error.message,
                true
            );

            return false;

        } finally {

            if (activeRequest === controller) {
                activeRequest = null;
            }
        }
    }


    // =====================================
    // 9. CREATE / UPDATE / DELETE REQUEST
    // =====================================

    async function sendAction(payload) {

        const response = await fetch(
            "api/budgets.php",
            {
                method: "POST",
                credentials: "same-origin",

                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": csrfToken
                },

                body: JSON.stringify(payload)
            }
        );

        if (response.status === 401) {
            window.location.href = "login.php";
            throw new Error("Session expired.");
        }

        const data = await response.json();

        if (!response.ok) {
            throw new Error(
                data.error || "Budget operation failed."
            );
        }

        return data;
    }


    // =====================================
    // 10. CREATE OR UPDATE BUDGET
    // =====================================

    get("budgetForm").addEventListener(
        "submit",
        async event => {

            event.preventDefault();

            const form = event.currentTarget;

            if (!form.reportValidity()) {
                return;
            }

            const categoryId =
                get("budgetCategory").value;

            const amount =
                get("budgetAmount").value;

            if (editingId === null && !categoryId) {

                showMessage(
                    "Select an available expense category.",
                    "error"
                );

                return;
            }

            const payload = {
                action: editingId === null
                    ? "create"
                    : "update",

                amount: amount
            };

            if (editingId === null) {

                payload.category_id = Number(categoryId);

                payload.month =
                    get("budgetMonth").value;

            } else {

                payload.budget_id = editingId;
            }

            const saveButton = get("saveBudget");

            saveButton.disabled = true;
            get("budgetMonth").disabled = true;

            saveButton.textContent = "SAVING...";

            showMessage("");

            try {

                const result = await sendAction(payload);

                resetForm();

                const loaded = await loadBudgets();

                if (loaded) {
                    showMessage(result.message, "success");
                } else {
                    showMessage(
                        result.message +
                        " Refresh to load the latest budgets.",
                        "success"
                    );
                }

            } catch (error) {

                console.error("Budget save failed:", error);

                showMessage(error.message, "error");

            } finally {

                saveButton.disabled = false;
                get("budgetMonth").disabled = false;

                saveButton.textContent =
                    editingId === null
                        ? "CREATE BUDGET ↗"
                        : "SAVE CHANGES ↗";
            }
        }
    );


    // =====================================
    // 11. EDIT OR DELETE BUDGET
    // =====================================

    get("budgetList").addEventListener(
        "click",
        async event => {

            const button = event.target.closest(
                "button[data-action]"
            );

            if (!button || !budgetData) return;

            const budget = budgetData.budgets.find(
                item =>
                    String(item.budget_id) ===
                    String(button.dataset.id)
            );

            if (!budget) return;

            // Edit
            if (button.dataset.action === "edit") {

                editingId = Number(budget.budget_id);

                updateCategoryOptions(
                    budget.category_id
                );

                get("budgetAmount").value =
                    Number(budget.amount).toFixed(2);

                get("budgetFormTitle").textContent =
                    "Edit budget";

                get("saveBudget").textContent =
                    "SAVE CHANGES ↗";

                get("cancelBudgetEdit").hidden = false;

                showMessage("");

                get("budgetForm").scrollIntoView({
                    behavior: "smooth",
                    block: "center"
                });

                get("budgetAmount").focus({
                    preventScroll: true
                });

                return;
            }

            // Delete
            if (button.dataset.action === "delete") {

                const confirmed = window.confirm(
                    "Delete the monthly budget for " +
                    budget.category_name + "? " +
                    "Your existing transactions will not be deleted."
                );

                if (!confirmed) return;

                button.disabled = true;

                try {

                    const result = await sendAction({
                        action: "delete",
                        budget_id: Number(budget.budget_id)
                    });

                    resetForm();

                    const loaded = await loadBudgets();

                    if (loaded) {
                        showMessage(
                            result.message,
                            "success"
                        );
                    }

                } catch (error) {

                    console.error(
                        "Budget delete failed:",
                        error
                    );

                    showMessage(
                        error.message,
                        "error"
                    );

                    button.disabled = false;
                }
            }
        }
    );


    // =====================================
    // 12. MONTH SELECTION
    // =====================================

    get("budgetMonth").addEventListener(
        "change",
        () => {

            resetForm();
            showMessage("");
            loadBudgets();
        }
    );


    // =====================================
    // 13. FIRST LOAD
    // =====================================

    loadBudgets();

});
