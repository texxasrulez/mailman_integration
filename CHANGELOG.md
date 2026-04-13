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

## Installation

1. Place the plugin in Roundcube's `plugins/mailman_integration/` directory.
2. Copy `config.inc.php.dist` to `config.inc.php` and set the Mailman API URL and credentials.
3. Add `mailman_integration` to Roundcube's `plugins` array.
4. Clear Roundcube caches if your deployment caches templates or plugin metadata.

## Configuration

Conservative defaults are used. Important settings:

- `mailman_integration_enabled`: master enable switch
- `mailman_integration_api_url`: Mailman REST base URL, typically ending in `/3.1`
- `mailman_integration_api_user` and `mailman_integration_api_password`: server-side REST credentials
- `mailman_integration_timeout`: API request timeout in seconds
- `mailman_integration_tls_verify`: disable only for controlled internal deployments
- `mailman_integration_show_directory`: allows browsing visible lists
- `mailman_integration_allow_subscribe`: enables subscribe actions
- `mailman_integration_allow_unsubscribe`: enables unsubscribe actions
- `mailman_integration_show_archives`: exposes archive links when Mailman returns them
- `mailman_integration_show_list_settings`: exposes a small amount of extra metadata
- `mailman_integration_allowed_lists` and `mailman_integration_blocked_lists`: explicit allow/deny controls
- `mailman_integration_exposed_domains`: optional domain gate for visible lists
- `mailman_integration_cache_ttl`: short-lived cache for list and health lookups
- `mailman_integration_compose_detection`: enables compose-time list recognition
- `mailman_integration_add_list_headers`: enables conditional outbound list header injection
- `mailman_integration_add_list_unsubscribe`: controls `List-Unsubscribe`
- `mailman_integration_allow_identity_aliases`: when false, only the primary active email is used

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

## Versioning

- `mailman_integration` now keeps its canonical version in `mailman_integration::PLUGIN_VERSION` inside `mailman_integration.php`.
- `mailman_integration::info()` exposes the plugin metadata array used for self-identification.
- Development builds should use a `+dev` suffix such as `1.0.0+dev`.
- Release builds should use a clean tagged version such as `1.0.0`.

For a release bump:
1. Update `mailman_integration::PLUGIN_VERSION` in `mailman_integration.php` or run `sh scripts/bump-version.sh 1.0.0`.
2. Update `CHANGELOG.md`.
3. Create the matching release tag after verification.

# Changelog

All notable changes to `mailman_integration` should be documented in this file.

## [Unreleased]

- Ongoing development builds use `mailman_integration::PLUGIN_VERSION` with a `+dev` suffix until the next release is cut.

## [1.0.0] - 2026-04-12

- Formalized the plugin's self-metadata through `mailman_integration::PLUGIN_VERSION` and `mailman_integration::info()`.
- Aligned self-versioning with a cleaner release workflow while keeping existing plugin behavior intact.
