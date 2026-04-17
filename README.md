# mailman_integration

![Downloads](https://img.shields.io/github/downloads/texxasrulez/mailman_integration/total?style=plastic&logo=github&logoColor=white&label=Downloads&labelColor=aqua&color=blue)
[![Packagist Downloads](https://img.shields.io/packagist/dt/texxasrulez/mailman_integration?style=plastic&logo=packagist&logoColor=white&label=Downloads&labelColor=blue&color=gold)](https://packagist.org/packages/texxasrulez/mailman_integration)
[![Packagist Version](https://img.shields.io/packagist/v/texxasrulez/mailman_integration?style=plastic&logo=packagist&logoColor=white&label=Version&labelColor=blue&color=limegreen)](https://packagist.org/packages/texxasrulez/mailman_integration)
[![Github License](https://img.shields.io/github/license/texxasrulez/mailman_integration?style=plastic&logo=github&label=License&labelColor=blue&color=coral)](https://github.com/texxasrulez/mailman_integration/LICENSE)
[![GitHub Stars](https://img.shields.io/github/stars/texxasrulez/mailman_integration?style=plastic&logo=github&label=Stars&labelColor=blue&color=deepskyblue)](https://github.com/texxasrulez/mailman_integration/stargazers)
[![GitHub Issues](https://img.shields.io/github/issues/texxasrulez/mailman_integration?style=plastic&logo=github&label=Issues&labelColor=blue&color=aqua)](https://github.com/texxasrulez/mailman_integration/issues)
[![GitHub Contributors](https://img.shields.io/github/contributors/texxasrulez/mailman_integration?style=plastic&logo=github&logoColor=white&label=Contributors&labelColor=blue&color=orchid)](https://github.com/texxasrulez/mailman_integration/graphs/contributors)
[![GitHub Forks](https://img.shields.io/github/forks/texxasrulez/mailman_integration?style=plastic&logo=github&logoColor=white&label=Forks&labelColor=blue&color=darkorange)](https://github.com/texxasrulez/mailman_integration/forks)
[![Donate Paypal](https://img.shields.io/badge/Paypal-Money_Please!-blue.svg?style=plastic&labelColor=blue&color=forestgreen&logo=paypal)](https://www.paypal.me/texxasrulez)

`mailman_integration` is a Roundcube plugin that exposes a small, admin-friendly Mailman 3 feature set inside Roundcube. It focuses on list discovery for the logged-in user, optional directory browsing, safe list metadata, simple member actions, and conservative outbound list headers.

## v1 scope

Included in v1:

- plugin bootstrap, task registration, and native Roundcube rendering
- Mailman 3 REST API access from PHP only
- degraded mode when Mailman is missing, unreachable, or misconfigured
- "My Lists" view based on the active Roundcube identity
- optional browseable directory of lists
- subscribe and unsubscribe actions when enabled by config
- basic list detail view
- compose-time recognition of known list recipients
- outbound `List-*` headers only when the message targets one recognized Mailman list

Intentionally excluded from v1:

- moderation queue handling
- owner or site-admin controls
- full Postorius replacement
- deep member preference management
- bounce and delivery diagnostics
- local database storage

## Requirements

- Roundcube 1.5 or later
- PHP 7.4 or later
- Mailman 3 with the REST API enabled and reachable from the web server

## Installation

### Via Composer (recommended)

```bash
composer require texxasrulez/mailman_integration
```

Then add `mailman_integration` to the `plugins` array in Roundcube's `config/config.inc.php`.

### Manual

1. Download or clone this repository into Roundcube's `plugins/mailman_integration/` directory.
2. Copy `config.inc.php.dist` to `config.inc.php`:

   ```bash
   cp plugins/mailman_integration/config.inc.php.dist plugins/mailman_integration/config.inc.php
   ```

3. Edit `config.inc.php` and set at minimum the API URL and credentials (see [Configuration](#configuration)).
4. Add `mailman_integration` to the `plugins` array in Roundcube's `config/config.inc.php`:

   ```php
   $config['plugins'] = ['mailman_integration', /* other plugins */];
   ```

5. Clear Roundcube caches if your deployment caches templates or plugin metadata.

## Configuration

Copy `config.inc.php.dist` to `config.inc.php` in the plugin directory to get started. All settings have safe defaults except the API connection options, which must be set for the plugin to function.

### API connection

| Setting | Default | Description |
| --- | --- | --- |
| `mailman_integration_enabled` | `true` | Master on/off switch |
| `mailman_integration_api_url` | — | Mailman REST base URL, e.g. `https://lists.example.com/3.1` |
| `mailman_integration_api_user` | — | REST API username (set in `mailman.cfg`) |
| `mailman_integration_api_password` | — | REST API password |
| `mailman_integration_timeout` | `8` | API request timeout in seconds |
| `mailman_integration_tls_verify` | `true` | Verify TLS certificates; disable only for internal deployments |
| `mailman_integration_postorius_url` | `''` | Optional Postorius URL used to link the word `Mailman` in the health status line |
| `mailman_integration_health_path` | `/system` | Path used to probe Mailman health |
| `mailman_integration_health_fallback_path` | `/lists` | Fallback probe path if the primary returns an error |

### List visibility

| Setting | Default | Description |
| --- | --- | --- |
| `mailman_integration_show_directory` | `false` | Show a browseable directory of all advertised lists |
| `mailman_integration_allow_subscribe` | `false` | Enable subscribe actions for users |
| `mailman_integration_allow_unsubscribe` | `true` | Enable unsubscribe actions for users |
| `mailman_integration_show_archives` | `false` | Show archive links when Mailman provides a URL |
| `mailman_integration_show_list_settings` | `false` | Show extra list metadata (volume, member count, etc.) |
| `mailman_integration_allowed_lists` | `[]` | If non-empty, only these list IDs are shown |
| `mailman_integration_blocked_lists` | `[]` | List IDs to always hide |
| `mailman_integration_exposed_domains` | `[]` | If non-empty, only lists hosted on these domains are shown |

### Compose settings

| Setting | Default | Description |
| --- | --- | --- |
| `mailman_integration_compose_detection` | `true` | Detect list recipients while composing |
| `mailman_integration_compose_widget` | `true` | Inject the list info widget into the compose page |
| `mailman_integration_owner_tools` | `true` | Show owner send options when a list address is in recipients |
| `mailman_integration_preflight_require_subject` | `true` | Block sending to a list when the subject line is empty |
| `mailman_integration_preflight_confirm_send` | `true` | Prompt for confirmation before sending to a list |
| `mailman_integration_preflight_append_unsubscribe_footer` | `false` | Append an unsubscribe footer when the owner enables it |
| `mailman_integration_unsubscribe_footer_template` | see dist | Footer template; supports `{list_address}` placeholder |
| `mailman_integration_allow_identity_aliases` | `false` | When `true`, all identity addresses are checked for membership, not just the primary |

### Outbound headers

| Setting | Default | Description |
| --- | --- | --- |
| `mailman_integration_add_list_headers` | `true` | Inject `List-*` headers when sending to exactly one recognized list |
| `mailman_integration_add_list_unsubscribe` | `true` | Include `List-Unsubscribe` in injected headers |

### Message templates

Pre-fill subject and body when composing to a list. Defined as an array in `config.inc.php`:

```php
$config['mailman_integration_message_templates'] = [
    ['name' => 'Announcement', 'subject' => 'Announcement', 'body' => "Dear subscribers,\n\n"],
    ['name' => 'Newsletter',   'subject' => 'Monthly Newsletter', 'body' => "Hello everyone,\n\n"],
];
```

Leave the array empty (the default) to hide the template picker.

### Diagnostics

| Setting | Default | Description |
| --- | --- | --- |
| `mailman_integration_cache_ttl` | `120` | Seconds to cache list and health data |
| `mailman_integration_log_level` | `warning` | Log verbosity: `debug`, `info`, `warning`, `error` |
| `mailman_integration_debug` | `false` | Write detailed debug entries to `logs/mailman_integration` |

## Usage

### Accessing the Mailman task

After installation the Mailman icon appears in the Roundcube taskbar. Click it to open the **Mailman Lists** page.

### My Lists

The left panel lists every Mailman list the logged-in user is subscribed to (matched against the active Roundcube identity). Selecting a list loads its detail view on the right, showing the list address, description, and available actions.

### Directory

When `mailman_integration_show_directory` is `true`, all publicly advertised lists appear in a second panel below **My Lists**. Users can browse and, if subscribe actions are enabled, join lists from this view.

### Subscribe / Unsubscribe

Subscribe and unsubscribe buttons appear in the list detail view when the corresponding config options are enabled (`mailman_integration_allow_subscribe`, `mailman_integration_allow_unsubscribe`). Actions are confirmed via a page-level flash message.

### Compose integration

When composing a message, the plugin checks the **To**, **Cc**, and **Bcc** fields against known list addresses. If a match is found:

- A widget appears below the header area showing the matched list name and address.
- If `mailman_integration_owner_tools` is enabled and the user is a list owner, additional send options appear inline:
  - **Require subject** — blocks send if the subject line is empty.
  - **Confirm before send** — shows a confirmation prompt before submitting.
  - **Append unsubscribe footer** — appends the configured footer template to the message body.

Disable the widget entirely with `mailman_integration_compose_widget = false` while keeping outbound header injection active.

### Send to List

From a list detail page, click **Send to List** to open a compose window pre-addressed to the list. If message templates are configured, a template picker appears next to the send button to pre-fill subject and body.

## Skin support

The plugin ships explicit support for:

- `elastic`
- `larry`
- `autumn_larry`
- `black_larry`
- `blue_larry`
- `green_larry`
- `grey_larry`
- `pink_larry`
- `plata_larry`
- `summer_larry`
- `teal_larry`
- `violet_larry`
- `classic`

The Larry color variants are created as real skin directories and mirror the Larry templates, styles, and image assets so Roundcube can resolve them without broken paths.

## Mailman failure handling

If Mailman is absent, unreachable, or misconfigured:

- the plugin stays loaded
- the lists page renders a degraded status message
- write actions are suppressed
- transport details are not leaked into the UI

## Outbound header behavior

The plugin only adds list-style outbound headers when it can match the outgoing recipients to exactly one recognized Mailman list address. It does not add list headers to ordinary mail, and it does not guess when the target is ambiguous.

Headers currently added when configured and available:

- `List-Id`
- `X-BeenThere`
- `List-Unsubscribe`
- `List-Archive`

## Roundcube version notes

The plugin follows common Roundcube plugin patterns, but hook payload details can differ slightly between Roundcube releases. The message-send hooks and compose-header injection are the most likely places to need small adjustments on older or customized installations.

## Compatibility troubleshooting

If compose loads with disabled toolbar actions (`Send`, `Save`, `Cancel`) or the browser console shows `$ is not a function`, the issue is almost always a JavaScript conflict from another plugin overriding the global jQuery symbol.

Suggested triage order:

1. Temporarily disable third-party compose-related plugins.
2. Hard-refresh Roundcube and verify core compose works from the native `New message` action.
3. Re-enable plugins one by one until the conflict reappears.
4. If needed, keep Mailman compose recognition active but disable widget injection by setting `mailman_integration_compose_widget` to `false`.

Known real-world conflict observed during testing:

- `markdown_editor` (caused global jQuery collisions and blocked compose initialization)

## Smoke test checklist

Run this quick check after upgrades or plugin changes:

1. Open Mailman task and verify list/taskbar icon visibility for your active skin.
2. Open a list detail page and click `Send to List` (owner role).
3. Confirm compose opens with recipient prefilled and toolbar actions enabled.
4. Send a test message to one list and confirm no send-time server error.
5. Inspect received message headers and verify `List-Id` and `X-BeenThere` are present.
6. If `mailman_integration_show_archives` is enabled, verify `List-Archive` is present.

## Translation workflow

This repository includes `translate_locales.php` to update all locale files in one batch with safer handling for placeholders and markup.

Recommended run with DeepL and Libre fallback:

```bash
php translate_locales.php \
  --provider=deepl \
  --deepl-key=YOUR_DEEPL_KEY \
  --deepl-plan=free \
  --fallback-provider=libre \
  --lt-url=http://localhost:5000
```

Useful flags:

- `--force=1`: re-translate all keys, not just missing/empty ones
- `--dry-run=1`: simulate translation without writing files
- `--locales=es_ES,fr_FR,de_DE`: process only selected locales
- `--exclude-locales=ja_JP,zh_TW`: skip specific locales
- `--report=localization/mt_report.json`: custom report path

Behavior notes:

- English variants (`en_*`) are copied from source text by design.
- Unsupported locales for the primary provider are captured in the report and can be handled by fallback provider.
- Placeholder tokens and simple markup are masked before translation and restored after translation.

## Versioning

- `mailman_integration` now keeps its canonical version in `mailman_integration::PLUGIN_VERSION` inside `mailman_integration.php`.
- `mailman_integration::info()` exposes the plugin metadata array used for self-identification.
- Development builds should use a `+dev` suffix such as `1.0.0+dev`.
- Release builds should use a clean tagged version such as `1.0.0`.

For a release bump:

1. Update `mailman_integration::PLUGIN_VERSION` in `mailman_integration.php` or run `sh scripts/bump-version.sh 1.0.0`.
2. Update `CHANGELOG.md`.
3. Create the matching release tag after verification.
