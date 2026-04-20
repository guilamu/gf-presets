<?php
/**
 * Preset Library Card List Template.
 *
 * This file is not directly included — the card HTML is built in JS.
 * This file documents the card structure for reference.
 *
 * The actual card rendering happens in preset-library.js using
 * the data returned from GET /presets.
 */

defined( 'ABSPATH' ) || exit;

// This file intentionally left as documentation only.
// The card list is rendered dynamically via JavaScript.
//
// Card HTML structure (rendered by JS):
//
// <div class="gf-presets-card" data-id="{id}" data-type="{preset_type}">
//   <div class="gf-presets-card-header">
//     <span class="gf-presets-card-icon">{icon}</span>
//     <span class="gf-presets-card-name">{name}</span>
//     <span class="gform-badge gf-presets-badge gf-presets-badge-{type}">{type}</span>
//     <span class="gf-presets-card-links">🔗 {linked_count} forms</span>
//   </div>
//   <div class="gf-presets-card-meta">
//     Created {created_at} · <button>Edit</button> · <button>Duplicate</button> · <button>Delete</button>
//   </div>
//   <div class="gf-presets-card-editor" style="display: none;">
//     <!-- Inline editor (accordion) -->
//   </div>
//   <div class="gf-presets-card-links-expand" style="display: none;">
//     <!-- Linked forms list -->
//   </div>
// </div>
