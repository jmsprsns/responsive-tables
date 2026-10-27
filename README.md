# Responsive Tables

Tables that stay readable on phones. Rows become cards, open with a tap, or scroll with a frozen first column. Spreadsheet-style editing, sorting, search, pagination and one-click TablePress import.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## What it does

Tables are built with the **Responsive Table** block. When a table has less room than its breakpoint, it switches to its phone layout:

- **Stack**: each row becomes a card, with the column headers as labels beside each value.
- **Collapse**: rows shrink to their first column and open with a tap.
- **Scroll**: the table keeps its shape and scrolls sideways, with the first column frozen (columns stop shrinking at the breakpoint).

The switch is a CSS container query on the table's wrapper, so it follows the space the table has, not the screen: a table in a narrow sidebar or column switches too, and the editor's Phone preview shows the real phone layout. Browsers without container queries fall back to a media query. The rules for each breakpoint are printed once per page, just before the first table that uses it (`Responsive_Tables_Render::breakpoint_css()`); everything else is in `assets/table.css`.

Other features: a "Sort by" menu that stands in for the headers on phones, search, pages, highlighted columns and rows with badges, cell shortcuts (`{yes}`, `{no}`, `{stars 4.5}`, `{button Text | URL | new, sponsored}`, `{image 123}`), starter tables, CSV import and export, and saved tables for reuse with `[responsive_table id="123"]`.

Settings live under **Content Powered > Responsive Tables** (tabs: Tables, Defaults, TablePress Import, Help).

### How tables are stored

- A table in a post is a dynamic block: the cells live in the block's attributes (`rows`, plus `columns` and `rowOpts` lined up with them) and the server draws the HTML. Markup and security fixes reach every table without re-saving posts.
- Saved tables are private `rtbl_table` posts whose content is one Responsive Table block, edited in the block editor with the block locked in place. The block can point at one (`ref`), and `[responsive_table id="123"]` shows one.
- TablePress imports become saved tables. `responsive_tables_tablepress_map` maps TablePress IDs to them, and with the "TablePress shortcodes" setting on (it turns on after the first import), `[table id=N /]`, `[table-info]` and the `tablepress/table` block show the imported copy. IDs that weren't imported go back to whatever registered the shortcode before (TablePress, while it's active).

### Security

- Every cell goes through `wp_kses()` with the post tags every time it's shown, whoever wrote it, imported tables included. Cell shortcuts are expanded afterwards, only in text between tags, into markup built from validated values (button links allow http, https, mailto and tel only).
- The accent colour must be a hex colour, breakpoints and page sizes are clamped whole numbers, and class names go through `sanitize_html_class()`. Nothing else reaches the CSS.
- Saved tables have no public URL. Their REST controller (`Responsive_Tables_REST_Controller`) requires edit rights to list or read them, published or not, and the block's picker route only lists published ones. A draft can't be shown by a shortcode or a `ref`.
- The TablePress import is an admin-ajax action with a nonce and `manage_options`, 5 tables per request.
- Limits: 5,000 rows, 60 columns, 60,000 cells, 20,000 characters per cell, tables nested at most 3 deep, and a saved table can't contain itself.
- The front-end script only moves and hides rows and writes text with `textContent`. CSV exports prefix cells that would run as spreadsheet formulas.

### Hooks and styling

| Hook | Use |
|---|---|
| `responsive_tables_cell_allowed_html` (filter) | HTML tags and attributes allowed in cells (the post tags by default). |

Custom properties on `.rtbl`: `--rtbl-accent` (set per table), `--rtbl-surface` (behind the frozen first column; set it for dark themes), `--rtbl-card`, `--rtbl-line`, `--rtbl-radius`. For tables added to a page after it loads, call `window.responsiveTablesInit()`.

Rebuild `assets/table.min.js` with `bin/build.sh` after editing `assets/table.js`. The block editor (`assets/block.js`) is plain JavaScript without a build step.

## Automatic updates

Updates come from https://github.com/jmsprsns/responsive-tables through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `responsive-tables.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `responsive-tables.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Publish a release whose tag matches that version:

   ```bash
   gh release create v1.0.1 --title 1.0.1 --generate-notes
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'RESPONSIVE_TABLES_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'RESPONSIVE_TABLES_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
responsive-tables.php                               Plugin header, constants, boot
uninstall.php                                       Deletes settings and saved tables
readme.txt                                          WordPress readme; feeds the "View details" popup
bin/build.sh                                        Rebuilds assets/table.min.js (not shipped)
includes/
  class-responsive-tables-plugin.php                Main singleton and settings
  class-responsive-tables-render.php                Table markup, breakpoint CSS, asset loading
  class-responsive-tables-cells.php                 Cell cleaning and cell shortcuts
  class-responsive-tables-block.php                 The Responsive Table block
  class-responsive-tables-library.php               Saved tables: post type, shortcode, REST routes
  class-responsive-tables-rest-controller.php       Saved tables REST controller (edit rights only)
  class-responsive-tables-tablepress.php            TablePress import and shortcode takeover
  class-responsive-tables-admin.php                 Content Powered > Responsive Tables page
  class-responsive-tables-menu.php                  Shared top-level "Content Powered" menu
  class-responsive-tables-feedback.php              Shared Submit Feedback and Report a Bug pages
  class-responsive-tables-updater.php               GitHub update checks (PUC wiring)
  class-responsive-tables-icons.php                 Inline Lucide icon helper
assets/
  table.css                                         Front-end styles (also in the editor)
  table.js, table.min.js                            Sorting, search and pages (front end, only where used)
  block.js, block-editor.css                        The block editor's spreadsheet, previews and sidebar
  admin.css, admin.js                               Loaded only on the settings page
  icon.svg                                          Icon on the update screens
  content-powered.svg                               Sumo logo for the Content Powered menu
icons/                                              Lucide icons used by the settings page (plus LICENSE)
plugin-update-checker/                              Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-responsive-tables-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-responsive-tables-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icons it uses in `icons/`. Print one inline with:

```php
echo Responsive_Tables_Icons::get( 'package', 20 );           // name, size in px
echo Responsive_Tables_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
