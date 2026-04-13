<?php

class mailman_integration extends rcube_plugin
{
    const PLUGIN_VERSION = '1.0.0';
    const PLUGIN_INFO = array(
        'name' => 'mailman_integration',
        'vendor' => 'Gene Hawkins',
        'version' => self::PLUGIN_VERSION,
        'license' => 'GPL-3.0-or-later',
        'uri' => 'https://github.com/texxasrulez/mailman_integration',
    );

    public $task = '.*';
    public $rc;
    public $client;
    public $home = '';
    private $ui;

    public static function info(): array
    {
        return self::PLUGIN_INFO;
    }

    public function init()
    {
        $this->rc = rcube::get_instance();
        $this->load_config();
        $this->home = $this->home ?: dirname(__FILE__);

        if (!$this->rc->config->get('mailman_integration_enabled', true)) {
            return;
        }

        $this->add_texts('localization/', true);
        $this->register_action('mailman-compose-info', [$this, 'action_compose_info']);
        $this->register_task('mailman');
        $this->register_autoloader();

        $this->client = new MailmanClient($this->rc, $this->get_config());
        $cache = new MailmanCache($this->rc, (int) $this->rc->config->get('mailman_integration_cache_ttl', 120));
        $mapper = new MailmanMapper($this->rc, $this->get_config());
        $guard = new MailmanGuard($this->rc, $this->get_config(), $mapper);
        $service = new MailmanService($this->rc, $this->client, $cache, $mapper, $guard, $this->get_config());
        $ui = new MailmanUi($this->rc, $service, $guard, $mapper, $this);
        $this->ui = $ui;

        $this->include_script('js/mailman_integration.js?v=' . $this->asset_version());
        $this->include_script('js/mailman_compose.js?v=' . $this->asset_version());

        $this->register_action('mailman', [$this, 'action_lists']);
        $this->register_action('mailman-subscribe', [$this, 'action_subscribe']);
        $this->register_action('mailman-unsubscribe', [$this, 'action_unsubscribe']);

        $this->add_hook('startup', [$this, 'hook_startup']);
        $this->add_hook('template_object_mailmanlistsidebar', [$ui, 'template_sidebar']);
        $this->add_hook('template_object_mailmanlistdetail', [$ui, 'template_detail']);
        $this->add_hook('template_object_mailmanwidget', [$ui, 'template_widget']);
        $this->add_hook('template_object_composeheaders', [$this, 'hook_compose_headers']);
        $this->add_hook('message_before_send', [$this, 'hook_message_before_send']);
        $this->add_hook('message_ready', [$this, 'hook_message_ready']);
    }

    public function action_lists()
    {
        $this->ensure_mailman_task();

        $this->rc->output->set_pagetitle($this->gettext('mailman_lists_title'));
        $this->rc->output->set_env('contentframe', false);
        $this->rc->output->set_env('blankpage', '');
        $this->register_handler('plugin.body', [$this->ui, 'render_page']);

        if (!empty($_SESSION['mailman_integration_flash'])) {
            $flash = $_SESSION['mailman_integration_flash'];
            unset($_SESSION['mailman_integration_flash']);
            $this->rc->output->command('display_message', $flash['message'], $flash['type']);
        }

        $this->rc->output->send('plugin');
    }

    public function action_subscribe()
    {
        $this->handle_membership_action('subscribe');
    }

    public function action_unsubscribe()
    {
        $this->handle_membership_action('unsubscribe');
    }

    public function action_compose_info()
    {
        $token = rcube_utils::get_input_value('_token', rcube_utils::INPUT_POST, true);
        if (!$token || !hash_equals((string) $this->rc->get_request_token(), (string) $token)) {
            header('Content-Type: application/json');
            echo json_encode(['enabled' => false, 'recognized' => [], 'error' => $this->gettext('invalidrequest')]);
            exit;
        }

        if (!$this->rc->config->get('mailman_integration_compose_detection', true)) {
            header('Content-Type: application/json');
            echo json_encode(['enabled' => false, 'recognized' => [], 'error' => $this->gettext('mailman_detection_disabled')]);
            exit;
        }

        $service = $this->get_service();
        $emails = rcube_utils::get_input_value('_emails', rcube_utils::INPUT_POST, true);
        $emails = is_string($emails) ? explode(',', $emails) : [];
        $payload = $service->getComposeWidgetData($emails);

        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }

