# osTicket Google reCAPTCHA v3 Plugin

A proper Google reCAPTCHA v3 integration for osTicket 1.18+, implemented as a distributable PHAR plugin.

This plugin protects guest ticket submissions (open.php and help-topic forms) using Google reCAPTCHA v3 with mandatory server-side enforcement, without modifying any osTicket core files.

---

## Features

- Google reCAPTCHA v3 (invisible, score-based)
- Works on open.php and AJAX-loaded help-topic forms
- Proper PHAR plugin (drop-in installation)
- Admin-configurable keys and thresholds
- Mandatory server-side validation (cannot be bypassed)
- Tested with PHP 8.1 – 8.4
- CSP-aware (documents required allowlists)
- Optional debug logging

---

## Tested With

- osTicket 1.18.x
- PHP 8.1, 8.2, 8.3, 8.4
- Apache on Ubuntu
- Cloudflare in front of osTicket

---

## Installation

1. Download recaptchav3.phar
2. Upload it to:

   /var/www/osTicket/upload/include/plugins/

3. Restart Apache (recommended to clear OPcache):

   sudo systemctl restart apache2

4. Log in to the osTicket Admin Panel
5. Go to Admin → Plugins
6. Enable reCAPTCHA v3 (Field)

---

## Configuration

Navigate to:

Admin → Plugins → reCAPTCHA v3 (Field)
This plugin registers a custom form field type. Enforcement occurs when the
field is present and required on a form.


### Required Settings

- Site Key (Google reCAPTCHA v3)
- Secret Key

### Optional Settings

- Minimum score (default: 0.5)
- Action name (default: ticket_submit)
- HTTP timeout (default: 3 seconds)
- Debug logging

---

## Adding the Field to Forms

1. Go to Admin → Manage → Forms
2. Edit Ticket Details or the desired Help Topic form
3. Add a new field:
   - Type: Google reCAPTCHA v3
   - Required: Yes
4. Save the form

The field renders invisibly but is enforced during submission.

---

## Content Security Policy (CSP)

Your Content Security Policy must allow the following domains:

script-src https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/
connect-src https://www.google.com/recaptcha/

If these domains are blocked, reCAPTCHA will not load.

---

## How It Works

- Uses a v2-style widget render approach (inline HTML and inline JavaScript)
- Dynamically loads Google’s reCAPTCHA v3 API
- Generates a fresh token on every submission
- Injects the token into:
  - g-recaptcha-response
  - the dynamic osTicket form field
- Enforces verification server-side on POST /open.php
- Blocks submission when:
  - the token is missing
  - the token is invalid, expired, or reused
  - the score is below the configured threshold
  - the action does not match

---

## Debug Logging

When Debug logging is enabled, logs are written to:

/upload/include/plugins/recaptchav3-debug.log

Example entries:

[recaptchav3] GATE: open.php POST detected. token_len=1849  
[recaptchav3] GATE: verify response={"success":true,"score":0.9,"action":"ticket_submit"}

Debug logging is disabled by default and should not be left enabled permanently.

---

## License

This project is free to use and modify, but may not be sold or redistributed for profit.

See the LICENSE file for full terms.

---

## Notes

- reCAPTCHA v3 is invisible by design (no checkbox)
- Help topic forms require JavaScript (osTicket behavior)
- No osTicket core files are modified
