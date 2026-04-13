<?php

class MailmanUi
{
    private $rc;
    private $service;
    private $guard;
    private $mapper;
    private $plugin;

    public function __construct(rcube $rc, MailmanService $service, MailmanGuard $guard, MailmanMapper $mapper, rcube_plugin $plugin)
    {
        $this->rc = $rc;
        $this->service = $service;
        $this->guard = $guard;
        $this->mapper = $mapper;
        $this->plugin = $plugin;
    }

    public function template_sidebar($args)
    {
        $selected = rcube_utils::get_input_value('_list', rcube_utils::INPUT_GPC, true);
        $data = $this->service->getDashboardData($selected);
        $args['content'] = $this->renderSidebar($data, $selected);

        return $args;
    }

    public function template_detail($args)
    {
        $selected = rcube_utils::get_input_value('_list', rcube_utils::INPUT_GPC, true);
        $data = $this->service->getDashboardData($selected);
        $args['content'] = $this->renderDetail($data);

        return $args;
    }

    public function template_widget($args)
    {
        $args['content'] = $this->getComposeWidgetMarkup();

        return $args;
    }

    public function render_page()
    {
        $selected = rcube_utils::get_input_value('_list', rcube_utils::INPUT_GPC, true);
        $data = $this->service->getDashboardData($selected);

        $layout = html::tag('div', ['class' => 'mailman-layout'],
            html::tag('aside', ['class' => 'mailman-layout__sidebar'],
                html::tag('div', ['class' => 'mailman-scrollpane'], $this->renderSidebar($data, $selected))
            )
            . html::tag('section', ['class' => 'mailman-layout__detail'],
                html::tag('div', ['class' => 'mailman-scrollpane'], $this->renderDetail($data))
            )
        );

        return $this->renderInlineStyles()
          . html::tag('div', ['class' => 'mailman-page'], $layout);
    }

    public function getComposeWidgetMarkup()
    {
        return html::tag('div', [
            'id' => 'mailman-compose-widget',
            'class' => 'mailman-compose-widget hidden',
            'data-empty-label' => $this->plugin->gettext('mailman_compose_widget_empty'),
        ], html::tag('div', ['class' => 'mailman-compose-widget__content'], ''));
    }

    public function getComposeOwnerToolsMarkup()
    {
      return html::tag('div', [
            'id' => 'mailman-compose-owner-tools',
            'class' => 'mailman-compose-owner-tools hidden',
        ],
        html::tag('span', ['id' => 'mailman-owner-target', 'class' => 'mailman-compose-owner-tools__target'], '')
        . html::tag('div', ['class' => 'mailman-compose-owner-tools__options'],
                html::tag('label', ['class' => 'mailman-compose-owner-tools__check'],
                    html::tag('input', ['type' => 'checkbox', 'id' => 'mailman-opt-require-subject', 'checked' => 'checked'])
                    . ' ' . $this->plugin->gettext('mailman_owner_option_require_subject')
                )
                . html::tag('label', ['class' => 'mailman-compose-owner-tools__check'],
                    html::tag('input', ['type' => 'checkbox', 'id' => 'mailman-opt-confirm-send', 'checked' => 'checked'])
                    . ' ' . $this->plugin->gettext('mailman_owner_option_confirm_send')
                )
                . html::tag('label', ['class' => 'mailman-compose-owner-tools__check'],
                    html::tag('input', ['type' => 'checkbox', 'id' => 'mailman-opt-append-footer'])
                    . ' ' . $this->plugin->gettext('mailman_owner_option_append_footer')
                )
            )
        );
    }

