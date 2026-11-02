document.addEventListener("DOMContentLoaded", function () {

    // =====================================
    // SHOW / HIDE PASSWORD
    // =====================================

    document.querySelectorAll("[data-toggle-password]")
        .forEach(button => {

            const input = document.getElementById(
                button.dataset.togglePassword
            );

            if (!input) return;

            button.addEventListener("click", function () {

                const visible = input.type === "text";

                input.type = visible ? "password" : "text";
                button.textContent = visible ? "SHOW" : "HIDE";

                button.setAttribute(
                    "aria-pressed",
                    String(!visible)
                );
            });
        });


    // =====================================
    // FULL NAME VALIDATION
    // =====================================

    const nameInput = document.getElementById("full_name");
    const nameMessage = document.getElementById("nameMessage");

    
function validateName() {

    if (!nameInput) return true;

    const name = nameInput.value.trim();

    // Detect numbers
    const hasNumber = /\p{N}/u.test(name);

    // Allowed characters
    const allowedCharacters =
        /^[\p{L}\p{M} .'-]+$/u.test(name);

    // Require at least two name parts
    const fullNamePattern =
        /^\p{L}[\p{L}\p{M}.'-]*(?: +\p{L}[\p{L}\p{M}.'-]*)+$/u;

    let warning = "";

    if (name === "") {

        warning = "";

    } else if (hasNumber) {

        warning =
            "⚠ Numbers are not allowed in your full name.";

    } else if (!allowedCharacters) {

        warning =
            "⚠ Your name contains invalid characters.";

    } else if (!fullNamePattern.test(name)) {

        warning =
            "⚠ Please enter your first and last name.";

    } else if (Array.from(name).length > 100) {

        warning =
            "⚠ Full name must not exceed 100 characters.";
    }

    if (warning !== "") {

        nameInput.setCustomValidity(warning);
        nameInput.classList.add("input-invalid");
        nameInput.setAttribute("aria-invalid", "true");

        if (nameMessage) {
            nameMessage.textContent = warning;
            nameMessage.className = "field-note invalid";
        }

        return false;
    }

    // Clear the warning
    nameInput.setCustomValidity("");
    nameInput.classList.remove("input-invalid");
    nameInput.removeAttribute("aria-invalid");

    if (nameMessage) {

        if (name === "") {

            nameMessage.textContent =
                "Enter your first and last name.";

            nameMessage.className = "field-note";

        } else {

            nameMessage.textContent =
                "✓ Valid full name.";

            nameMessage.className = "field-note valid";
        }
    }

    return name !== "";


    }

    if (nameInput) {
        nameInput.addEventListener("input", validateName);

        // Validate any existing value from PHP
        validateName();
    }


    // =====================================
    // STRONG PASSWORD VALIDATION
    // =====================================

    const passwordInput = document.getElementById("password");
    const confirmInput = document.getElementById("confirm_password");

    const strengthTrack = document.getElementById("strengthTrack");
    const strengthMessage = document.getElementById("strengthMessage");
    const matchMessage = document.getElementById("matchMessage");

    function validatePassword() {

        // Login page does not need registration rules
        if (!passwordInput || !confirmInput) return true;

        const password = passwordInput.value;

        const byteLength = new TextEncoder()
            .encode(password).length;

        const rules = {
            length: Array.from(password).length >= 12,
            upper: /\p{Lu}/u.test(password),
            lower: /\p{Ll}/u.test(password),
            number: /\p{N}/u.test(password),
            symbol: /[^\p{L}\p{N}\s]/u.test(password),
            bytes: byteLength <= 72
        };

        // Update password checklist
        Object.entries(rules).forEach(([rule, valid]) => {

            const element = document.querySelector(
                `[data-rule="${rule}"]`
            );

            if (element) {
                element.classList.toggle("met", valid);
            }
        });

        const passed = Object.values(rules).filter(Boolean).length;
        const allValid = Object.values(rules).every(Boolean);

        // Update strength bars
        const level = password.length === 0
            ? 0
            : Math.max(1, Math.floor((passed / 6) * 4));

        if (strengthTrack) {

            strengthTrack.dataset.level = String(level);

            strengthTrack.querySelectorAll(".strength-bar")
                .forEach((bar, index) => {

                    bar.classList.toggle(
                        "active",
                        index < level
                    );
                });
        }

        // Password feedback
        if (strengthMessage) {

            if (password === "") {
                strengthMessage.textContent =
                    "Enter a strong password.";
                strengthMessage.className = "field-note";

            } else if (allValid) {
                strengthMessage.textContent =
                    "✓ All password requirements satisfied.";
                strengthMessage.className = "field-note valid";

            } else {
                strengthMessage.textContent =
                    "Complete all password requirements below.";
                strengthMessage.className = "field-note invalid";
            }
        }

        if (password === "") {

            passwordInput.setCustomValidity("");

        } else if (byteLength > 72) {

            passwordInput.setCustomValidity(
                "Password must not exceed 72 bytes."
            );

        } else if (!allValid) {

            passwordInput.setCustomValidity(
                "Password must meet all the requirements."
            );

        } else {

            passwordInput.setCustomValidity("");
        }

        validateConfirmation();

        return allValid;
    }


    // =====================================
    // CONFIRM PASSWORD
    // =====================================

    function validateConfirmation() {

        if (!confirmInput || !passwordInput) return true;

        const confirmation = confirmInput.value;
        const matches = confirmation === passwordInput.value;

        if (confirmation === "") {

            confirmInput.setCustomValidity("");

            if (matchMessage) {
                matchMessage.textContent = "";
                matchMessage.className = "field-note";
            }

            return false;
        }

        if (!matches) {

            confirmInput.setCustomValidity(
                "Passwords do not match."
            );

            if (matchMessage) {
                matchMessage.textContent =
                    "⚠ Passwords do not match.";
                matchMessage.className = "field-note invalid";
            }

        } else {

            confirmInput.setCustomValidity("");

            if (matchMessage) {
                matchMessage.textContent =
                    "✓ Passwords match.";
                matchMessage.className = "field-note valid";
            }
        }

        return matches;
    }

    if (passwordInput && confirmInput) {

        passwordInput.addEventListener(
            "input",
            validatePassword
        );

        confirmInput.addEventListener(
            "input",
            validateConfirmation
        );

        validatePassword();
    }


    // =====================================
    // FORM SUBMISSION
    // =====================================

    document.querySelectorAll("[data-auth-form]")
        .forEach(form => {

            form.addEventListener("submit", function (event) {

                if (nameInput && form.contains(nameInput)) {

                    validateName();
                    validatePassword();
                    validateConfirmation();

                    if (!form.reportValidity()) {

                        event.preventDefault();
                        return;
                    }
                }

                const button = form.querySelector(
                    "[data-submit-button]"
                );

                if (button) {
                    button.disabled = true;

                    button.textContent =
                        button.dataset.loadingText ||
                        "PROCESSING...";
                }
            });
        });
});
