<?php
require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/field.recaptchav3.php';

class GoogleRecaptchaV3 extends Plugin
{
    var $config_class = "GoogleRecaptchaV3Config";

    private function debugEnabled(): bool {
        try { return (bool)$this->getConfig()->get('debug'); } catch (\Throwable $e) { return false; }
    }

    private function logPath(): string {
        // /var/www/osTicket/upload/include/plugins/recaptchav3-debug.log
        $base = rtrim(dirname(rtrim(INCLUDE_DIR, '/')), '/'); // parent of include (public root)
        // If include dir is not under /upload/include, try to use /upload/include/plugins
        if (is_dir($base . '/upload/include/plugins')) {
            return $base . '/upload/include/plugins/recaptchav3-debug.log';
        }
        return $base . '/include/plugins/recaptchav3-debug.log';
    }

    private function logLine(string $msg): void {
        if (!$this->debugEnabled()) return;

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $line = '[recaptchav3] ' . $msg . ' | ' . $uri;

        // 1) PHP error_log (best effort)
        @error_log($line);

        // 2) osTicket system log (shows in Admin UI)
        try {
            if (!class_exists('Syslog')) {
                // osTicket core syslog class
                @require_once INCLUDE_DIR . 'class.syslog.php';
            }
            if (class_exists('Syslog')) {
                // Priority varies by version; these constants exist on most builds
                if (defined('Syslog::LOG_DEBUG')) {
                    Syslog::log(Syslog::LOG_DEBUG, 'reCAPTCHA v3', $line);
                } elseif (defined('Syslog::LOG_INFO')) {
                    Syslog::log(Syslog::LOG_INFO, 'reCAPTCHA v3', $line);
                } else {
                    // Fallback
                    Syslog::log(1, 'reCAPTCHA v3', $line);
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // 3) Deterministic file log under /upload/include/plugins/
        $path = $this->logPath();
        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND);
    }

    private function isOpenPhpPost(): bool {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return false;
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        return (substr($script, -8) === 'open.php');
    }

    private function blockWithErrorPage(string $msg): void {
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        $m = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        $back = htmlspecialchars($_SERVER['HTTP_REFERER'] ?? 'open.php', ENT_QUOTES, 'UTF-8');
        echo "<!doctype html><html><head><meta charset='utf-8'><title>reCAPTCHA</title></head><body style='font-family:Arial,sans-serif;max-width:720px;margin:40px auto;line-height:1.5'>";
        echo "<h2>Unable to create a ticket</h2>";
        echo "<p>{$m}</p>";
        echo "<p><a href='{$back}'>Go back</a> and try again.</p>";
        echo "</body></html>";
        exit;
    }

    private function verifyWithGoogle(string $secret, string $token, int $timeoutSeconds): ?array {
        $url = 'https://www.google.com/recaptcha/api/siteverify';
        $post = [
            'secret' => $secret,
            'response' => $token,
        ];
        $remoteip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remoteip) $post['remoteip'] = $remoteip;

        $timeoutSeconds = max(1, $timeoutSeconds);
        $body = null;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($post),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
                CURLOPT_TIMEOUT => $timeoutSeconds,
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query($post),
                    'timeout' => max(1, $timeoutSeconds),
                ]
            ]);
            $body = @file_get_contents($url, false, $ctx);
        }

        if (!$body) return null;
        $json = json_decode($body, true);
        return is_array($json) ? $json : null;
    }

    private function enforceServerSideGate(): void {
        if (!RecaptchaV3Field::$enabled) return;
        if (!$this->isOpenPhpPost()) return;

        $site   = trim((string)RecaptchaV3Field::$site_key);
        $secret = trim((string)RecaptchaV3Field::$secret_key);

        if (!$site || !$secret) {
            $this->logLine('GATE: not configured (site/secret missing)');
            $this->blockWithErrorPage('reCAPTCHA is not configured. Please contact support.');
        }

        $token = trim((string)($_POST['g-recaptcha-response'] ?? ''));
        $this->logLine('GATE: open.php POST detected. token_len=' . strlen($token));

        if ($token === '') {
            $this->logLine('GATE: missing token');
            $this->blockWithErrorPage('reCAPTCHA token missing. Please reload the page and try again.');
        }

        $resp = $this->verifyWithGoogle($secret, $token, (int)RecaptchaV3Field::$timeout);

        if (!is_array($resp)) {
            $this->logLine('GATE: verify failed (no/invalid response)');
            $this->blockWithErrorPage('Unable to verify reCAPTCHA at this time. Please try again.');
        }

        $this->logLine('GATE: verify response=' . json_encode($resp));

        if (empty($resp['success'])) {
            $this->blockWithErrorPage('reCAPTCHA verification failed. Please try again.');
        }

        if (isset($resp['score']) && (float)$resp['score'] < (float)RecaptchaV3Field::$min_score) {
            $this->blockWithErrorPage('reCAPTCHA score too low. Please try again.');
        }

        if (!empty(RecaptchaV3Field::$action) && isset($resp['action']) && $resp['action'] !== RecaptchaV3Field::$action) {
            $this->blockWithErrorPage('reCAPTCHA action mismatch. Please try again.');
        }
    }

    function bootstrap()
    {
        $config = $this->getConfig();

        RecaptchaV3Field::$enabled    = (bool)$config->get('enabled');
        RecaptchaV3Field::$site_key   = (string)$config->get('site_key');
        RecaptchaV3Field::$secret_key = (string)$config->get('secret_key');

        $min = $config->get('min_score');
        RecaptchaV3Field::$min_score  = ($min === '' || $min === null) ? 0.5 : (float)$min;

        $action = trim((string)$config->get('action'));
        RecaptchaV3Field::$action = $action ?: 'ticket_submit';

        $timeout = $config->get('timeout');
        RecaptchaV3Field::$timeout = ($timeout === '' || $timeout === null) ? 3 : max(1, (int)$timeout);

        RecaptchaV3Field::$debug = (bool)$config->get('debug');

        FormField::addFieldTypes(__('Verification'), function () {
            return array(
                'recaptchav3' => array(__('Google reCAPTCHA v3'), 'RecaptchaV3Field')
            );
        });

        // Log once per request when debug enabled, so you can confirm bootstrap is running
        $this->logLine('BOOTSTRAP: loaded; log_file=' . $this->logPath());

        $this->enforceServerSideGate();
    }
}
