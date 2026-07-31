# Changelog

All notable changes to the Extension are documented in this file.

## 1.3.2 - Unreleased
- Fix: the "Read more..." link on category pages and the "Custom Fields" heading on article pages are no longer hard-coded; both are translated through language constants and follow the site language
- Fix: the debug report available through `?output=markdown&debug=1` no longer prints a hard-coded version number; it now reads the version from the manifest
- Improvement: the type prefix in the plugin name is now translated in Spanish, French, Italian and Dutch (`Sistema -`, `Système -`, `Sistema -`, `Systeem -`)
- Improvement: PHP file headers updated to the standard Joomla docblock copyright format; code style only, no functional changes
- Improvement: full code style pass against the Joomla CMS phpcs ruleset (PSR-12): phpcbf auto-fixes for indentation, line endings, brace placement and whitespace, plus phpcs annotations for deliberate exceptions (`_JEXEC` guards, legacy global class names, legacy API naming). Code style only, no functional changes

## [1.3.1] - 08/07/2026
- Addition: Downloads from the Joomill update server now include diagnostic request headers with site and environment information

## [1.3.0] - 04/07/2026
- Addition: help buttons now link to the Joomill documentation page
- Addition: Support Plugin lazy loading for PHP >= 8.4: Added a possibility to load plugin class on demand (lazy loading) when the event dispatched. For servers with PHP version >= 8.4.
- Addition: Added German, French, Italian and Spanish translations.
- Addition: Added an installer script with a thank-you/quickstart screen on install and uninstall (Joomill standard).
