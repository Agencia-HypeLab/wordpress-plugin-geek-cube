# Changelog

All notable changes to Geek Cube Studio will be documented in this file.

## Unreleased

- Add a signed self-update dashboard with manual checks, native installation and patch diagnostics.
- Retain and publish only the latest three versioned release packages.
- Separate the artifact registry into type-specific tabs with category counts and preserved filters.
- Scope artifact imports to the active category tab, removing the global view and redundant type selector.
- Refresh the update control page and public manifest without cache; publish only the newest ZIP over FTP and remove prior remote ZIPs.
- Unlock the project Git SSH key at the beginning of a release so later push and tag operations do not time out waiting for its passphrase.
- Flush player rewrite rules once after a route-schema update, honor the configured player start behavior, and report live core FPS only in the compatibility laboratory.
- Add the native pt-BR catalogue for the administration and public player, with PO/MO verification in every release build.
- Add the Geek Cube Studio settings control center.
- Add language-aware route configuration prepared for Polylang Free.
- Add player, artifact, laboratory, save and account policy controls.
- Add immutable games, artifact versions, execution profiles and compatibility test records.
- Add protected laboratory and production player routes backed by self-hosted EmulatorJS packages.
- Seed the MIT-licensed Falling NES test entry and the FCEUmm runtime preset through permanent update patches.

## 0.1.0

- Add the initial plugin and release infrastructure.
