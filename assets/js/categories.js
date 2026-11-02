
// =========================================
// PFT CATEGORY MANAGEMENT
// =========================================

const get = id => document.getElementById(id);

const csrfToken = document.querySelector(
    'meta[name="csrf-token"]'
).content;

let categories = [];
let editingCategoryId = null;
let activeLoad = null;


// =========================================
// 1. DARK / LIGHT MODE
// =========================================

let savedTheme = "dark";

try {
    savedTheme =
        localStorage.getItem("pft-theme") || "dark";
} catch (error) {
    // Use dark mode when storage is unavailable
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

    const next = current === "dark"
        ? "light"
        : "dark";

    setTheme(next);

    try {
        localStorage.setItem("pft-theme", next);
    } catch (error) {
        // Theme still works until the page reloads
    }
});


// =========================================
// 2. HELPERS
// =========================================

function showMessage(message, type = "") {

    const target = get("categoryMessage");

    target.textContent = message;
    target.className = "category-message";

    if (type) {
        target.classList.add(type);
    }
}

function normalizeName(value) {

    return value.replace(/\s+/gu, " ").trim();
}

function validateCategoryName() {

    const input = get("categoryName");
    const hint = get("nameHint");

    const name = normalizeName(input.value);

    let warning = "";

    const allowed =
        /^[\p{L}\p{M}\p{N} .&'()\/-]+$/u.test(name);

    const hasLetter = /\p{L}/u.test(name);
    const length = Array.from(name).length;

    if (name === "") {

        warning = "";

    } else if (length < 2 || length > 50) {

        warning = "Use 2–50 characters.";

    } else if (!allowed || !hasLetter) {

        warning = "Category name contains invalid characters.";
    }

    input.setCustomValidity(warning);
    input.classList.toggle(
        "input-invalid",
        warning !== ""
    );

    if (warning) {

        hint.textContent = "⚠ " + warning;
        hint.className = "invalid";

    } else if (name) {

        hint.textContent = "✓ Valid category name.";
        hint.className = "valid";

    } else {

        hint.textContent =
            "Use a short, descriptive category name.";
        hint.className = "";
    }

    return name !== "" && warning === "";
}

get("categoryName").addEventListener(
    "input",
    validateCategoryName
);


// =========================================
// 3. CREATE CATEGORY ROW
// =========================================

function createCategoryRow(category) {

    const row = document.createElement("div");

    row.className =
        "category-item " + category.type;

    const isDefault =
        Number(category.is_default) === 1;

    const transactionCount =
        Number(category.transaction_count) || 0;

    const budgetCount =
        Number(category.budget_count) || 0;

    // Icon
    const icon = document.createElement("span");

    icon.className = "category-icon";
    icon.setAttribute("aria-hidden", "true");

    icon.textContent =
        category.type === "expense" ? "↗" : "↙";

    // Category name and details
    const info = document.createElement("div");
    info.className = "category-info";

    const name = document.createElement("span");
    name.className = "category-name";
    name.textContent = category.name;

    const meta = document.createElement("div");
    meta.className = "category-meta";

    const usage = document.createElement("span");

    usage.textContent =
        transactionCount +
        (transactionCount === 1
            ? " transaction"
            : " transactions");

    meta.append(usage);

    if (isDefault) {

        const defaultLabel = document.createElement("span");

        defaultLabel.className = "category-default";
        defaultLabel.textContent = "• BUILT-IN";

        meta.append(defaultLabel);
    }

    info.append(name, meta);

    row.append(icon, info);

    // Built-in categories are read-only
    if (!isDefault) {

        const actions = document.createElement("div");
        actions.className = "category-actions";

        const editButton = document.createElement("button");

        editButton.type = "button";
        editButton.className = "category-action";
        editButton.textContent = "Edit";

        editButton.dataset.action = "edit";
        editButton.dataset.id = category.category_id;

        const deleteButton =
            document.createElement("button");

        deleteButton.type = "button";
        deleteButton.className =
            "category-action delete";

        deleteButton.textContent = "Delete";

        deleteButton.dataset.action = "delete";
        deleteButton.dataset.id = category.category_id;

        if (transactionCount > 0 || budgetCount > 0) {

            deleteButton.disabled = true;

        deleteButton.title =
            "This category is used by transactions or monthly budgets.";
        }

        actions.append(editButton, deleteButton);
        row.append(actions);
    }

    return row;
}


// =========================================
// 4. DISPLAY CATEGORIES
// =========================================

function renderGroup(type, listId, countId) {

    const list = get(listId);
    const count = get(countId);

    const items = categories.filter(
        category => category.type === type
    );

    count.textContent =
        `${items.length} ` +
        (items.length === 1
            ? "CATEGORY"
            : "CATEGORIES");

    list.replaceChildren();

    if (items.length === 0) {

        const empty = document.createElement("p");

        empty.className = "category-empty";

        empty.textContent =
            type === "expense"
                ? "No expense categories yet."
                : "No income categories yet.";

        list.append(empty);

        return;
    }

    items.forEach(category => {
        list.append(createCategoryRow(category));
    });
}

function renderCategories() {

    renderGroup(
        "expense",
        "expenseCategoryList",
        "expenseCount"
    );

    renderGroup(
        "income",
        "incomeCategoryList",
        "incomeCount"
    );
}


// =========================================
// 5. FETCH CATEGORIES
// =========================================

async function loadCategories() {

    if (activeLoad) {
        activeLoad.abort();
    }

    const controller = new AbortController();
    activeLoad = controller;

    try {

        const response = await fetch(
            "api/categories.php",
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
                data.error || "Unable to load categories."
            );
        }

        categories = data.categories;
        renderCategories();

    } catch (error) {

        if (error.name === "AbortError") {
            return;
        }

        console.error("Category load failed:", error);

        showMessage(
            "Unable to load categories. Please refresh.",
            "error"
        );

    } finally {

        if (activeLoad === controller) {
            activeLoad = null;
        }
    }
}


