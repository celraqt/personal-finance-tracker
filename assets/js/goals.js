
// =========================================
// PFT SAVINGS GOALS
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

    let goalsData = null;
    let editingGoalId = null;
    let activeRequest = null;


    // =====================================
    // 1. THEME
    // =====================================

    let savedTheme = "dark";

    try {
        savedTheme =
            localStorage.getItem("pft-theme") || "dark";
    } catch (error) {
        // Default to dark mode
    }

    function setTheme(theme) {

        const isDark = theme === "dark";

        document.documentElement.dataset.theme = theme;

        get("themeToggle").textContent = isDark
            ? "☀ Light mode"
            : "☾ Dark mode";

        get("themeToggle").setAttribute(
            "aria-pressed",
            String(isDark)
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
            // Keep theme active for this page
        }
    });


    // =====================================
    // 2. HELPERS
    // =====================================

    function money(value) {
        return peso.format(Number(value) || 0);
    }

    function make(tag, className = "", text = "") {

        const node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        node.textContent = text;

        return node;
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

    function readableDate(value) {

        if (!value) return "No target date";

        const date = new Date(`${value}T12:00:00`);

        return date.toLocaleDateString("en-PH", {
            day: "numeric",
            month: "short",
            year: "numeric"
        });
    }

    function showMessage(id, message, type = "") {

        const node = get(id);

        node.className = "goals-message";
        node.textContent = message;

        if (type) {
            node.classList.add(type);
        }
    }

    function setPageStatus(message, error = false) {

        const node = get("goalsStatus");

        node.textContent = message;
        node.className = error
            ? "goals-status error"
            : "goals-status";
    }

    function showEmpty(container, message) {

        container.replaceChildren(
            make("p", "goals-empty", message)
        );
    }

    function formatPercent(value) {
        return Number(value).toFixed(1) + "%";
    }


    // =====================================
    // 3. SUMMARY CARDS
    // =====================================

    function renderSummary(summary) {

        get("totalGoalTarget").textContent =
            money(summary.total_target);

        get("totalGoalSaved").textContent =
            money(summary.total_saved);

        get("totalGoalRemaining").textContent =
            money(summary.remaining);

        get("completedGoalsCount").textContent =
            String(summary.completed_count);
    }


    // =====================================
    // 4. GOAL OPTIONS
    // =====================================

    function updateGoalOptions() {

        if (!goalsData) return;

        const goalSelect = get("contributionGoal");
        const historySelect = get("historyGoalFilter");

        const previousGoal = goalSelect.value;
        const previousHistory = historySelect.value;

        goalSelect.replaceChildren();
        historySelect.replaceChildren();

        const choose = make(
            "option",
            "",
            "Select a savings goal"
        );

        choose.value = "";
        goalSelect.append(choose);

        const all = make(
            "option",
            "",
            "All Goals"
        );

        all.value = "all";
        historySelect.append(all);

        goalsData.goals.forEach(goal => {

            const option = make(
                "option",
                "",
                goal.name
            );

            option.value = String(goal.goal_id);
            goalSelect.append(option);

            const historyOption = make(
                "option",
                "",
                goal.name
            );

            historyOption.value = String(goal.goal_id);
            historySelect.append(historyOption);
        });

        const goalExists = goalsData.goals.some(
            goal =>
                String(goal.goal_id) === previousGoal
        );

        const historyExists = goalsData.goals.some(
            goal =>
                String(goal.goal_id) === previousHistory
        );

        goalSelect.value = goalExists
            ? previousGoal
            : "";

        historySelect.value = historyExists
            ? previousHistory
            : "all";

        goalSelect.disabled =
            goalsData.goals.length === 0;
    }


    // =====================================
    // 5. RENDER GOAL CARDS
    // =====================================

    function renderGoals(goals) {

        const container = get("goalsList");

        container.replaceChildren();

        get("goalsCount").textContent =
            `${goals.length} ` +
            (goals.length === 1 ? "GOAL" : "GOALS");

        if (!goals.length) {

            showEmpty(
                container,
                "You haven't created a savings goal yet. " +
                "Start with a target such as a laptop, " +
                "emergency fund, or school expenses."
            );

            return;
        }

        goals.forEach(goal => {

            const card = make(
                "article",
                "goal-card " + goal.status
            );

            // Header
            const header = make(
                "div",
                "goal-card-header"
            );

            const heading = make("div");

            heading.append(
                make(
                    "strong",
                    "goal-card-name",
                    goal.name
                ),
                make(
                    "p",
                    "goal-card-subtitle",
                    goal.target_date
                        ? "Target: " +
                          readableDate(goal.target_date)
                        : "No target date selected"
                )
            );

            const statusTexts = {
                completed: "✓ COMPLETED",
                overdue: "⚠ OVERDUE",
                active: "IN PROGRESS"
            };

            const status = make(
                "span",
                "goal-status " + goal.status,
                statusTexts[goal.status] ||
                    "IN PROGRESS"
            );

            header.append(heading, status);

            // Saved amount
            const amounts = make(
                "div",
                "goal-card-amounts"
            );

            amounts.append(
                make(
                    "strong",
                    "goal-saved",
                    money(goal.saved_amount)
                ),
                make(
                    "span",
                    "goal-target",
                    "/ " + money(goal.target_amount)
                )
            );

            // Progress
            const track = make(
                "div",
                "goal-track"
            );

            const visibleProgress = Math.max(
                0,
                Math.min(100, Number(goal.percentage))
            );

            track.setAttribute("role", "progressbar");
            track.setAttribute(
                "aria-label",
                goal.name + " savings progress"
            );
            track.setAttribute("aria-valuemin", "0");
            track.setAttribute("aria-valuemax", "100");
            track.setAttribute(
                "aria-valuenow",
                String(visibleProgress)
            );

            const fill = make(
                "div",
                "goal-fill"
            );

            fill.style.width =
                visibleProgress + "%";

            track.append(fill);

            // Progress information
            const progressInfo = make(
                "div",
                "goal-progress-info"
            );

            const remaining = Number(goal.remaining);

            const remainingText =
                goal.status === "completed"
                    ? "Savings target reached"
                    : money(remaining) + " remaining";

            progressInfo.append(
                make(
                    "span",
                    "",
                    remainingText
                ),
                make(
                    "strong",
                    "",
                    formatPercent(goal.percentage) +
                    " saved"
                )
            );

            // Action buttons
            const actions = make(
                "div",
                "goal-actions"
            );

            const addButton = make(
                "button",
                "goal-action primary",
                "+ Add savings"
            );

            addButton.type = "button";
            addButton.dataset.action = "contribute";
            addButton.dataset.id = goal.goal_id;

            const editButton = make(
                "button",
                "goal-action",
                "Edit"
            );

            editButton.type = "button";
            editButton.dataset.action = "edit";
            editButton.dataset.id = goal.goal_id;

            const historyButton = make(
                "button",
                "goal-action",
                "History"
            );

            historyButton.type = "button";
            historyButton.dataset.action = "history";
            historyButton.dataset.id = goal.goal_id;

            const deleteButton = make(
                "button",
                "goal-action danger",
                "Delete"
            );

            deleteButton.type = "button";
            deleteButton.dataset.action = "delete";
            deleteButton.dataset.id = goal.goal_id;

            actions.append(
                addButton,
                editButton,
                historyButton,
                deleteButton
            );

            card.append(
                header,
                amounts,
                track,
                progressInfo,
                actions
            );

            container.append(card);
        });
    }


    // =====================================
    // 6. CONTRIBUTION HISTORY
    // =====================================

    function renderContributions() {

        const container = get("contributionHistory");

        container.replaceChildren();

        if (!goalsData) return;

        const selected =
            get("historyGoalFilter").value;

        const contributions =
            goalsData.contributions.filter(
                contribution =>
                    selected === "all" ||
                    String(contribution.goal_id) ===
                        selected
            );

        if (!contributions.length) {

            showEmpty(
                container,
                "No savings contributions recorded " +
                "for this selection."
            );

            return;
        }

        contributions.forEach(contribution => {

            const row = make(
                "div",
                "contribution-row"
            );

            const icon = make(
                "span",
                "contribution-mark",
                "+"
            );

            icon.setAttribute(
                "aria-hidden",
                "true"
            );

            const info = make(
                "div",
                "contribution-info"
            );

            const note = contribution.note
                ? " · " + contribution.note
                : "";

            info.append(
                make(
                    "strong",
                    "",
                    contribution.goal_name
                ),
                make(
                    "small",
                    "",
                    readableDate(
                        contribution.contribution_date
                    ) + note
                )
            );

            const right = make(
                "div",
                "contribution-right"
            );

            right.append(
                make(
                    "strong",
                    "contribution-amount",
                    "+" + money(contribution.amount)
                )
            );

            const deleteButton = make(
                "button",
                "contribution-delete",
                "Delete"
            );

            deleteButton.type = "button";

            deleteButton.dataset.contributionId =
                contribution.contribution_id;

            right.append(deleteButton);

            row.append(icon, info, right);

            container.append(row);
        });
    }

    get("historyGoalFilter").addEventListener(
        "change",
        renderContributions
    );


    // =====================================
    // 7. LOAD GOALS FROM API
    // =====================================

    async function loadGoals() {

        if (activeRequest) {
            activeRequest.abort();
        }

        const controller = new AbortController();
        activeRequest = controller;

        setPageStatus("Loading savings goals...");

        try {

            const response = await fetch(
                "api/goals.php",
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
                    "Unable to load savings goals."
                );
            }

            goalsData = data;

            renderSummary(data.summary);
            renderGoals(data.goals);
            updateGoalOptions();
            renderContributions();

            setPageStatus("");

            return true;

        } catch (error) {

            if (error.name === "AbortError") {
                return false;
            }

            console.error(
                "Savings goals loading error:",
                error
            );

            goalsData = null;

            [
                "totalGoalTarget",
                "totalGoalSaved",
                "totalGoalRemaining",
                "completedGoalsCount"
            ].forEach(id => {
                get(id).textContent = "—";
            });

            showEmpty(
                get("goalsList"),
                "Unable to load savings goals."
            );

            showEmpty(
                get("contributionHistory"),
                "Unable to load contribution history."
            );

            get("contributionGoal").disabled = true;

            setPageStatus(
                "Unable to load savings goals. " +
                "Please refresh the page.",
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
    // 8. SEND API ACTIONS
    // =====================================

    async function sendAction(payload) {

        const response = await fetch(
            "api/goals.php",
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
                "Savings operation failed."
            );
        }

        return data;
    }


    // =====================================
    // 9. RESET GOAL FORM
    // =====================================

    function resetGoalForm() {

        editingGoalId = null;

        get("goalForm").reset();

        get("goalFormHeading").textContent =
            "Create savings goal";

        get("saveGoal").textContent =
            "CREATE GOAL ↗";

        get("cancelGoalEdit").hidden = true;
    }

    get("cancelGoalEdit").addEventListener(
        "click",
        () => {

            resetGoalForm();
            showMessage("goalMessage", "");
        }
    );


    // =====================================
    // 10. CREATE / EDIT GOAL
    // =====================================

    get("goalForm").addEventListener(
        "submit",
        async event => {

            event.preventDefault();

            if (!event.currentTarget.reportValidity()) {
                return;
            }

            const name =
                get("goalName").value.trim();

            const targetAmount =
                get("goalTarget").value;

            const targetDate =
                get("goalDeadline").value;

            const payload = {
                action: editingGoalId === null
                    ? "create_goal"
                    : "update_goal",

                name: name,
                target_amount: targetAmount,
                target_date: targetDate || null
            };

            if (editingGoalId !== null) {
                payload.goal_id = editingGoalId;
            }

            const button = get("saveGoal");

            button.disabled = true;
            button.textContent = "SAVING...";

            showMessage("goalMessage", "");

            try {

                const result = await sendAction(payload);

                resetGoalForm();

                const loaded = await loadGoals();

                showMessage(
                    "goalMessage",
                    loaded
                        ? result.message
                        : result.message +
                          " Refresh to view the update.",
                    "success"
                );

            } catch (error) {

                console.error(
                    "Goal save failed:",
                    error
                );

                showMessage(
                    "goalMessage",
                    error.message,
                    "error"
                );

            } finally {

                button.disabled = false;

                button.textContent =
                    editingGoalId === null
                        ? "CREATE GOAL ↗"
                        : "SAVE CHANGES ↗";
            }
        }
    );


    // =====================================
    // 11. RECORD SAVINGS CONTRIBUTION
    // =====================================

    get("contributionForm").addEventListener(
        "submit",
        async event => {

            event.preventDefault();

            if (!event.currentTarget.reportValidity()) {
                return;
            }

            const selectedGoal =
                get("contributionGoal").value;

            if (!selectedGoal) {

                showMessage(
                    "contributionMessage",
                    "Please select a savings goal.",
                    "error"
                );

                return;
            }

            const payload = {
                action: "add_contribution",

                goal_id: Number(selectedGoal),

                amount:
                    get("contributionAmount").value,

                contribution_date:
                    get("contributionDate").value,

                note:
                    get("contributionNote").value.trim()
            };

            const button = get("saveContribution");

            button.disabled = true;
            button.textContent = "SAVING...";

            showMessage("contributionMessage", "");

            try {

                const result = await sendAction(payload);

                get("contributionForm").reset();

                get("contributionDate").value =
                    todayLocal();

                const loaded = await loadGoals();

                if (loaded) {
                    get("contributionGoal").value =
                        selectedGoal;
                }

                showMessage(
                    "contributionMessage",
                    loaded
                        ? result.message
                        : result.message +
                          " Refresh to view the update.",
                    "success"
                );

            } catch (error) {

                console.error(
                    "Contribution save failed:",
                    error
                );

                showMessage(
                    "contributionMessage",
                    error.message,
                    "error"
                );

            } finally {

                button.disabled = false;
                button.textContent = "ADD SAVINGS ↗";
            }
        }
    );


    // =====================================
    // 12. GOAL ACTION BUTTONS
    // =====================================

    get("goalsList").addEventListener(
        "click",
        async event => {

            const button = event.target.closest(
                "button[data-action]"
            );

            if (!button || !goalsData) return;

            const goal = goalsData.goals.find(
                item =>
                    String(item.goal_id) ===
                    String(button.dataset.id)
            );

            if (!goal) return;

            const action = button.dataset.action;

            // ADD SAVINGS
            if (action === "contribute") {

                get("contributionGoal").value =
                    String(goal.goal_id);

                get("contributionEditorPanel")
                    .scrollIntoView({
                        behavior: "smooth",
                        block: "center"
                    });

                get("contributionAmount").focus({
                    preventScroll: true
                });

                return;
            }

            // VIEW HISTORY
            if (action === "history") {

                get("historyGoalFilter").value =
                    String(goal.goal_id);

                renderContributions();

                get("contributionHistory")
                    .scrollIntoView({
                        behavior: "smooth",
                        block: "start"
                    });

                return;
            }

            // EDIT GOAL
            if (action === "edit") {

                editingGoalId = Number(goal.goal_id);

                get("goalName").value =
                    goal.name;

                get("goalTarget").value =
                    Number(goal.target_amount)
                        .toFixed(2);

                get("goalDeadline").value =
                    goal.target_date || "";

                get("goalFormHeading").textContent =
                    "Edit savings goal";

                get("saveGoal").textContent =
                    "SAVE CHANGES ↗";

                get("cancelGoalEdit").hidden = false;

                showMessage("goalMessage", "");

                get("goalEditorPanel").scrollIntoView({
                    behavior: "smooth",
                    block: "center"
                });

                get("goalName").focus({
                    preventScroll: true
                });

                return;
            }

            // DELETE GOAL
            if (action === "delete") {

                const confirmed = window.confirm(
                    `Delete "${goal.name}"? ` +
                    "This will permanently delete its " +
                    "contribution history. " +
                    "Income and expense transactions " +
                    "will not be affected."
                );

                if (!confirmed) return;

                button.disabled = true;

                try {

                    const result = await sendAction({
                        action: "delete_goal",
                        goal_id: Number(goal.goal_id)
                    });

                    if (
                        editingGoalId ===
                        Number(goal.goal_id)
                    ) {
                        resetGoalForm();
                    }

                    const loaded = await loadGoals();

                    showMessage(
                        "goalMessage",
                        loaded
                            ? result.message
                            : result.message +
                              " Refresh to view the update.",
                        "success"
                    );

                } catch (error) {

                    console.error(
                        "Goal deletion failed:",
                        error
                    );

                    showMessage(
                        "goalMessage",
                        error.message,
                        "error"
                    );

                    button.disabled = false;
                }
            }
        }
    );


    // =====================================
    // 13. DELETE CONTRIBUTION
    // =====================================

    get("contributionHistory").addEventListener(
        "click",
        async event => {

            const button = event.target.closest(
                "button[data-contribution-id]"
            );

            if (!button || button.disabled) return;

            const confirmed = window.confirm(
                "Delete this savings contribution? " +
                "Your goal's progress will be recalculated."
            );

            if (!confirmed) return;

            button.disabled = true;

            try {

                const result = await sendAction({
                    action: "delete_contribution",
                    contribution_id:
                        Number(button.dataset.contributionId)
                });

                const loaded = await loadGoals();

                showMessage(
                    "contributionMessage",
                    loaded
                        ? result.message
                        : result.message +
                          " Refresh to view the update.",
                    "success"
                );

            } catch (error) {

                console.error(
                    "Contribution deletion failed:",
                    error
                );

                showMessage(
                    "contributionMessage",
                    error.message,
                    "error"
                );

                button.disabled = false;
            }
        }
    );


    // =====================================
    // 14. INITIAL LOAD
    // =====================================

    get("contributionDate").value =
        todayLocal();

    loadGoals();

});
