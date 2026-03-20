<?php
require_once INCLUDE_DIR . 'class.forms.php';
require_once INCLUDE_DIR . 'class.i18n.php';

class RecaptchaV3Field extends TextboxField
{
    public static $enabled    = true;
    public static $site_key   = '';
    public static $secret_key = '';
    public static $min_score  = 0.5;
    public static $action     = 'ticket_submit';
    public static $timeout    = 3;
    public static $debug      = false;

    static $type   = 'recaptchav3';
    static $widget = 'RecaptchaV3Widget';

    // Make osTicket treat the token as the field value too (helps required/clean paths)
    function getClean($validate = true) {
        $k = $this->getFormName();
        $v = $_POST[$k] ?? ($_POST['g-recaptcha-response'] ?? '');
        return is_string($v) ? trim($v) : '';
    }

    // Keep field validation compatible; the hard gate in recaptchav3.php is the true enforcement.
    function isValidEntry()
    {
        if (!self::$enabled) return true;

        // If not configured, fail (user-friendly)
        $site   = trim((string) self::$site_key);
        $secret = trim((string) self::$secret_key);

        if (!$site || !$secret) {
            $this->setError(__('reCAPTCHA v3 is not configured.'));
            return false;
        }

        // Token should be present by now (JS set it). If missing, fail.
        $token = $this->getClean(true);
        if ($token === '') {
            $this->setError(__('reCAPTCHA verification failed. Please try again.'));
            return false;
        }

        return true;
    }
}

class RecaptchaV3Widget extends Widget
{
    function render()
    {
        if (!RecaptchaV3Field::$enabled) return;

        $siteKey = trim((string) RecaptchaV3Field::$site_key);
        $action  = trim((string) RecaptchaV3Field::$action) ?: 'ticket_submit';
        $debug   = RecaptchaV3Field::$debug ? 'true' : 'false';

        // Locale (optional)
        $hl = Internationalization::getCurrentLocale();
        if (!$hl) $hl = 'en';

        // Stable unique id for this render
        $anchorId  = 'rcv3_' . md5($this->id . '_' . microtime(true));
        $fieldName = $this->field->getFormName();
        ?>
        <div id="<?php echo Format::htmlchars($anchorId); ?>"
             class="rcv3-anchor"
             style="height:1px;overflow:hidden;opacity:0.01"></div>

        <script>
        (function(){
            var DEBUG = <?php echo $debug; ?>;
            function log(){ if(DEBUG) try{ console.log.apply(console, arguments);}catch(e){} }

            var siteKey   = <?php echo json_encode($siteKey); ?>;
            var action    = <?php echo json_encode($action); ?>;
            var fieldName = <?php echo json_encode($fieldName); ?>;

            function ensureHidden(form, name) {
                var input = form.querySelector('input[name="'+name+'"]');
                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    form.appendChild(input);
                    log('[recaptchav3] created hidden input', name);
                }
                return input;
            }

            function loadGoogle(cb){
                if (window.grecaptcha && typeof window.grecaptcha.ready === 'function') return cb();

                // If loader already injected, wait for grecaptcha
                if (document.querySelector('script[data-rcv3="1"]')) {
                    var tries = 0;
                    (function wait(){
                        if (window.grecaptcha && typeof window.grecaptcha.ready === 'function') return cb();
                        if (++tries > 80) return cb(new Error('grecaptcha not ready after 8s'));
                        setTimeout(wait, 100);
                    })();
                    return;
                }

                var s = document.createElement('script');
                s.src = 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(siteKey)
                      + '&hl=' + encodeURIComponent(<?php echo json_encode($hl); ?>);
                s.async = true;
                s.defer = true;
                s.setAttribute('data-rcv3','1');
                s.onload = function(){ cb(); };
                s.onerror = function(){ cb(new Error('google loader failed')); };
                document.head.appendChild(s);
                log('[recaptchav3] injected Google loader');
            }

            // ---------------------------------------------------------------
            // Robust form detection: anchor.closest('form') can return null
            // when osTicket renders the field outside the <form> tag (seen
            // after osTicket updates). Fall back to known osTicket form IDs
            // and finally to the first form on the page.
            // ---------------------------------------------------------------
            function findForm(anchor) {
                var form = anchor.closest('form');
                if (form) { log('[recaptchav3] found form via closest()'); return form; }

                // osTicket open.php known form IDs / selectors
                var candidates = [
                    'form#new-ticket',
                    'form[action*="open.php"]',
                    'form[name="ticket"]',
                    'form'
                ];
                for (var i = 0; i < candidates.length; i++) {
                    form = document.querySelector(candidates[i]);
                    if (form) { log('[recaptchav3] found form via selector:', candidates[i]); return form; }
                }

                return null;
            }

            function init() {
                var anchor = document.getElementById(<?php echo json_encode($anchorId); ?>);
                if (!anchor) { log('[recaptchav3] anchor not found'); return; }

                var form = findForm(anchor);
                if (!form) { log('[recaptchav3] could not find parent form — giving up'); return; }

                // Bind only once per form
                if (form.__rcv3_bound) { log('[recaptchav3] form already bound'); return; }
                form.__rcv3_bound = true;

                // Move hidden inputs into the form if the anchor was outside it
                var tokenInput = ensureHidden(form, 'g-recaptcha-response');
                var fieldInput = ensureHidden(form, fieldName);

                form.addEventListener('submit', function(e){
                    // Re-entrancy guard FIRST
                    if (form.__rcv3_submitting) return;

                    // Intercept to fetch a fresh token every time
                    e.preventDefault();
                    form.__rcv3_submitting = true;

                    // Clear stale token on every attempt
                    tokenInput.value = '';
                    fieldInput.value = '';

                    loadGoogle(function(err){
                        if (err) {
                            // FAIL CLOSED: do not submit without a token
                            log('[recaptchav3] loader error', err);
                            form.__rcv3_submitting = false;
                            alert('reCAPTCHA could not be loaded. Please check your internet connection and try again.');
                            return;
                        }

                        window.grecaptcha.ready(function(){
                            window.grecaptcha.execute(siteKey, {action: action}).then(function(token){
                                token = token || '';
                                if (!token) {
                                    // FAIL CLOSED: empty token from Google
                                    log('[recaptchav3] execute returned empty token');
                                    form.__rcv3_submitting = false;
                                    alert('reCAPTCHA verification failed. Please reload the page and try again.');
                                    return;
                                }
                                tokenInput.value = token;
                                fieldInput.value = token;
                                log('[recaptchav3] token set len=', token.length);

                                form.__rcv3_submitting = false;
                                form.submit();
                            }).catch(function(ex){
                                // FAIL CLOSED: execute() rejected
                                log('[recaptchav3] execute failed', ex);
                                form.__rcv3_submitting = false;
                                alert('reCAPTCHA verification failed. Please reload the page and try again.');
                            });
                        });
                    });
                }, true);

                log('[recaptchav3] bound form submit handler');
            }

            // Run after DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
        </script>
        <?php
    }

    function getValue()
    {
        return $_POST['g-recaptcha-response'] ?? null;
    }
}