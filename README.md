# SnipCore

A lightweight WordPress plugin for creating, managing, and running **PHP, HTML, CSS, and JavaScript snippets** from a single native WordPress admin interface.

SnipCore lets you add custom code without directly editing theme or plugin files.

## Features

* **PHP Snippets** — Add and run custom PHP code.
* **HTML Snippets** — Insert custom HTML into supported locations.
* **CSS Snippets** — Add custom CSS without editing theme files.
* **JavaScript Snippets** — Add custom JavaScript to your site.
* **Flexible Locations** — Control where each snippet type runs or is inserted.
* **Display Conditions** — Show or hide snippets on specific posts or pages.
* **Device Conditions** — Target desktop or mobile visitors.
* **Scheduling** — Optionally control when a snippet should run using a start/end schedule.
* **Global Header & Footer** — Add site-wide code through dedicated Header and Footer fields.
* **Import / Export** — Import and export snippets using JSON or XML.
* **Complete Export** — Export plugin data for backup or migration.
* **Snippet Management** — Clone, activate, deactivate, trash, restore, and permanently delete snippets.
* **Bulk Actions** — Clone, trash, and export multiple snippets at once.
* **Safe Mode** — Helps recover from faulty PHP snippets that cause fatal errors.
* **Multisite Awareness** — Designed to keep snippet and settings data properly scoped per site.
* **Lightweight** — Admin-only functionality is kept separate from frontend requests where possible.

## Supported Snippet Types

| Type       | Purpose                      |
| ---------- | ---------------------------- |
| PHP        | Run custom PHP functionality |
| HTML       | Add custom HTML markup       |
| CSS        | Add custom styles            |
| JavaScript | Add custom JavaScript        |

Each snippet type provides only the location and configuration options relevant to that type.

## Safe Mode

SnipCore includes a recovery mechanism for faulty PHP snippets.

If a PHP snippet causes a fatal error, SnipCore can disable the problematic snippet to help prevent the same code from continuously breaking the site.

An emergency option is also available to disable snippet execution when manual recovery is required.

Your snippet code and data are preserved when a snippet is disabled.

## Requirements

* WordPress **5.8+**
* PHP **7.4+**

## Installation

### From WordPress

1. Download the plugin ZIP.
2. Go to **Plugins → Add New → Upload Plugin** in WordPress.
3. Upload the SnipCore ZIP file.
4. Install and activate the plugin.
5. Open **SnipCore** from the WordPress admin menu.

### Manual Installation

1. Download or clone this repository.
2. Copy the `snipcore` directory to:

```text
/wp-content/plugins/
```

3. Activate **SnipCore** from the WordPress Plugins screen.

## Usage

After activation:

1. Open **SnipCore** from the WordPress admin menu.
2. Create a new snippet.
3. Select the appropriate snippet type.
4. Add your code.
5. Configure the available location and display options.
6. Save and activate the snippet.
7. Verify the result on your site.

## Security

SnipCore restricts snippet management to authorized WordPress administrators.

The plugin uses WordPress capabilities, nonces, sanitization, validation, and escaping where appropriate to protect administrative functionality.

**Important:** PHP snippets contain executable code. Only add PHP code that you understand and trust.

## Data & Uninstall

Deactivating SnipCore does not delete your snippets or settings.

By default, uninstalling the plugin preserves your data so it can be restored if the plugin is installed again.

An optional setting allows administrators to permanently delete SnipCore data during uninstall.

## Compatibility

SnipCore is built for standard WordPress environments and is designed with WordPress Multisite compatibility in mind.

The plugin has been tested against its supported WordPress and PHP requirements.

## Development

Clone the repository:

```bash
git clone https://github.com/YOUR-USERNAME/snipcore.git
```

Replace `YOUR-USERNAME` with your GitHub username or organization.

## Contributing

Contributions, bug reports, and feature suggestions are welcome.

Before submitting a pull request:

1. Test your changes on a supported WordPress version.
2. Check for PHP errors and warnings.
3. Verify that existing snippet functionality continues to work.
4. Keep changes focused and consistent with the existing codebase.

## Bug Reports & Feature Requests

If you find a bug or have a feature request, please open an issue in the GitHub repository with:

* A clear description of the issue.
* Steps to reproduce it.
* WordPress version.
* PHP version.
* Relevant browser/console errors.
* Any relevant screenshots or logs.

## License

SnipCore is licensed under the **GNU General Public License v2.0 or later (GPL-2.0-or-later)**.

See the `LICENSE` file for details.

## Version

**Current version:** `1.0.0`

**Initial release:** SnipCore 1.0.0