    public function hook_startup($args)
    {
        if ($this->rc->output->type === 'html') {
            if (!$this->rc->output->framed) {
                $this->add_button([
                    'command' => 'mailman',
                    'class' => 'button-mailman',
                    'classsel' => 'button-mailman button-selected',
                    'innerclass' => 'button-inner',
                    'label' => 'mailman_integration.mailman_lists_title',
                    'title' => 'mailman_integration.mailman_lists_title',
                    'type' => 'link',
                ], 'taskbar');
            }

            $cssPath = $this->skin_css_path();
            $activeSkinName = $this->resolve_active_skin_name();
            $this->debug_log('Startup: active_skin=' . $activeSkinName . ', css_path=' . $cssPath);
            
            $this->include_stylesheet($cssPath . '?v=' . $this->asset_version());
            $this->inject_taskbar_icon_css();
        }

        if ($args['task'] === 'mailman' && empty($args['action'])) {
            $args['action'] = 'mailman';
        }

        return $args;
    }

    public function hook_compose_headers($args)
    {
        static $composeMarkupInjected = false;
        static $ownerToolsCssInjected = false;

        $composeWidgetEnabled = (bool) $this->rc->config->get('mailman_integration_compose_widget', true);
        $ownerToolsEnabled = (bool) $this->rc->config->get('mailman_integration_owner_tools', true);

        if (
            $this->rc->task !== 'mail'
            || $this->rc->action !== 'compose'
            || !$this->rc->config->get('mailman_integration_compose_detection', true)
            || (!$composeWidgetEnabled && !$ownerToolsEnabled)
        ) {
            return $args;
        }

        $this->rc->output->set_env('mailman_compose_lookup_url', './?_task=mail&_action=plugin.mailman-compose-info');
        $this->rc->output->set_env('mailman_compose_lookup_enabled', true);
        $this->rc->output->set_env('mailman_owner_tools_enabled', (bool) $this->rc->config->get('mailman_integration_owner_tools', true));
        $this->rc->output->set_env('mailman_preflight_require_subject', (bool) $this->rc->config->get('mailman_integration_preflight_require_subject', true));
        $this->rc->output->set_env('mailman_preflight_confirm_send', (bool) $this->rc->config->get('mailman_integration_preflight_confirm_send', true));
        $this->rc->output->set_env('mailman_preflight_append_unsubscribe_footer', (bool) $this->rc->config->get('mailman_integration_preflight_append_unsubscribe_footer', false));
        $this->rc->output->set_env('mailman_unsubscribe_footer_template', (string) $this->rc->config->get('mailman_integration_unsubscribe_footer_template', ''));

        $this->rc->output->set_env('mailman_owner_tools_labels', [
            'title' => $this->gettext('mailman_owner_tools_title'),
            'target_prefix' => $this->gettext('mailman_owner_target_prefix'),
            'option_require_subject' => $this->gettext('mailman_owner_option_require_subject'),
            'option_confirm_send' => $this->gettext('mailman_owner_option_confirm_send'),
            'option_append_footer' => $this->gettext('mailman_owner_option_append_footer'),
            'preflight_missing_subject' => $this->gettext('mailman_owner_preflight_missing_subject'),
            'preflight_confirm_send' => $this->gettext('mailman_owner_preflight_confirm_send'),
        ]);

        if (!isset($args['content'])) {
            $args['content'] = '';
        }

        if (!$composeMarkupInjected) {
            $markup = '';
            if ($composeWidgetEnabled) {
                $markup .= $this->ui->getComposeWidgetMarkup();
            }
            if ($ownerToolsEnabled) {
                $markup .= $this->ui->getComposeOwnerToolsMarkup();
            }

            $args['content'] .= '<div class="mailman-compose-widget-wrap">' . $markup . '</div>';
            $composeMarkupInjected = true;
        }

        if ($ownerToolsEnabled && !$ownerToolsCssInjected && method_exists($this->rc->output, 'add_header')) {
            $ownerToolsCss = <<<'CSS'
.mailman-compose-owner-tools {
    box-sizing: border-box;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px 12px;
    margin: 0;
    padding: 2px 0;
    background: transparent;
    border: 0;
    max-width: none;
}

.mailman-compose-owner-tools--inline {
    display: inline-flex;
    vertical-align: middle;
    margin-left: 8px;
}

.mailman-compose-owner-tools__target {
    margin: 0;
    color: #d6e6ff;
    font-size: 0.82rem;
    white-space: nowrap;
}

.mailman-compose-owner-tools__options {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 10px;
}

.mailman-compose-owner-tools__check {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin: 0;
    font-size: 0.82rem;
    color: #d6e6ff;
    line-height: 1.1;
    white-space: nowrap;
}

.mailman-compose-owner-tools__check input[type="checkbox"] {
    margin: 0;
    vertical-align: middle;
    accent-color: #7fb4ff;
}
CSS;

            $this->rc->output->add_header(html::tag('style', ['type' => 'text/css'], $ownerToolsCss));
            $ownerToolsCssInjected = true;
        }

        return $args;
    }

