document.addEventListener("DOMContentLoaded", () => {

    const monthInput =
        document.getElementById("monthInput");

    const banner =
        document.getElementById("dashboardBudgetAlert");

    const title =
        document.getElementById("dashboardBudgetAlertTitle");

    const message =
        document.getElementById("dashboardBudgetAlertText");

    if (!monthInput || !banner || !title || !message) {
        return;
    }

    let activeRequest = null;

    async function checkBudgetAlerts() {

        if (activeRequest) {
            activeRequest.abort();
        }

        const controller = new AbortController();
        activeRequest = controller;

        banner.hidden = true;

        try {

            const response = await fetch(
                "api/budgets.php?month=" +
                encodeURIComponent(monthInput.value),
                {
                    credentials: "same-origin",
                    cache: "no-store",
                    signal: controller.signal
                }
            );

            if (!response.ok) {
                return;
            }

            const data = await response.json();

            if (!data.alerts || !data.alerts.length) {
                return;
            }

            const alerts = data.alerts;

            const hasCritical = alerts.some(
                alert => alert.status === "danger"
            );

            title.textContent = hasCritical
                ? "⚠ Budget limit reached"
                : "⚠ Spending warning";

            if (alerts.length === 1) {

                message.textContent =
                    alerts[0].message;

            } else {

                message.textContent =
                    "You have " +
                    alerts.length +
                    " active budget alerts. " +
                    "Open Budgets to review your spending.";
            }

            banner.classList.toggle(
                "critical",
                hasCritical
            );

            banner.hidden = false;

        } catch (error) {

            if (error.name !== "AbortError") {
                console.error(
                    "Budget notification check failed:",
                    error
                );
            }

        } finally {

            if (activeRequest === controller) {
                activeRequest = null;
            }
        }
    }

    monthInput.addEventListener(
        "change",
        checkBudgetAlerts
    );

    checkBudgetAlerts();

});
