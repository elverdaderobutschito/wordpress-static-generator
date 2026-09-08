/**
 * WP Static Deploy - leichtgewichtige Formular-Validierung.
 *
 * Prüft im Browser, BEVOR ein Formular abgeschickt wird:
 *  - Felder mit aria-required="true" müssen ausgefüllt sein (bei
 *    Checkbox-/Radio-Gruppen: mindestens eine Option der Gruppe).
 *  - type="email"-Felder müssen (falls ausgefüllt) wie eine gültige
 *    E-Mail-Adresse aussehen (nutzt die native Browser-Prüfung).
 *
 * Bewusst KEIN Framework, keine Abhängigkeiten - nutzt die native
 * HTML5-Validierungs-UI des Browsers (setCustomValidity/reportValidity),
 * damit Fehlermeldungen ohne eigenes CSS konsistent aussehen.
 *
 * Warum aria-required statt required? Viele Formular-Plugins (z.B.
 * Fluent Forms) markieren Pflichtfelder aus Barrierefreiheits-Gründen
 * mit aria-required="true", nicht mit dem nativen required-Attribut
 * (das der Browser selbst ohne jedes JS durchsetzen würde). Dieses
 * Skript schließt genau diese Lücke.
 */
(function () {
    'use strict';

    /**
     * Erkennt technische/interne Formularfelder (WordPress-Nonces,
     * AJAX-Routing-Felder, plugin-eigene interne Marker wie bei Fluent
     * Forms __fluent_protection_token_12, _fluentform_12_fluentformnonce,
     * _wp_http_referer etc.) - dieselbe Logik wie im PHP-Formular-Handler
     * (siehe form-handler-template.php), hier aber VOR dem eigentlichen
     * Absenden angewendet, damit weder Netlify noch der PHP-Handler diese
     * Felder überhaupt erst zu Gesicht bekommen.
     */
    function isTechnicalField(name, honeypotField) {
        if (name === honeypotField) {
            return false; // das eigene Honeypot-Feld MUSS mitgesendet werden
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
     * Deaktiviert technische Felder kurz vor dem echten Absenden -
     * deaktivierte Felder werden von Browsern automatisch NICHT
     * mitgesendet (kein manuelles Entfernen aus dem DOM nötig).
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
     * Setzt eine ggf. gesetzte Custom-Fehlermeldung sofort zurück, sobald
     * der Nutzer mit dem Feld interagiert - NICHT erst beim nächsten
     * Absenden-Versuch. Wichtig: Der Browser prüft VOR dem Auslösen des
     * submit-Ereignisses, ob das Formular schon eine gesetzte
     * Fehlermeldung hat - ist das der Fall, wird das submit-Ereignis gar
     * nicht erst gefeuert, unser Reset-Code (der bisher nur innerhalb der
     * submit-Behandlung lief) käme dann nie mehr zum Zug, und die
     * Meldung bliebe dauerhaft hängen, egal was man danach einträgt.
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

    function validateForm(form) {
        var valid = true;

        var allRequired = form.querySelectorAll('[aria-required="true"]');
        var emailFields = form.querySelectorAll('input[type="email"]');

        // Vorherige Custom-Fehler zurücksetzen.
        allRequired.forEach(function (field) {
            if (typeof field.setCustomValidity === 'function') {
                field.setCustomValidity('');
            }
        });

        // Checkbox-/Radio-Gruppen (gleicher name) getrennt behandeln:
        // "mindestens eine Option der Gruppe" statt "jede einzelne".
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
                group[0].setCustomValidity('Bitte mindestens eine Option auswählen.');
                valid = false;
            }
        });

        // Normale Pflichtfelder (Text, E-Mail, Auswahl etc.).
        allRequired.forEach(function (field) {
            if (isCheckboxOrRadio(field)) {
                return; // oben schon als Gruppe behandelt
            }

            if (field.value.trim() === '') {
                field.setCustomValidity('Dieses Feld ist erforderlich.');
                valid = false;
            }
        });

        // E-Mail-Format: nutzt die native Browser-Prüfung für type=email.
        emailFields.forEach(function (field) {
            if (field.value.trim() !== '' && typeof field.checkValidity === 'function' && !field.checkValidity()) {
                valid = false;
            }
        });

        if (!valid) {
            // Erzwingt einen echten Fokuswechsel, bevor die native
            // Validierungs-Sprechblase angezeigt wird - manche Browser
            // (v.a. Chrome) zeigen sie sonst nicht an, wenn das
            // betroffene Feld schon vorher fokussiert war (z.B. weil
            // Fluent Forms dem ersten Feld automatisch den Fokus gibt).
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

        document.querySelectorAll('form').forEach(function (form) {
            form.querySelectorAll('[aria-required="true"], input[type="email"]').forEach(attachLiveReset);

            form.addEventListener('submit', function (event) {
                if (!validateForm(form)) {
                    event.preventDefault();
                    return;
                }

                stripTechnicalFields(form, honeypotField);
            });
        });
    });
})();
