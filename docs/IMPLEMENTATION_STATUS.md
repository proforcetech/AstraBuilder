# Implementation status and next steps

This document captures what already exists in the Astra Builder codebase and the concrete steps required to ship a working plugin that matches the planned feature set.

## Current state

- **Services wired up:** The bootstrap class instantiates services for templates, tokens, forms, data bindings, media helpers, insights, backups, localization, and REST controllers. These services are registered on `init`, editor assets are enqueued, and global token CSS is printed in both editor and front-end contexts. 【F:astra-builder/includes/class-astra-builder.php†L32-L241】
- **Template and component storage:** Two custom post types (`astra_template` and `astra_component`) are registered with REST visibility and meta for conditions, compiled markup/CSS, pattern exports, and collaboration data. Preview query vars and language scoping hooks also exist. 【F:astra-builder/includes/services/class-astra-builder-template-service.php†L11-L168】
- **Token pipeline:** Token CRUD, snapshots, CSS variable rendering, and REST exposure are scaffolded in `Astra_Builder_Token_Service` and its REST controllers. 【F:astra-builder/includes/services/class-astra-builder-token-service.php†L11-L195】【F:astra-builder/includes/rest/class-astra-builder-tokens-controller.php†L11-L113】
- **Forms and submissions:** Form settings, spam configuration, and a submissions controller are registered, and the front-end script only loads when a form block is present. 【F:astra-builder/includes/services/class-astra-builder-form-service.php†L11-L118】【F:astra-builder/includes/class-astra-builder.php†L170-L241】
- **Developer surface:** The editor script exposes `window.AstraBuilder` with hooks and registration helpers defined in `types/index.d.ts`. 【F:astra-builder/types/index.d.ts†L1-L48】

## Gaps to close

1. **Build the missing editor bundles and styles.** The PHP loader enqueues `assets/editor/canvas-renderer.js`, `assets/editor/responsive-context.js`, `assets/editor.js`, and `assets/editor.css`, but only `assets/editor.js` and `assets/editor.css` currently exist. Implement or bundle the missing scripts and ensure all assets are versioned via `filemtime`. 【F:astra-builder/includes/class-astra-builder.php†L89-L118】
2. **Finish template compilation and preview.** Implement the methods in `Astra_Builder_Template_Service` that generate compiled markup, critical CSS, resource hints, and preview transients so template saves and previews reflect real output. Ensure pattern export and fallback logic produce valid WP patterns. 【F:astra-builder/includes/services/class-astra-builder-template-service.php†L69-L137】
3. **Wire REST controllers to persistence.** Complete handlers for templates, tokens, snapshots, settings, diffs, collaboration, backups, and form submissions so CRUD, validation, capability checks, and nonce verification are enforced. Map controller payloads to the corresponding service methods. 【F:astra-builder/includes/rest/class-astra-builder-templates-controller.php†L11-L164】【F:astra-builder/includes/rest/class-astra-builder-backup-controller.php†L11-L90】
4. **Implement editor UI features.** Extend the React editor to surface responsive controls, layout/design/effects/data panels, marketplace catalog, collaboration overlays, token editor, and backup/import flows that call the REST endpoints above. Connect `window.AstraBuilder` registrations to the Gutenberg block registry and inspector controls. 【F:astra-builder/assets/editor.js†L1-L120】
5. **Front-end behaviors and critical CSS.** Ensure front-end rendering respects design tokens, per-template critical CSS, resource hints, and lazy-loading/media rules. Validate that `output_global_token_styles` and asset manifests are applied conditionally and sanitized. 【F:astra-builder/includes/class-astra-builder.php†L221-L241】【F:astra-builder/includes/services/class-astra-builder-template-service.php†L121-L168】
6. **Collaboration and versioning.** Implement presence polling, section locks, inline comments, and snapshot/diff UIs that coordinate with REST controllers. Store history metadata in post meta or a dedicated store and expose diff/rollback endpoints. 【F:astra-builder/includes/class-astra-builder.php†L145-L169】【F:astra-builder/includes/rest/class-astra-builder-collaboration-controller.php†L11-L116】
7. **Testing and tooling.** Add linting, PHPUnit/Playwright or Jest tests for PHP and JS, and a build pipeline (e.g., @wordpress/scripts) to compile the editor bundles, generate translation catalogs, and validate marketplace manifest schemas. Document commands in the README once available.

Use this list as the implementation backlog to bring Astra Builder from scaffold to a full-featured editor.

