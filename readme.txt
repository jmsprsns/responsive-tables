=== Responsive Tables ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: table, responsive table, comparison table, tablepress, pricing table
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tables that stay readable on phones. Rows become cards, open with a tap, or scroll with a frozen first column. Spreadsheet-style editing and one-click TablePress import.

== Description ==

Tables break on phones. Wide comparison tables, pricing grids and spec sheets either shrink to unreadable text or scroll sideways where most visitors never look. Responsive Tables builds tables that reorganize themselves on small screens, so every row stays readable without pinching or scrolling.

Build a table with the Responsive Table block, then pick how it behaves on phones:

* Stack: each row becomes a card, with the column headers as labels beside each value.
* Collapse: rows shrink to their first column and open with a tap.
* Scroll: the table keeps its shape and scrolls sideways, with the first column frozen.

Each table switches at a width you choose, and it goes by the space the table actually has, so a table in a narrow column on a desktop switches too. The phone layouts are pure CSS. A small script (about 2.5 KB) loads only on pages with a table that sorts, searches or splits into pages.

= Features =

* Stack, Collapse and Scroll layouts on phones, free.
* A breakpoint per table, or one for the whole site.
* Sorting, search and pagination, each optional. On phones, a "Sort by" menu stands in for the column headers.
* Spreadsheet-style editing: paste a range from Excel or Google Sheets, Enter and Tab to move between cells, Shift+click to select a range, CSV import and export.
* Starter tables: product comparison, pricing plans, schedule and spec sheet.
* Highlight a column or a row, with a badge like "Best value" or "Our pick".
* Cell shortcuts for check marks, crosses, star ratings, buttons and images, plus links and other HTML.
* Accessible: real table headers, ARIA roles kept in every layout, labelled controls and announced search results.
* Saved tables for reuse anywhere with [responsive_table id="123"].
* TablePress import in one click. Your [table id=N /] shortcodes and TablePress blocks keep working, even after TablePress is deactivated.
* Free, with no Pro tier.

Find the settings under Content Powered > Responsive Tables in the admin menu.

= Cell shortcuts =

* {yes} and {no} show a check mark or a cross. Add text after it: {yes Free returns}.
* {stars 4.5} shows a star rating out of 5, or out of 10 with {stars 8/10}.
* {button Buy now | https://example.com | new, sponsored} shows a button link.
* {image 123} shows an image from the media library.

= Safe for any site =

Cells can hold links, bold text, images and other HTML allowed in posts. Scripts, iframes and event handlers are always removed when a table is shown, whoever wrote it, including tables imported from TablePress. Saved tables have no public URL, and only people who can edit them can read them through the REST API.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `responsive-tables` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. In the block editor, press + and add a Responsive Table block. Site-wide defaults are under Content Powered > Responsive Tables.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Which phone layout should I use? =

Stack suits comparisons and product tables, where each row is one thing. Collapse suits long tables people scan, like schedules or directories. Scroll suits wide grids of numbers, where comparing across a row matters more than reading one cell.

= How do I move my TablePress tables over? =

Go to Content Powered > Responsive Tables > TablePress Import and press Import. Each table is copied with its header and footer rows, name, description and its sorting, search and pagination settings. From then on, [table id=N /] shortcodes and TablePress blocks show the imported copy, so nothing in your posts needs editing. TablePress itself isn't changed, and you can deactivate it once you've checked the tables.

= What about formulas and merged cells from TablePress? =

Formulas are imported as their results when TablePress is active during the import. Merged cells are split, because merged cells can't reflow into cards on phones. The import screen lists anything like this for each table.

= Do the tables work without JavaScript? =

Yes. The phone layouts, including tap-to-open rows, are pure CSS. Without JavaScript, every row of a paged table is shown.

= Will search engines see every row? =

Yes. Every row is in the page's HTML, even on tables split into pages.

= Can I use a table in a widget or a page builder? =

Save it as a saved table (Content Powered > Responsive Tables > Add New Table) and use its shortcode, [responsive_table id="123"].

= What happens to my tables if I delete the plugin? =

Settings and saved tables are deleted. Tables built inside posts stay in those posts and show again if you reinstall. TablePress's tables are never touched.

== Changelog ==

= 1.0.1 =

* Scroll layout: the frozen first column stays solid when a row is hovered, striped or highlighted, so columns scrolling under it no longer show through.
* Clearer sort arrows in column headers.

= 1.0.0 =

* First release.

See https://github.com/jmsprsns/responsive-tables/releases for the full release history.
