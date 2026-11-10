// =========================================
// PFT TRANSACTION MANAGEMENT
// WITH LIVE SEARCH, FILTERING AND SORTING
// =========================================

document.addEventListener("DOMContentLoaded", () => {

    const get = id => document.getElementById(id);

    const currency = new Intl.NumberFormat("en-PH", {
        style: "currency",
        currency: "PHP"
    });

    const csrfToken = document.querySelector(
        'meta[name="csrf-token"]'
    ).content;

    let categories = [];
    let transactions = [];
    let editingId = null;
    let activeRequest = null;


    // =====================================
    // 1. DARK / LIGHT MODE
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
        const button = get("themeToggle");

        button.textContent = isDark
            ? "☀ Light mode"
            : "☾ Dark mode";

        button.setAttribute(
            "aria-pressed",
            String(isDark)
        );
    }

    setTheme(savedTheme === "light" ? "light" : "dark");

    get("themeToggle").addEventListener("click", () => {

        const current =
            document.documentElement.dataset.theme;

        const next =
            current === "dark" ? "light" : "dark";

        setTheme(next);

        try {
            localStorage.setItem("pft-theme", next);
        } catch (error) {
            // Theme still works for this page
        }
    });


    // =====================================
    // 2. HELPERS
    // =====================================

    function formatMoney(amount) {
        return currency.format(Number(amount) || 0);
    }

    function todayLocal() {

        const now = new Date();

        const year = now.getFullYear();

        const month = String(
            now.getMonth() + 1
        ).padStart(2, "0");

        const day = String(
            now.getDate()
        ).padStart(2, "0");

        return `${year}-${month}-${day}`;
    }

    function displayDate(value) {

        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return value;
        }

        const date = new Date(`${value}T12:00:00`);

        return date.toLocaleDateString("en-PH", {
            month: "short",
            day: "numeric",
            year: "numeric"
        });
    }

    function makeCell(text, className = "") {

        const cell = document.createElement("td");

        cell.textContent = text;

        if (className) {
            cell.className = className;
        }

        return cell;
    }

    function showMessage(message, type = "") {

        const target = get("formMessage");

        target.textContent = message;
        target.className = "form-message";

        if (type) {
            target.classList.add(type);
        }
    }

    function emptyTable(message) {

        const tbody = get("transactionRows");

        tbody.replaceChildren();

        const row = document.createElement("tr");
        const cell = makeCell(message, "table-empty");

        cell.colSpan = 6;

        row.append(cell);
        tbody.append(row);
    }


    // =====================================
    // 3. CATEGORY OPTIONS FOR ENTRY FORM
    // =====================================

    function updateCategories(preferredCategory = null) {

        const select = get("transactionCategory");
        const type = get("transactionType").value;

        const available = categories.filter(
            category => category.type === type
        );

        select.replaceChildren();

        if (!available.length) {

            const option =
                document.createElement("option");

            option.value = "";
            option.textContent =
                "No categories available";

            select.append(option);
            select.disabled = true;

            return;
        }

        select.disabled = false;

        available.forEach(category => {

            const option =
                document.createElement("option");

            option.value =
                String(category.category_id);

            option.textContent = category.name;

            select.append(option);
        });

        if (
            preferredCategory !== null &&
            available.some(
                category =>
                    String(category.category_id) ===
                    String(preferredCategory)
            )
        ) {
            select.value = String(preferredCategory);
        }
    }

    get("transactionType").addEventListener(
        "change",
        () => updateCategories()
    );


    // =====================================
    // 4. CATEGORY OPTIONS FOR LIVE FILTER
    // =====================================

    function updateFilterCategoryOptions() {

        const select = get("filterCategory");
        const previous = select.value;

        const selectedType = get("filterType").value;

        select.replaceChildren();

        const allOption =
            document.createElement("option");

        allOption.value = "all";
        allOption.textContent = "All Categories";

        select.append(allOption);

        const available = categories.filter(
            category =>
                selectedType === "all" ||
                category.type === selectedType
        );

        available.forEach(category => {

            const option =
                document.createElement("option");

            option.value =
                String(category.category_id);

            option.textContent =
                `${category.name} (${category.type})`;

            select.append(option);
        });

        // Keep selected category only if
        // it is still valid for the type filter.
        const exists = available.some(
            category =>
                String(category.category_id) === previous
        );

        select.value = exists ? previous : "all";
    }


    // =====================================
    // 5. FILTER CURRENT MONTH'S RECORDS
    // =====================================

    function getFilteredTransactions() {

        const keyword = get("filterSearch")
            .value.trim().toLocaleLowerCase();

        const selectedType = get("filterType").value;
        const selectedCategory = get("filterCategory").value;

        const minInput = get("filterMinAmount");
        const maxInput = get("filterMaxAmount");

        const minText = minInput.value;
        const maxText = maxInput.value;

        const minAmount = minText === ""
            ? null
            : Number(minText);

        const maxAmount = maxText === ""
            ? null
            : Number(maxText);

        const notice = get("filterNotice");

        notice.textContent = "";
        notice.className = "filter-notice";

        // Validate optional amount filters
        if (
            !minInput.validity.valid ||
            !maxInput.validity.valid ||
            (minAmount !== null &&
                (!Number.isFinite(minAmount) ||
                 minAmount < 0)) ||
            (maxAmount !== null &&
                (!Number.isFinite(maxAmount) ||
                 maxAmount < 0))
        ) {

            notice.textContent =
                "Enter valid, non-negative amount limits.";

            notice.classList.add("error");

            return [];
        }

        if (
            minAmount !== null &&
            maxAmount !== null &&
            minAmount > maxAmount
        ) {

            notice.textContent =
                "Minimum amount cannot exceed maximum amount.";

            notice.classList.add("error");

            return [];
        }

        // Filter using existing monthly records
        const filtered = transactions.filter(transaction => {

            const description = String(
                transaction.description || ""
            ).toLocaleLowerCase();

            const category = String(
                transaction.category_name || ""
            ).toLocaleLowerCase();

            const amount = Number(transaction.amount);

            // Search text
            if (
                keyword &&
                !description.includes(keyword) &&
                !category.includes(keyword)
            ) {
                return false;
            }

            // Transaction type
            if (
                selectedType !== "all" &&
                transaction.type !== selectedType
            ) {
                return false;
            }

            // Category
            if (
                selectedCategory !== "all" &&
                String(transaction.category_id) !==
                    selectedCategory
            ) {
                return false;
            }

            // Minimum amount
            if (
                minAmount !== null &&
                amount < minAmount
            ) {
                return false;
            }

            // Maximum amount
            if (
                maxAmount !== null &&
                amount > maxAmount
            ) {
                return false;
            }

            return true;
        });

        // =================================
        // SORT MATCHING RECORDS
        // =================================

        const sortBy = get("filterSort").value;

        const newestFirst = (a, b) => {

            const dateCompare =
                b.transaction_date.localeCompare(
                    a.transaction_date
                );

            if (dateCompare !== 0) {
                return dateCompare;
            }

            return Number(b.transaction_id) -
                Number(a.transaction_id);
        };

        filtered.sort((a, b) => {

            switch (sortBy) {

                case "oldest":
                    return -newestFirst(a, b);

                case "highest":
                    return (
                        Number(b.amount) -
                        Number(a.amount)
                    ) || newestFirst(a, b);

                case "lowest":
                    return (
                        Number(a.amount) -
                        Number(b.amount)
                    ) || newestFirst(a, b);

                case "category":
                    return String(
                        a.category_name
                    ).localeCompare(
                        String(b.category_name)
                    ) || newestFirst(a, b);

                case "newest":
                default:
                    return newestFirst(a, b);
            }
        });

        return filtered;
    }


    // =====================================
    // 6. FILTERED SUMMARY TOTALS
    // =====================================

    function renderFilteredTotals(records) {

        const income = records
            .filter(item => item.type === "income")
            .reduce(
                (total, item) =>
                    total + Number(item.amount),
                0
            );

        const expenses = records
            .filter(item => item.type === "expense")
            .reduce(
                (total, item) =>
                    total + Number(item.amount),
                0
            );

        const net = income - expenses;

        get("filteredIncome").textContent =
            formatMoney(income);

        get("filteredExpenses").textContent =
            formatMoney(expenses);

        get("filteredNet").textContent =
            formatMoney(net);
    }


    // =====================================
    // 7. RENDER FILTERED TRANSACTIONS
    // =====================================

    function renderTransactions() {

        const filtered = getFilteredTransactions();

        const tbody = get("transactionRows");

        tbody.replaceChildren();

        const matches = filtered.length;
        const total = transactions.length;

        get("transactionCount").textContent =
            `Showing ${matches} of ${total} transactions`;

        renderFilteredTotals(filtered);

        if (matches === 0) {

            emptyTable(
                total === 0
                    ? "No transactions recorded for this month."
                    : "No matching transactions. Try changing or clearing your filters."
            );

            return;
        }

        filtered.forEach(transaction => {

            const row = document.createElement("tr");

            // Date
            row.append(
                makeCell(
                    displayDate(
                        transaction.transaction_date
                    )
                )
            );

            // Category
            row.append(
                makeCell(
                    transaction.category_name
                )
            );

            // Description
            row.append(
                makeCell(
                    transaction.description || "—"
                )
            );

            // Type
            const typeCell =
                document.createElement("td");

            const pill =
                document.createElement("span");

            pill.className =
                "type-pill " + transaction.type;

            pill.textContent =
                transaction.type === "income"
                    ? "Income"
                    : "Expense";

            typeCell.append(pill);
            row.append(typeCell);

            // Amount
            const amountCell = makeCell(
                (transaction.type === "income"
                    ? "+"
                    : "−") +
                    formatMoney(transaction.amount),

                transaction.type === "income"
                    ? "income-color"
                    : "expense-color"
            );

            row.append(amountCell);

            // Action buttons
            const actionCell =
                document.createElement("td");

            const actionGroup =
                document.createElement("div");

            actionGroup.className = "action-group";

            const editButton =
                document.createElement("button");

            editButton.type = "button";
            editButton.className = "action-button";
            editButton.textContent = "Edit";

            editButton.dataset.edit =
                transaction.transaction_id;

            const deleteButton =
                document.createElement("button");

            deleteButton.type = "button";

            deleteButton.className =
                "action-button delete";

            deleteButton.textContent = "Delete";

            deleteButton.dataset.delete =
                transaction.transaction_id;

            actionGroup.append(
                editButton,
                deleteButton
            );

            actionCell.append(actionGroup);
            row.append(actionCell);

            tbody.append(row);
        });
    }


    // =====================================
    // 8. INSTANT FILTER EVENTS
    // =====================================

    // Runs immediately as the user types.
    get("filterSearch").addEventListener(
        "input",
        renderTransactions
    );

    get("filterMinAmount").addEventListener(
        "input",
        renderTransactions
    );

    get("filterMaxAmount").addEventListener(
        "input",
        renderTransactions
    );

    get("filterType").addEventListener(
        "change",
        () => {

            updateFilterCategoryOptions();
            renderTransactions();
        }
    );

    get("filterCategory").addEventListener(
        "change",
        renderTransactions
    );

    get("filterSort").addEventListener(
        "change",
        renderTransactions
    );

    function clearFilters(render = true) {

        get("filterSearch").value = "";
        get("filterType").value = "all";

        get("filterMinAmount").value = "";
        get("filterMaxAmount").value = "";

        get("filterSort").value = "newest";

        updateFilterCategoryOptions();

        if (render) {
            renderTransactions();
        }
    }

    get("clearFilters").addEventListener(
        "click",
        () => clearFilters()
    );


    // =====================================
    // 9. LOAD MONTHLY TRANSACTIONS
    // =====================================

    async function loadTransactions() {

        if (activeRequest) {
            activeRequest.abort();
        }

        const controller = new AbortController();

        activeRequest = controller;

        get("historyStatus").textContent =
            "Loading...";

        get("transactionCount").textContent =
            "Loading transactions...";

        emptyTable("Loading transactions...");

        const month = get("monthInput").value;

        try {

            const response = await fetch(
                "api/transactions.php?month=" +
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
                    data.error ||
                    "Unable to load transactions."
                );
            }

            categories = data.categories;
            transactions = data.transactions;

            updateCategories();
            updateFilterCategoryOptions();

            renderTransactions();

            get("historyStatus").textContent =
                "UP TO DATE";

            return true;

        } catch (error) {

            if (error.name === "AbortError") {
                return false;
            }

            console.error(
                "Transaction loading error:",
                error
            );

            transactions = [];
            categories = [];

            get("historyStatus").textContent =
                "Unable to load records.";

            get("transactionCount").textContent =
                "Transactions unavailable";

            emptyTable(
                "Unable to load transactions. Please refresh."
            );

            return false;

        } finally {

            if (activeRequest === controller) {
                activeRequest = null;
            }
        }
    }


    // =====================================
    // 10. SEND CREATE, UPDATE OR DELETE
    // =====================================

    async function sendAction(payload) {

        const response = await fetch(
            "api/transactions.php",
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
                data.error ||
                "Transaction could not be saved."
            );
        }

        return data;
    }


    // =====================================
    // 11. RESET ENTRY FORM
    // =====================================

    function resetForm() {

        editingId = null;

        get("transactionForm").reset();

        get("transactionDate").value =
            todayLocal();

        get("transactionType").value =
            "expense";

        updateCategories();

        get("formHeading").textContent =
            "Add transaction";

        get("saveTransaction").textContent =
            "ADD TRANSACTION ↗";

        get("cancelEdit").hidden = true;
    }

    get("cancelEdit").addEventListener(
        "click",
        () => {

            resetForm();
            showMessage("");
        }
    );


    // =====================================
    // 12. CREATE OR EDIT TRANSACTION
    // =====================================

    get("transactionForm").addEventListener(
        "submit",
        async event => {

            event.preventDefault();

            const form = event.currentTarget;

            if (!form.reportValidity()) {
                return;
            }

            const categoryId =
                get("transactionCategory").value;

            if (!categoryId) {

                showMessage(
                    "Please select a valid category.",
                    "error"
                );

                return;
            }

            const transactionDate =
                get("transactionDate").value;

            const payload = {

                action: editingId === null
                    ? "create"
                    : "update",

                type:
                    get("transactionType").value,

                category_id:
                    Number(categoryId),

                amount:
                    get("transactionAmount").value,

                transaction_date:
                    transactionDate,

                description:
                    get("transactionDescription")
                        .value.trim()
            };

            if (editingId !== null) {
                payload.transaction_id = editingId;
            }

            const saveButton =
                get("saveTransaction");

            saveButton.disabled = true;

            saveButton.textContent =
                "SAVING...";

            get("monthInput").disabled = true;

            showMessage("");

            try {

                const result =
                    await sendAction(payload);

                // Switch to the month containing
                // the created/updated transaction.
                get("monthInput").value =
                    transactionDate.substring(0, 7);

                resetForm();

                // Clear filters so the saved record
                // cannot be hidden accidentally.
                clearFilters(false);

                const loaded =
                    await loadTransactions();

                showMessage(
                    loaded
                        ? result.message
                        : result.message +
                          " Refresh to view updated records.",
                    "success"
                );

            } catch (error) {

                console.error(
                    "Transaction save failed:",
                    error
                );

                showMessage(
                    error.message,
                    "error"
                );

            } finally {

                saveButton.disabled = false;
                get("monthInput").disabled = false;

                saveButton.textContent =
                    editingId === null
                        ? "ADD TRANSACTION ↗"
                        : "SAVE CHANGES ↗";
            }
        }
    );


    // =====================================
    // 13. EDIT / DELETE TABLE ACTIONS
    // =====================================

    get("transactionRows").addEventListener(
        "click",
        async event => {

            const button =
                event.target.closest("button");

            if (!button || button.disabled) {
                return;
            }

            // EDIT
            if (button.dataset.edit) {

                const transaction =
                    transactions.find(
                        item =>
                            String(item.transaction_id) ===
                            button.dataset.edit
                    );

                if (!transaction) {
                    return;
                }

                editingId =
                    Number(transaction.transaction_id);

                get("transactionType").value =
                    transaction.type;

                updateCategories(
                    transaction.category_id
                );

                get("transactionAmount").value =
                    transaction.amount;

                get("transactionDate").value =
                    transaction.transaction_date;

                get("transactionDescription").value =
                    transaction.description || "";

                get("formHeading").textContent =
                    "Edit transaction";

                get("saveTransaction").textContent =
                    "SAVE CHANGES ↗";

                get("cancelEdit").hidden = false;

                showMessage("");

                get("transactionForm").scrollIntoView({
                    behavior: "smooth",
                    block: "center"
                });

                get("transactionAmount").focus({
                    preventScroll: true
                });

                return;
            }

            // DELETE
            if (button.dataset.delete) {

                const id =
                    Number(button.dataset.delete);

                const confirmed = window.confirm(
                    "Delete this transaction? " +
                    "This action cannot be undone."
                );

                if (!confirmed) {
                    return;
                }

                button.disabled = true;

                try {

                    const result = await sendAction({
                        action: "delete",
                        transaction_id: id
                    });

                    if (editingId === id) {
                        resetForm();
                    }

                    const loaded =
                        await loadTransactions();

                    showMessage(
                        loaded
                            ? result.message
                            : result.message +
                              " Refresh to view updated records.",
                        "success"
                    );

                } catch (error) {

                    console.error(
                        "Transaction delete failed:",
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
    // 14. MONTH SELECTION
    // =====================================

    get("monthInput").addEventListener(
        "change",
        () => {

            resetForm();
            showMessage("");
            loadTransactions();
        }
    );


    // =====================================
    // 15. FIRST LOAD
    // =====================================

    get("transactionDate").value =
        todayLocal();

    loadTransactions();

});