    public function hook_message_before_send($args)
    {
        return $this->apply_outbound_list_headers($args);
    }

    public function hook_message_ready($args)
    {
        return $this->apply_outbound_list_headers($args);
    }

    public function get_plugin_version()
    {
        return self::PLUGIN_VERSION;
    }

    public function asset_version()
    {
        static $version;

        if ($version !== null) {
            return $version;
        }

        $seed = self::PLUGIN_VERSION . ':' . @filemtime(__FILE__);
        $skinCss = $this->skin_css_path();

        $files = [
            $this->home . '/js/mailman_integration.js',
            $this->home . '/js/mailman_compose.js',
            $this->home . '/lib/MailmanService.php',
            $this->home . '/' . ltrim($skinCss, '/'),
        ];

        foreach ($files as $file) {
            if (is_file($file)) {
                $seed .= ':' . @filemtime($file);
            }
        }

        $version = substr(sha1($seed), 0, 12);

        return $version;
    }

    private function skin_css_path()
    {
        $candidates = [];
        $activeSkin = $this->resolve_active_skin_name();

        if (!empty($activeSkin)) {
            $candidates[] = 'skins/' . $activeSkin . '/styles/mailman_integration.css';
        }

        $localCandidate = rtrim($this->local_skin_path(), '/') . '/styles/mailman_integration.css';
        if (!empty($localCandidate)) {
            $candidates[] = $localCandidate;
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (is_file($this->home . '/' . ltrim($candidate, '/'))) {
                return $candidate;
            }
        }

        return $this->resolve_skin_asset('styles/mailman_integration.css');
    }

    private function handle_membership_action($action)
    {
        $this->ensure_mailman_task();

        $listId = rcube_utils::get_input_value('_list', rcube_utils::INPUT_POST, true);
        $token = rcube_utils::get_input_value('_token', rcube_utils::INPUT_POST, true);

        if (!$token || !hash_equals((string) $this->rc->get_request_token(), (string) $token)) {
            $this->flash_redirect('error', 'invalidrequest');
        }

        $service = $this->get_service();
        $result = $action === 'subscribe'
            ? $service->subscribeCurrentUser($listId)
            : $service->unsubscribeCurrentUser($listId);

        $this->flash_redirect($result['success'] ? 'confirmation' : 'error', $result['message']);
    }

    private function flash_redirect($type, $message)
    {
        $label = $this->gettext($message) ?: $message;
        $_SESSION['mailman_integration_flash'] = ['type' => $type, 'message' => $label];
        $this->rc->output->redirect(['task' => 'mailman', 'action' => 'mailman']);
    }

    private function ensure_mailman_task()
    {
        $this->rc->task = 'mailman';
    }

    private function apply_outbound_list_headers($args)
    {
        if (!$this->rc->config->get('mailman_integration_add_list_headers', true)) {
            return $args;
        }

        $service = $this->get_service();
        $recipients = $this->extract_recipients_from_args($args);
        $headers = $service->buildOutboundHeaders($recipients);

        if (empty($headers)) {
            return $args;
        }

        if (!isset($args['headers']) || !is_array($args['headers'])) {
            $args['headers'] = [];
        }

        foreach ($headers as $name => $value) {
            $args['headers'][$name] = $value;
        }

        if (isset($args['message']) && is_object($args['message'])) {
            foreach ($headers as $name => $value) {
                if (method_exists($args['message'], 'set_header')) {
                    $args['message']->set_header($name, $value);
                }
            }
        }

        return $args;
    }

    private function extract_recipients_from_args(array $args)
    {
        $candidates = [];

        foreach (['_to', '_cc', '_bcc'] as $field) {
            $value = rcube_utils::get_input_value($field, rcube_utils::INPUT_POST, true);
            if ($value) {
                $candidates[] = $value;
            }
        }

        foreach (['mailto', 'to', 'cc', 'bcc'] as $key) {
            if (!empty($args[$key])) {
                $candidates[] = is_array($args[$key]) ? implode(',', $args[$key]) : $args[$key];
            }
        }

        if (!empty($args['headers']) && is_array($args['headers'])) {
            foreach (['To', 'Cc', 'Bcc'] as $key) {
                if (!empty($args['headers'][$key])) {
                    $candidates[] = $args['headers'][$key];
                }
            }
        }

        $emails = [];
        foreach ($candidates as $candidate) {
            foreach (rcube_mime::decode_address_list((string) $candidate, 1, false) as $address) {
                if (!empty($address['mailto'])) {
                    $emails[] = $address['mailto'];
                } elseif (!empty($address['email'])) {
                    $emails[] = $address['email'];
                }
            }
        }

        return array_values(array_unique(array_filter($emails)));
    }