// =========================================
// 6. SEND CATEGORY ACTION
// =========================================

async function sendAction(payload) {

    const response = await fetch("api/categories.php", {

        method: "POST",
        credentials: "same-origin",

        headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": csrfToken
        },

        body: JSON.stringify(payload)
    });

    if (response.status === 401) {
        window.location.href = "login.php";
        throw new Error("Session expired.");
    }

    const data = await response.json();

    if (!response.ok) {
        throw new Error(
            data.error || "Category action failed."
        );
    }

    return data;
}


// =========================================
// 7. RESET CATEGORY FORM
// =========================================

function resetForm() {

    editingCategoryId = null;

    get("categoryForm").reset();

    get("categoryType").disabled = false;
    get("categoryType").value = "expense";

    get("categoryFormTitle").textContent =
        "Create category";

    get("saveCategory").textContent =
        "CREATE CATEGORY ↗";

    get("cancelCategoryEdit").hidden = true;

    get("categoryName").setCustomValidity("");
    get("categoryName").classList.remove("input-invalid");

    get("nameHint").className = "";
    get("nameHint").textContent =
        "Use a short, descriptive category name.";
}

get("cancelCategoryEdit").addEventListener(
    "click",
    () => {

        resetForm();
        showMessage("");
    }
);


// =========================================
// 8. CREATE OR UPDATE CATEGORY
// =========================================

get("categoryForm").addEventListener(
    "submit",
    async event => {

        event.preventDefault();

        if (!validateCategoryName()) {
            get("categoryForm").reportValidity();
            return;
        }

        if (!get("categoryForm").reportValidity()) {
            return;
        }

        const payload = {

            action: editingCategoryId === null
                ? "create"
                : "update",

            name: normalizeName(
                get("categoryName").value
            ),

            type: get("categoryType").value
        };

        if (editingCategoryId !== null) {
            payload.category_id = editingCategoryId;
        }

        const button = get("saveCategory");

        button.disabled = true;
        button.textContent = "SAVING...";

        showMessage("");

        try {

            const result = await sendAction(payload);

            resetForm();

            await loadCategories();

            showMessage(result.message, "success");

        } catch (error) {

            console.error("Save category failed:", error);

            showMessage(error.message, "error");

        } finally {

            button.disabled = false;

            button.textContent =
                editingCategoryId === null
                    ? "CREATE CATEGORY ↗"
                    : "SAVE CHANGES ↗";
        }
    }
);


// =========================================
// 9. EDIT / DELETE CATEGORY BUTTONS
// =========================================

get("categoryLists").addEventListener(
    "click",
    async event => {

        const button = event.target.closest(
            "button[data-action]"
        );

        if (!button || button.disabled) return;

        const category = categories.find(
            item =>
                String(item.category_id) ===
                String(button.dataset.id)
        );

        if (!category) return;

        // EDIT CATEGORY
        if (button.dataset.action === "edit") {

            editingCategoryId =
                Number(category.category_id);

            get("categoryName").value =
                category.name;

            get("categoryType").value =
                category.type;

            // Rename only; do not change its type
            get("categoryType").disabled = true;

            get("categoryFormTitle").textContent =
                "Rename category";

            get("saveCategory").textContent =
                "SAVE CHANGES ↗";

            get("cancelCategoryEdit").hidden = false;

            validateCategoryName();
            showMessage("");

            get("categoryForm").scrollIntoView({
                behavior: "smooth",
                block: "center"
            });

            get("categoryName").focus({
                preventScroll: true
            });

            return;
        }

        // DELETE CATEGORY
        if (button.dataset.action === "delete") {

            const confirmed = window.confirm(
                `Delete "${category.name}"? ` +
                "This action cannot be undone."
            );

            if (!confirmed) return;

            button.disabled = true;

            try {

                const result = await sendAction({
                    action: "delete",
                    category_id:
                        Number(category.category_id)
                });

                if (
                    editingCategoryId ===
                    Number(category.category_id)
                ) {
                    resetForm();
                }

                await loadCategories();

                showMessage(result.message, "success");

            } catch (error) {

                console.error("Delete failed:", error);

                showMessage(error.message, "error");

                button.disabled = false;
            }
        }
    }
);


// =========================================
// 10. FIRST LOAD
// =========================================

loadCategories();
