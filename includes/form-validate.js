/**
 * WordPress Static Generator - lightweight form validation.
 *
 * Checks in the browser BEFORE a form is submitted:
 *  - fields with aria-required="true" must be filled in (for
 *    checkbox/radio groups: at least one option in the group).
 *  - type="email" fields must (if filled in) look like a valid email
 *    address (uses the browser's native validation).
 *
 * Deliberately NO framework, no dependencies - uses the browser's
 * native HTML5 validation UI (setCustomValidity/reportValidity), so
 * error messages look consistent without any custom CSS.
 *
 * Why aria-required instead of required? Many form plugins (e.g.
 * Fluent Forms) mark required fields for accessibility reasons with
 * aria-required="true", not with the native required attribute (which
 * the browser itself would enforce without any JS). This script closes
 * exactly that gap.
 */
(function () {
    'use strict';

    /**
     * Detects technical/internal form fields (WordPress nonces, AJAX
     * routing fields, plugin-internal markers such as Fluent Forms'
     * __fluent_protection_token_12, _fluentform_12_fluentformnonce,
     * _wp_http_referer etc.) - the same logic as in the PHP form handler
     * (see form-handler-template.php), but applied here BEFORE the
     * actual submit, so neither Netlify nor the PHP handler ever gets to
     * see these fields at all.
     */
    function isTechnicalField(name, honeypotField) {
        if (name === honeypotField) {
            return false; // the honeypot field itself MUST be submitted
        }

        if (name === '' || name.charAt(0) === '_') {
            return true;
        }

        if (name === 'action') {
            return true;
        }

        return /nonce|token/i.test(name);
    }

    /**
     * Disables technical fields right before the real submit - disabled
     * fields are automatically NOT submitted by browsers (no manual
     * removal from the DOM needed).
     */
    function stripTechnicalFields(form, honeypotField) {
        form.querySelectorAll('input, select, textarea').forEach(function (field) {
            if (field.name && isTechnicalField(field.name, honeypotField)) {
                field.disabled = true;
            }
        });
    }

    function isCheckboxOrRadio(field) {
        return field.type === 'checkbox' || field.type === 'radio';
    }

    /**
     * Resets a previously set custom error message as soon as the user
     * interacts with the field - not only on the next submit attempt.
     * Important: the browser checks BEFORE firing the submit event
     * whether the form already has a custom error message set - if so,
     * the submit event never fires at all, our reset code (which
     * previously only ran inside the submit handler) would then never
     * run again, and the message would stay stuck permanently no matter
     * what gets entered afterwards.
     */
    function attachLiveReset(field) {
        if (typeof field.setCustomValidity !== 'function') {
            return;
        }

        var eventName = (isCheckboxOrRadio(field) || field.tagName === 'SELECT') ? 'change' : 'input';

        field.addEventListener(eventName, function () {
            field.setCustomValidity('');
        });
    }

    function validateForm(form, messages) {
        var valid = true;

        var allRequired = form.querySelectorAll('[aria-required="true"]');
        var emailFields = form.querySelectorAll('input[type="email"]');

        // Reset any previous custom errors.
        allRequired.forEach(function (field) {
            if (typeof field.setCustomValidity === 'function') {
                field.setCustomValidity('');
            }
        });

        // Handle checkbox/radio groups (same name) separately: "at least
        // one option in the group" instead of "every single one".
        var groupNames = [];
        allRequired.forEach(function (field) {
            if (isCheckboxOrRadio(field) && field.name && groupNames.indexOf(field.name) === -1) {
                groupNames.push(field.name);
            }
        });

        groupNames.forEach(function (name) {
            var group = form.querySelectorAll('[name="' + name.replace(/"/g, '\\"') + '"]');
            var anyChecked = false;

            group.forEach(function (el) {
                if (el.checked) {
                    anyChecked = true;
                }
            });

            if (!anyChecked && group.length > 0) {
                group[0].setCustomValidity(messages.selectOne);
                valid = false;
            }
        });

        // Normal required fields (text, email, select etc.).
        allRequired.forEach(function (field) {
            if (isCheckboxOrRadio(field)) {
                return; // already handled as a group above
            }

            if (field.value.trim() === '') {
                field.setCustomValidity(messages.required);
                valid = false;
            }
        });

        // Email format: uses the browser's native validation for type=email.
        emailFields.forEach(function (field) {
            if (field.value.trim() !== '' && typeof field.checkValidity === 'function' && !field.checkValidity()) {
                valid = false;
            }
        });

        if (!valid) {
            // Forces a real focus change before the native validation
            // bubble is shown - some browsers (Chrome especially) won't
            // show it otherwise if the affected field was already
            // focused beforehand (e.g. because Fluent Forms
            // auto-focuses the first field).
            var firstInvalid = form.querySelector(':invalid');

            if (firstInvalid && typeof firstInvalid.blur === 'function' && typeof firstInvalid.focus === 'function') {
                firstInvalid.blur();
                firstInvalid.focus();
            }

            if (typeof form.reportValidity === 'function') {
                form.reportValidity();
            }
        }

        return valid;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var scriptTag = document.querySelector('script[data-honeypot], script[src$="wpstatic-form-validate.js"]');
        var honeypotField = scriptTag ? (scriptTag.getAttribute('data-honeypot') || '') : '';

        // Messages come from the server (see WPStatic_Forms::applyToGenerator())
        // translated into whatever language the WordPress install is
        // configured for - falls back to English if the script tag
        // doesn't carry them (e.g. if this file is used standalone).
        var messages = {
            required: (scriptTag && scriptTag.getAttribute('data-msg-required')) || 'This field is required.',
            selectOne: (scriptTag && scriptTag.getAttribute('data-msg-select-one')) || 'Please select at least one option.'
        };

        document.querySelectorAll('form').forEach(function (form) {
            form.querySelectorAll('[aria-required="true"], input[type="email"]').forEach(attachLiveReset);

            form.addEventListener('submit', function (event) {
                if (!validateForm(form, messages)) {
                    event.preventDefault();
                    return;
                }

                stripTechnicalFields(form, honeypotField);
            });
        });
    });
})();
