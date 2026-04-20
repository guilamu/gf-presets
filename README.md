# GF Presets

Global preset library for Gravity Forms fields, notifications, and confirmations. Save once, apply anywhere — as a one-time copy or a synced live link.

## Preset Library

- Save any field, notification, or confirmation as a reusable preset
- Organize presets with names and descriptions
- Filter by type: Fields, Notifications, Confirmations
- Search, duplicate, edit, or delete presets from a central library page

## Live Link Sync

- Apply presets as a **Copy** (independent) or a **Live Link** (synced)
- Live-linked objects stay in sync: edit one form, changes propagate to all linked forms automatically
- Per-setting exclusion: unlink individual settings (e.g., label, description) while keeping the rest synced
- Visual indicators show which fields are live-linked and which settings are excluded
- Conflict detection prevents overwriting local edits on target forms

## Conditional Logic & Merge Tags

- Conditional logic rules are saved with label-based mapping for portability across forms
- When applying a preset, conditional logic is automatically remapped by matching field labels
- Merge tag warnings alert you when a preset references field IDs that may not exist in the target form

## Key Features

- **Multilingual:** Works with content in any language
- **Translation-Ready:** All strings are internationalized
- **Secure:** Nonce-verified REST API with capability checks on every endpoint
- **GitHub Updates:** Automatic updates from GitHub releases

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- Gravity Forms 2.5 or higher

## Installation

1. Upload the `gf-presets` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Forms → Presets** to access the Preset Library
4. Open any form in the editor to save or apply field presets
5. Open any notification or confirmation editor to save or apply those presets

## FAQ

### How do I save a field as a preset?

Open the form editor, select a field, then open the **Field Preset** tab in the field settings sidebar. Click **Save as Preset**, enter a name, and confirm.

### What is the difference between Copy and Live Link?

**Copy** creates an independent snapshot — the field won't change if the preset is updated later. **Live Link** keeps the field synced: any change to the preset (or to any linked form's field) is automatically pushed to all other linked forms.

### Can I exclude specific settings from sync?

Yes. When a field is live-linked, each setting (label, description, placeholder, etc.) shows a link/unlink icon. Click it to exclude that setting from future syncs.

### What happens to conditional logic when applying a preset?

Conditional logic rules are remapped by matching field labels in the target form. If a referenced field doesn't exist in the target form, that rule is dropped. A report shows what was remapped and what was dropped.

### What happens if I delete a preset that has live links?

Deleting a preset breaks all live links. Linked forms keep their current settings but will no longer receive sync updates.

### Does it work with notifications and confirmations?

Yes. You can save and apply presets for notifications and confirmations using the toolbar that appears on their respective editor pages.

## Project Structure

```
.
├── gf-presets.php                          # Main plugin file (bootstrap)
├── class-gf-presets.php                    # GFAddOn class (REST API, sync, settings)
├── uninstall.php                           # Cleanup on uninstall
├── README.md
├── admin
│   ├── class-preset-admin.php              # Preset Library page renderer
│   └── views
│       ├── modal-apply.php                 # Apply preset modal (Copy / Live Link)
│       └── modal-save.php                  # Save preset modal
├── css
│   └── gf-presets.css                      # Admin styles
├── includes
│   ├── class-cl-remapper.php               # Conditional logic label-based remapper
│   ├── class-github-updater.php            # GitHub auto-updates
│   ├── class-merge-tag-scanner.php         # Merge tag detection in payloads
│   ├── class-preset-confirmation.php       # Confirmation apply logic
│   ├── class-preset-field.php              # Field apply logic with CL remapping
│   ├── class-preset-link-store.php         # Live link DB operations
│   ├── class-preset-notification.php       # Notification apply logic
│   ├── class-preset-store.php              # Preset CRUD DB operations
│   ├── class-sync-engine.php               # Live link sync engine
│   └── Parsedown.php                       # Markdown parser for View Details
├── js
│   ├── field-preset-editor.js              # Field editor sidebar (save/apply/sync icons)
│   ├── notification-preset-bar.js          # Notification/confirmation editor toolbar
│   └── preset-library.js                   # Preset Library page JS
└── languages
    ├── gf-presets-fr_FR.mo                 # French translation (binary)
    ├── gf-presets-fr_FR.po                 # French translation (source)
    └── gf-presets.pot                      # Translation template
```

## Changelog

### 0.9.1
- Fix: consent field (and similar) false sync conflicts caused by PHP runtime-only properties
- Fix: per-setting sync exclusions (excluded keys) now respected in all code paths — source form save, REST manual sync, and target conflict detection
- Fix: synced_hash now computed from filtered payload, preventing perpetual conflict mismatch

### 0.9.0
- Initial release
- Preset library with save, apply, edit, duplicate, delete
- Copy and Live Link apply modes
- Conditional logic label-based remapping
- Merge tag detection and warnings
- Field, notification, and confirmation preset support
- Live Link sync — changes propagate automatically to all linked forms
- Per-setting sync exclusion with visual link/unlink icons
- Conflict detection prevents overwriting local edits
- Field Preset collapsible tab in the field settings sidebar
- Sync warning when editing a live-linked field
- GitHub auto-updates from releases
- Guilamu Bug Reporter integration
- Hide save/load controls when a field is live-linked
- Visual link indicator badge on linked fields

## License

This project is licensed under the GNU Affero General Public License v3.0 (AGPL-3.0) - see the [LICENSE](LICENSE) file for details.

---

<p align="center">
  Made with love for the WordPress community
</p>