    private function renderInlineStyles()
    {
        // Map skin names to their specific colors
        $skinColors = [
            'autumn_larry' => ['bg' => '#f5d8c5', 'border' => '#8b6f47'],
            'black_larry' => ['bg' => '#d3d3d8', 'border' => '#595959'],
            'blue_larry' => ['bg' => '#d9ecf4', 'border' => '#265576'],
            'green_larry' => ['bg' => '#dcebb0', 'border' => '#5a7819'],
            'grey_larry' => ['bg' => '#e1e1e1', 'border' => '#595959'],
            'pink_larry' => ['bg' => '#e8cad8', 'border' => '#8b5a8a'],
            'plata_larry' => ['bg' => '#d5d5d5', 'border' => '#595959'],
            'summer_larry' => ['bg' => '#e2cf98', 'border' => '#a68547'],
            'teal_larry' => ['bg' => '#b8efe6', 'border' => '#248b7c'],
            'violet_larry' => ['bg' => '#e5cde9', 'border' => '#704b80'],
            'larry' => ['bg' => '#edf4ff', 'border' => '#9cb8dd'],
        ];

        $activeSkin = $this->plugin->rc->output && !empty($this->plugin->rc->output->skin_path)
            ? basename($this->plugin->rc->output->skin_path)
            : (string) $this->plugin->rc->config->get('skin', 'elastic');

        // Remove "skin-" prefix if present
        $activeSkin = preg_replace('/^skin-/', '', (string) $activeSkin);

        // Pick colors for this skin, fallback to larry defaults
        $colors = $skinColors[$activeSkin] ?? $skinColors['larry'];
        $activeBg = $colors['bg'];
        $activeBorder = $colors['border'];

        $css = <<<CSS
.mailman-page {
  box-sizing: border-box;
  width: 100%;
  padding: var(--mailman-page-padding, 16px);
}

.mailman-layout {
  display: flex;
  gap: var(--mailman-layout-gap, 16px);
  align-items: stretch;
  min-height: calc(100vh - 210px);
}

.mailman-layout__sidebar,
.mailman-layout__detail {
  min-width: 0;
}

.mailman-layout__sidebar {
  flex: 0 0 var(--mailman-sidebar-basis, 340px);
  max-width: var(--mailman-sidebar-max, 420px);
}

.mailman-layout__detail {
  flex: 1 1 auto;
}

.mailman-scrollpane {
  box-sizing: border-box;
  height: var(--mailman-scroll-height, calc(100vh - 210px));
  min-height: var(--mailman-scroll-min-height, 420px);
  overflow: auto;
  padding: var(--mailman-scroll-padding, 14px);
  background: var(--mailman-surface-bg, #ffffff);
  border: 1px solid var(--mailman-surface-border, #c8ced6);
  border-radius: var(--mailman-radius, 4px);
}

.mailman-status,
.mailman-panel,
.mailman-detail,
.mailman-compose-widget {
  box-sizing: border-box;
  background: var(--mailman-panel-bg, #ffffff);
  border: 1px solid var(--mailman-panel-border, #d6dce3);
  border-radius: var(--mailman-radius, 4px);
  margin: 0 0 var(--mailman-section-gap, 14px);
  padding: var(--mailman-scroll-padding, 14px);
}

.mailman-status.is-degraded {
  background: var(--mailman-degraded-bg, #fff8e8);
  border-color: var(--mailman-degraded-border, #e5c97a);
}

.mailman-panel:last-child,
.mailman-detail:last-child {
  margin-bottom: 0;
}

.mailman-panel__title,
.mailman-detail__title {
  margin: 0 0 var(--mailman-title-gap, 12px);
  line-height: 1.25;
}

.mailman-detail__title {
  font-size: var(--mailman-detail-title-size, 1.45rem);
}

:where(.mailman-list-item) {
  display: block;
  padding: var(--mailman-item-padding-y, 10px) var(--mailman-item-padding-x, 12px);
  margin: 0 0 var(--mailman-item-gap, 8px);
  border: 1px solid var(--mailman-item-border, #d9dee5);
  border-radius: var(--mailman-radius, 4px);
  text-decoration: none;
  color: inherit;
  overflow-wrap: anywhere;
  word-break: break-word;
}

:where(.mailman-list-item.selected),
:where(.mailman-list-item:hover),
:where(.mailman-list-item:focus) {
  background: {$activeBg};
  border-color: {$activeBorder};
  text-decoration: none;
}

:where(.mailman-list-item:last-child) {
  margin-bottom: 0;
}

.mailman-list-item__name,
.mailman-list-item__address,
.mailman-detail__address,
.mailman-detail__description,
.mailman-debug__payload {
  display: block;
  overflow-wrap: anywhere;
  word-break: break-word;
}

.mailman-list-item__name {
  font-weight: 600;
  margin-bottom: 4px;
}

.mailman-list-item__address,
.mailman-detail__address,
.mailman-empty-state,
.mailman-list-empty,
.mailman-actions__note {
  color: var(--mailman-muted-text, #5d6875);
}

.mailman-detail__address {
  margin: 0 0 var(--mailman-title-gap, 12px);
}

.mailman-detail__description {
  margin-bottom: var(--mailman-title-gap, 12px);
  line-height: var(--mailman-detail-line-height, 1.5);
}

.mailman-detail__meta {
  margin: 0 0 var(--mailman-section-gap, 14px);
  padding-left: var(--mailman-meta-padding-left, 20px);
}

.mailman-detail__meta li {
  margin: 0 0 6px;
  overflow-wrap: anywhere;
  word-break: break-word;
}

.mailman-detail__meta li:last-child {
  margin-bottom: 0;
}

.mailman-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--mailman-actions-gap, 10px);
  margin-top: var(--mailman-section-gap, 14px);
}

.mailman-send-group {
  display: flex;
  align-items: center;
  gap: 8px;
}

.mailman-template-picker {
  max-width: 200px;
}

.mailman-action-form {
  margin: 0;
}

.mailman-debug__entry {
  margin: 0 0 var(--mailman-item-gap, 8px);
}

.mailman-debug__entry:last-child {
  margin-bottom: 0;
}

.mailman-debug__summary {
  cursor: pointer;
}

.mailman-debug__payload {
  white-space: pre-wrap;
  margin: var(--mailman-item-gap, 8px) 0 0;
  padding: 10px;
  background: var(--mailman-debug-bg, #f5f7fa);
  border-radius: var(--mailman-radius, 4px);
}

@media (max-width: 980px) {
  .mailman-layout {
    flex-direction: column;
    min-height: auto;
  }

  .mailman-layout__sidebar {
    flex: 1 1 auto;
    max-width: none;
  }

  .mailman-scrollpane {
    height: auto;
    max-height: none;
    min-height: 0;
  }
}
CSS;

        return html::tag('style', ['type' => 'text/css'], $css);
    }

    private function renderSidebar(array $data, $selected)
    {
        $parts = [];
        $parts[] = html::tag('div', ['class' => 'mailman-status ' . ($data['health']['ok'] ? 'is-ok' : 'is-degraded')], $this->plugin->gettext($data['health']['message']));
        if (!empty($data['debug_enabled']) && !empty($data['debug_entries'])) {
            $parts[] = $this->renderDebugPanel($data['debug_entries']);
        }
        $parts[] = $this->renderListGroup($this->plugin->gettext('mailman_my_lists'), $data['my_lists'], $selected, false);

        if ($data['directory_enabled']) {
            $parts[] = $this->renderListGroup($this->plugin->gettext('mailman_available_lists'), $data['directory_lists'], $selected, true);
        }

        return implode('', $parts);
    }

    private function renderDetail(array $data)
    {
        $list = $data['selected'];
        if (!$list) {
            return html::tag('div', ['class' => 'mailman-empty-state'], $this->plugin->gettext('mailman_select_list'));
        }

        $pieces = [];
        $pieces[] = html::tag('h2', ['class' => 'mailman-detail__title'], rcube::Q($list['name'] ?: $list['id']));
        $pieces[] = html::tag('p', ['class' => 'mailman-detail__address'], rcube::Q($list['address']));

        if ($list['description'] !== '') {
            $pieces[] = html::tag('div', ['class' => 'mailman-detail__description'], nl2br(rcube::Q($list['description'])));
        }

        $meta = [];
        if ($list['created_at'] !== '') {
            $meta[] = html::tag('li', [], rcube::Q($list['created_at']));
        }
        if ($data['show_archives'] && $list['archive_url'] !== '') {
            $meta[] = html::tag('li', [], html::a(['href' => $list['archive_url'], 'target' => '_blank', 'rel' => 'noopener'], $this->plugin->gettext('mailman_archives')));
        }
        if ($data['show_list_settings']) {
            $meta[] = html::tag('li', [], rcube::Q($list['mail_host']));
        }
        if ($meta) {
            $pieces[] = html::tag('ul', ['class' => 'mailman-detail__meta'], implode('', $meta));
        }

        $pieces[] = $this->renderActions($list, $data);

        return html::tag('div', ['class' => 'mailman-detail'], implode('', $pieces));
    }

    private function renderListGroup($title, array $lists, $selected, $isDirectory)
    {
        $rows = [];

        foreach ($lists as $list) {
            $classes = ['mailman-list-item'];
            if ($selected === $list['id'] || (!$selected && !$isDirectory && $list === reset($lists))) {
                $classes[] = 'selected';
            }

            $url = './?_task=mailman&_action=mailman&_list=' . rawurlencode($list['id']);
            $label = html::span(['class' => 'mailman-list-item__name'], rcube::Q($list['name'] ?: $list['id']));
            $address = html::span(['class' => 'mailman-list-item__address'], rcube::Q($list['address']));
            $rows[] = html::a(['href' => $url, 'class' => implode(' ', $classes)], $label . $address);
        }

        if (empty($rows)) {
            $rows[] = html::tag('div', ['class' => 'mailman-list-empty'], $isDirectory ? $this->plugin->gettext('mailman_no_directory_lists') : $this->plugin->gettext('mailman_no_lists'));
        }

        return html::tag('section', ['class' => 'mailman-panel'], html::tag('h3', ['class' => 'mailman-panel__title'], $title) . implode('', $rows));
    }

    private function renderActions(array $list, array $data)
    {
        $buttons = [];
        $token = $this->rc->get_request_token();
        $composeRecipient = $this->getListComposeRecipient($list);

        if ($this->isListOwner($list) && $composeRecipient !== '') {
            $composeUrl = './?_task=mail&_action=compose&_to=' . rawurlencode($composeRecipient);
            $sendBtn = html::a([
                'href'   => $composeUrl,
                'target' => '_top',
                'class'  => 'button mainaction mailman-send-btn',
            ], $this->plugin->gettext('mailman_send_to_list'));

            $templates = (array) $this->rc->config->get('mailman_integration_message_templates', []);
            $validTemplates = array_values(array_filter($templates, function ($t) {
                return !empty($t['name']);
            }));

            if (!empty($validTemplates)) {
                $options = html::tag('option', ['value' => ''], '— ' . $this->plugin->gettext('mailman_template_none') . ' —');
                foreach ($validTemplates as $idx => $tpl) {
                    $options .= html::tag('option', ['value' => $idx], rcube::Q((string) $tpl['name']));
                }
                $picker = html::tag('select', ['class' => 'mailman-template-picker button'], $options);
                $buttons[] = html::tag('div', [
                    'class'          => 'mailman-send-group',
                    'data-templates' => json_encode($validTemplates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT),
                ], $picker . $sendBtn);
            } else {
                $buttons[] = $sendBtn;
            }
        }

        if (!empty($list['actions']['subscribe']) && $data['can_subscribe']) {
            $buttons[] = $this->renderActionForm('mailman-subscribe', 'mailman_subscribe', $list['id'], $token);
        }

        if (!empty($list['actions']['unsubscribe']) && $data['can_unsubscribe']) {
            $buttons[] = $this->renderActionForm('mailman-unsubscribe', 'mailman_unsubscribe', $list['id'], $token);
        }

        if (empty($buttons)) {
            $buttons[] = html::tag('p', ['class' => 'mailman-actions__note'], $this->plugin->gettext('mailman_actions_unavailable'));
        }

        return html::tag('div', ['class' => 'mailman-actions'], implode('', $buttons));
    }

    private function renderActionForm($action, $label, $listId, $token)
    {
        return html::tag('form', [
            'class' => 'mailman-action-form',
            'method' => 'post',
            'action' => './?_task=mailman&_action=' . $action,
        ],
            html::tag('input', ['type' => 'hidden', 'name' => '_list', 'value' => $listId]) .
            html::tag('input', ['type' => 'hidden', 'name' => '_token', 'value' => $token]) .
            html::tag('button', ['type' => 'submit', 'class' => 'button mainaction'], $this->plugin->gettext($label))
        );
    }

        private function isListOwner(array $list)
        {
          $role = strtolower((string) ($list['membership']['role'] ?? ''));

          return in_array($role, ['owner', 'administrator', 'admin'], true);
        }

        private function getListComposeRecipient(array $list)
        {
          $candidates = [
            (string) ($list['address'] ?? ''),
            (string) ($list['fqdn_listname'] ?? ''),
            (string) ($list['list_name'] ?? ''),
          ];

          if (!empty($list['mail_host']) && !empty($list['list_name'])) {
            $candidates[] = (string) $list['list_name'] . '@' . (string) $list['mail_host'];
          }

          if (!empty($list['list_id'])) {
            $candidates[] = $this->mapper->listIdToAddress((string) $list['list_id']);
          }

          foreach ($candidates as $candidate) {
            $value = strtolower(trim((string) $candidate));
            if ($value !== '' && strpos($value, '@') !== false && filter_var($value, FILTER_VALIDATE_EMAIL)) {
              return $value;
            }
          }

          return '';
        }

    private function renderDebugPanel(array $entries)
    {
        $items = [];

        foreach ($entries as $entry) {
            $event = rcube::Q((string) ($entry['event'] ?? 'debug'));
            $context = $entry['context'] ?? [];
            $json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                $json = '{}';
            }

            $items[] = html::tag('details', ['class' => 'mailman-debug__entry'],
                html::tag('summary', ['class' => 'mailman-debug__summary'], $event)
                . html::tag('pre', ['class' => 'mailman-debug__payload'], rcube::Q($json))
            );
        }

        return html::tag('section', ['class' => 'mailman-panel mailman-debug'],
            html::tag('h3', ['class' => 'mailman-panel__title'], $this->plugin->gettext('mailman_debug_title'))
            . implode('', $items)
        );
    }
}
