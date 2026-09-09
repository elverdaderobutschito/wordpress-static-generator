<?php

/**
 * Auto-generated form handler (Content2HTML plugin).
 *
 * IMPORTANT: This file is regenerated in the build directory on every
 * "Deploy all" run (the placeholders below are replaced with the
 * configured values) - manual changes made directly to the uploaded
 * version are lost on the next run. Adjustments belong in the plugin
 * settings (Content2HTML -> Forms), or, if needed, in this template
 * (includes/form-handler-template.php).
 *
 * How it works: accepts a POST request from one of the generated HTML
 * forms, emails the content, and then redirects to a thank-you page (or
 * back to the originating page). Includes a simple honeypot field as a
 * spam deterrent.
 */

$RECIPIENT = '{{RECIPIENT}}';
$FROM_EMAIL = '{{FROM_EMAIL}}';
$SUBJECT_PREFIX = '{{SUBJECT_PREFIX}}';
$REDIRECT_URL = '{{REDIRECT_URL}}';
$HONEYPOT_FIELD = '{{HONEYPOT_FIELD}}';

// Protection against an accidental direct call to the UNPROCESSED
// template (in case this file is ever called directly instead of via
// the build process - the placeholders would then not have been
// replaced yet).
if (strpos($RECIPIENT, '{{') === 0) {
    http_response_code(404);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Method Not Allowed');
}

function wpstatic_form_redirect_or_success(string $redirectUrl): void {
    if ($redirectUrl !== '') {
        header('Location: ' . $redirectUrl);
        exit;
    }

    if (!empty($_SERVER['HTTP_REFERER'])) {
        header('Location: ' . wpstatic_add_sent_param($_SERVER['HTTP_REFERER']));
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo 'Danke, deine Nachricht wurde gesendet.';
    exit;
}

/**
 * Sets the "sent=1" query parameter without piling up existing "sent"
 * parameters (e.g. if ?sent=1 is already present in the referrer URL
 * because a previous redirect led there).
 */
function wpstatic_add_sent_param(string $url): string {
    $parts = parse_url($url);
    $query = [];

    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $query['sent'] = '1';

    $result = ($parts['scheme'] ?? '') !== '' ? $parts['scheme'] . '://' : '';
    $result .= $parts['host'] ?? '';
    $result .= isset($parts['port']) ? ':' . $parts['port'] : '';
    $result .= $parts['path'] ?? '';
    $result .= '?' . http_build_query($query);
    $result .= isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

    return $result;
}

// Honeypot: never visible/filled in by humans (see the CSS hiding in
// the generated HTML). If it's filled in anyway, it was probably a bot -
// pretend "success" without actually sending anything, so as not to tip
// the bot off that it was detected.
if (!empty($_POST[$HONEYPOT_FIELD])) {
    wpstatic_form_redirect_or_success($REDIRECT_URL);
}

if ($RECIPIENT === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Formular-Handler ist nicht konfiguriert (keine Empfänger-Adresse hinterlegt).');
}

/**
 * Detects technical/internal form fields (WordPress nonces, AJAX routing
 * fields, plugin-internal markers such as Fluent Forms'
 * __fluent_protection_token_17, _fluentform_17_fluentformnonce,
 * _wp_http_referer etc.) that are of no value to the email recipient,
 * since they relate to WordPress mechanisms that no longer exist in the
 * static context.
 */
function wpstatic_is_technical_field(string $key): bool {
    if ($key === '' || $key[0] === '_') {
        return true; // WP convention: internal/meta fields start with "_"
    }

    if ($key === 'action') {
        return true; // commonly used for WP AJAX routing
    }

    return (bool) preg_match('/(nonce|token)/i', $key);
}

$lines = [];

foreach ($_POST as $key => $value) {
    if ($key === $HONEYPOT_FIELD || wpstatic_is_technical_field($key)) {
        continue;
    }

    if (is_array($value)) {
        $value = implode(', ', array_map('strval', $value));
    }

    // Header injection protection: strip newlines from values that
    // could end up in mail headers (Reply-To).
    $value = str_replace(["\r", "\n"], ' ', (string) $value);
    $lines[] = sprintf('%s: %s', $key, $value);
}

$body = implode("\n", $lines);
$subject = trim($SUBJECT_PREFIX . ' Neue Formular-Nachricht');

$headers = [];

if ($FROM_EMAIL !== '') {
    $headers[] = 'From: ' . $FROM_EMAIL;
}

// If an email field exists in the form, use it as Reply-To - handy for
// being able to reply directly to the message.
foreach (['email', 'e-mail', 'e_mail', 'mail'] as $emailField) {
    if (!empty($_POST[$emailField]) && filter_var($_POST[$emailField], FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . str_replace(["\r", "\n"], '', $_POST[$emailField]);
        break;
    }
}

$mailResult = mail($RECIPIENT, $subject, $body, implode("\r\n", $headers));
$mailError = $mailResult ? null : error_get_last();

wpstatic_log_form_submission($RECIPIENT, $subject, $mailResult, $mailError);

wpstatic_form_redirect_or_success($REDIRECT_URL);

/**
 * Writes one line per form submission to form-handler.log - so you can
 * check (e.g. via FTP) whether PHP's mail() even reported success at
 * all, WITHOUT needing access to the server's PHP error logs. IMPORTANT:
 * "true" from mail() only means "handed off to the mail server", NOT
 * "arrived at the recipient" - SPF/DKIM rejection or server-side spam
 * filtering often only happen AFTERWARDS and don't show up here.
 */
function wpstatic_log_form_submission(string $recipient, string $subject, bool $success, ?array $error): void {
    $line = sprintf(
        "%s;%s;an=%s;betreff=%s;php_mail_ergebnis=%s%s\n",
        date('Y-m-d'),
        date('H:i:s'),
        $recipient,
        $subject,
        $success ? 'true (an Mailserver uebergeben)' : 'FALSE (mail() ist fehlgeschlagen)',
        $error ? ';php_fehler=' . str_replace([";", "\n"], ' ', $error['message']) : ''
    );

    @file_put_contents(__DIR__ . '/form-handler.log', $line, FILE_APPEND | LOCK_EX);
}