    private function register_autoloader()
    {
        spl_autoload_register(function ($class) {
            if (strpos($class, 'Mailman') !== 0) {
                return;
            }

            $file = $this->home . '/lib/' . $class . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    private function get_service()
    {
        static $service;

        if ($service) {
            return $service;
        }

        $cache = new MailmanCache($this->rc, (int) $this->rc->config->get('mailman_integration_cache_ttl', 120));
        $mapper = new MailmanMapper($this->rc, $this->get_config());
        $guard = new MailmanGuard($this->rc, $this->get_config(), $mapper);

        $service = new MailmanService($this->rc, $this->client, $cache, $mapper, $guard, $this->get_config());

        return $service;
    }

    private function get_config()
    {
        return [
            'api_url' => (string) $this->rc->config->get('mailman_integration_api_url', ''),
            'api_user' => (string) $this->rc->config->get('mailman_integration_api_user', ''),
            'api_password' => (string) $this->rc->config->get('mailman_integration_api_password', ''),
            'timeout' => (int) $this->rc->config->get('mailman_integration_timeout', 8),
            'tls_verify' => (bool) $this->rc->config->get('mailman_integration_tls_verify', true),
            'show_directory' => (bool) $this->rc->config->get('mailman_integration_show_directory', false),
            'allow_subscribe' => (bool) $this->rc->config->get('mailman_integration_allow_subscribe', false),
            'allow_unsubscribe' => (bool) $this->rc->config->get('mailman_integration_allow_unsubscribe', true),
            'show_archives' => (bool) $this->rc->config->get('mailman_integration_show_archives', false),
            'show_list_settings' => (bool) $this->rc->config->get('mailman_integration_show_list_settings', false),
            'allowed_lists' => (array) $this->rc->config->get('mailman_integration_allowed_lists', []),
            'blocked_lists' => (array) $this->rc->config->get('mailman_integration_blocked_lists', []),
            'exposed_domains' => (array) $this->rc->config->get('mailman_integration_exposed_domains', []),
            'cache_ttl' => (int) $this->rc->config->get('mailman_integration_cache_ttl', 120),
            'log_level' => (string) $this->rc->config->get('mailman_integration_log_level', 'warning'),
            'debug' => (bool) $this->rc->config->get('mailman_integration_debug', false),
            'health_path' => (string) $this->rc->config->get('mailman_integration_health_path', '/system'),
            'health_fallback_path' => (string) $this->rc->config->get('mailman_integration_health_fallback_path', '/lists'),
            'compose_detection' => (bool) $this->rc->config->get('mailman_integration_compose_detection', true),
            'compose_widget' => (bool) $this->rc->config->get('mailman_integration_compose_widget', true),
            'owner_tools' => (bool) $this->rc->config->get('mailman_integration_owner_tools', true),
            'preflight_require_subject' => (bool) $this->rc->config->get('mailman_integration_preflight_require_subject', true),
            'preflight_confirm_send' => (bool) $this->rc->config->get('mailman_integration_preflight_confirm_send', true),
            'preflight_append_unsubscribe_footer' => (bool) $this->rc->config->get('mailman_integration_preflight_append_unsubscribe_footer', false),
            'unsubscribe_footer_template' => (string) $this->rc->config->get('mailman_integration_unsubscribe_footer_template', ''),
            'add_list_headers' => (bool) $this->rc->config->get('mailman_integration_add_list_headers', true),
            'add_list_unsubscribe' => (bool) $this->rc->config->get('mailman_integration_add_list_unsubscribe', true),
            'allow_identity_aliases' => (bool) $this->rc->config->get('mailman_integration_allow_identity_aliases', false),
        ];
    }

    private function resolve_active_skin_name()
    {
        $configured = (string) $this->rc->config->get('skin', 'elastic');
        $outputSkin = $this->rc->output && !empty($this->rc->output->skin_path)
            ? basename((string) $this->rc->output->skin_path)
            : '';

        $candidates = [];
        
        if ($configured !== '') {
            $candidates[] = $configured;
        }
        
        if ($outputSkin !== '' && $outputSkin !== $configured) {
            $candidates[] = $outputSkin;
        }

        // Check candidates as-is first
        foreach ($candidates as $candidate) {
            if (is_dir($this->home . '/skins/' . $candidate)) {
                return $candidate;
            }
        }

        // If "larry" was requested but not found as a directory, check for built larry variants
        // Fallback to the actual resolved skin path basename if available
        if ($outputSkin !== '') {
            return $outputSkin;
        }

        // Last resort
        return $configured !== '' ? $configured : 'elastic';
    }

    private function resolve_skin_asset($path)
    {
        $skin = $this->resolve_active_skin_name();

        $candidate = 'skins/' . $skin . '/' . ltrim($path, '/');
        $full = $this->home . '/' . $candidate;

        if (is_file($full)) {
            return $candidate;
        }

        if (strpos($skin, '_larry') !== false) {
            $fallback = 'skins/larry/' . ltrim($path, '/');
            if (is_file($this->home . '/' . $fallback)) {
                $this->debug_log('Skin asset fallback from ' . $candidate . ' to ' . $fallback);
                return $fallback;
            }
        }

        $elastic = 'skins/elastic/' . ltrim($path, '/');
        $this->debug_log('Skin asset fallback from ' . $candidate . ' to ' . $elastic);

        return $elastic;
    }

    private function debug_log($message)
    {
        if (!$this->rc->config->get('mailman_integration_debug', false)) {
            return;
        }

        rcube::write_log('mailman_integration', (string) $message);
    }

    private function inject_taskbar_icon_css()
    {
        if ($this->rc->output->type !== 'html' || !method_exists($this->rc->output, 'add_header')) {
            return;
        }

        $skinCss = $this->skin_css_path();
        $isElastic = strpos($skinCss, 'skins/elastic/') === 0;

        if ($isElastic) {
            $icon = rcube::Q($this->url($this->resolve_skin_asset('images/mailman.svg')));
            $iconHover = rcube::Q($this->url($this->resolve_skin_asset('images/mailman-hover.svg')));

            $css = <<<CSS
#taskmenu a.button-mailman:before,
#taskmenu a.mailman:before,
#taskbar a.button-mailman:before,
#taskbar a.mailman:before {
    content: " " !important;
    display: block !important;
    width: 1.6rem !important;
    height: 1.6rem !important;
    margin: 0 auto 0.2rem !important;
    float: none !important;
    background: url('{$icon}') center center no-repeat !important;
    background-size: contain !important;
    font-size: 0 !important;
}

#taskmenu a.button-mailman:hover:before,
#taskmenu a.button-mailman.selected:before,
#taskmenu a.button-mailman.button-selected:before,
#taskmenu a.mailman:hover:before,
#taskmenu a.mailman.selected:before,
#taskmenu a.mailman.button-selected:before,
#taskbar a.button-mailman:hover:before,
#taskbar a.button-mailman.selected:before,
#taskbar a.button-mailman.button-selected:before,
#taskbar a.mailman:hover:before,
#taskbar a.mailman.selected:before,
#taskbar a.mailman.button-selected:before {
    background-image: url('{$iconHover}') !important;
}
CSS;
        } else {
            $icon = rcube::Q($this->url($this->resolve_skin_asset('images/mailman.png')));
            $iconHover = rcube::Q($this->url($this->resolve_skin_asset('images/mailman-hover.png')));

            $css = <<<CSS
#taskbar a.button-mailman span.button-inner,
#taskbar a.button-mailman span.inner,
#taskbar a.mailman span.button-inner,
#taskbar a.mailman span.inner {
    background: url('{$icon}') 0 0 no-repeat !important;
    display: inline-block !important;
    height: 19px !important;
}

#taskbar a.button-mailman:hover span.button-inner,
#taskbar a.button-mailman.button-selected span.button-inner,
#taskbar a.button-mailman.selected span.button-inner,
#taskbar a.button-mailman:hover span.inner,
#taskbar a.button-mailman.button-selected span.inner,
#taskbar a.button-mailman.selected span.inner,
#taskbar a.mailman:hover span.button-inner,
#taskbar a.mailman.button-selected span.button-inner,
#taskbar a.mailman.selected span.button-inner,
#taskbar a.mailman:hover span.inner,
#taskbar a.mailman.button-selected span.inner,
#taskbar a.mailman.selected span.inner {
    background: url('{$iconHover}') 0 0 no-repeat !important;
    height: 19px !important;
}
CSS;
        }

        $this->rc->output->add_header(html::tag('style', ['type' => 'text/css'], $css));
    }
}
