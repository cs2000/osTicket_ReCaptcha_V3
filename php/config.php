<?php
require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

class GoogleRecaptchaV3Config extends PluginConfig {
    function getOptions() {
        $Password = class_exists('PasswordField') ? 'PasswordField' : 'TextboxField';

        return array(
            'google' => new SectionBreakField(array(
                'label' => __('Google reCAPTCHA v3'),
            )),

            'enabled' => new BooleanField(array(
                'label' => __('Enable reCAPTCHA v3'),
                'default' => true,
            )),

            'site_key' => new TextboxField(array(
                'label' => __('reCAPTCHA v3 Site Key'),
                'required'=> true,
                'configuration' => array('size'=>60, 'length'=>120, 'autocomplete'=>'off'),
            )),

            'secret_key' => new $Password(array(
                'label' => __('reCAPTCHA v3 Secret Key'),
                'required'=> true,
                'configuration' => array('size'=>60, 'length'=>120, 'autocomplete'=>'off'),
            )),

            'min_score' => new TextboxField(array(
                'label' => __('Minimum Score (0.0 – 1.0)'),
                'required' => false,
                'hint' => __('Default 0.5'),
                'configuration' => array('size'=>6, 'length'=>6),
            )),

            'action' => new TextboxField(array(
                'label' => __('Action Name'),
                'required' => false,
                'hint' => __('Default ticket_submit'),
                'configuration' => array('size'=>24, 'length'=>64),
            )),

            'timeout' => new TextboxField(array(
                'label' => __('HTTP Timeout (seconds)'),
                'required' => false,
                'hint' => __('Default 3'),
                'configuration' => array('size'=>6, 'length'=>6),
            )),

            'debug' => new BooleanField(array(
                'label' => __('Debug logging'),
                'default' => false,
                'hint' => __('When enabled, logs are written to: /upload/include/plugins/recaptchav3-debug.log (and also to PHP error_log when available).'),
            )),
        );
    }
}
