# Finishcoat

Finishcoat is a WordPress plugin for website builders. Your client opens a review link, pins notes to any spot on the real page, attaches photos and tries their own photo in place. Nothing on the site changes until you approve it.

- Plugin page: https://wilderlabs.studio/finishcoat/
- WordPress.org listing: coming once the plugin is approved
- Support: finishcoat@wilderlabs.studio, or the WordPress.org support forum once the listing is live

## Source code

This repository is the plugin exactly as it ships. Nothing is minified, compiled or bundled, and there is no build step. The JavaScript and CSS in `assets/` are the files we edit.

## Install from here

Download this repository as a zip, rename the folder inside it to `finishcoat` if needed, and upload it in WordPress under Plugins > Add New > Upload Plugin. Or clone it into `wp-content/plugins/finishcoat`.

Requires WordPress 6.5 or later and PHP 7.4 or later.

## Releasing

1. Set the new version in three places: the `Version:` header and `FINISHCOAT_VERSION` in `finishcoat.php`, and `Stable tag:` in `readme.txt`. Add a changelog entry to `readme.txt`.
2. Commit, then publish a GitHub release tagged with that version (`1.0.1` or `v1.0.1`).
3. The `Deploy to WordPress.org` workflow checks the three versions match the tag, then pushes the plugin (without the files in `.distignore`) and the `.wordpress-org` assets to WordPress.org SVN.

## License

GPLv2 or later. See `LICENSE`.

Made by Wilder Labs Studio in Reno, Nevada.
