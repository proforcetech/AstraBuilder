# Implementation tasks derived from the current status

The backlog below converts the gaps listed in [`docs/IMPLEMENTATION_STATUS.md`](IMPLEMENTATION_STATUS.md) into discrete, assignable tasks. Each cluster references the primary files to edit and the expected outputs so contributors can split work without overlap.

## 1) Build the missing editor bundles and styles
- Implement `assets/editor/canvas-renderer.js` with layout parsing, drag/drop handling, guides, and selection logic; export a renderer consumed by `assets/editor.js`.
- Add `assets/editor/responsive-context.js` to provide breakpoint context, inheritance flags, and helpers for per-breakpoint attribute updates.
- Ensure enqueue handles versioning for all editor assets using `filemtime` and document any build steps required to generate the bundles (e.g., `npm run build`).
- If using a bundler (e.g., `@wordpress/scripts`), add the config and entrypoints to produce the missing files plus associated CSS.

## 2) Finish template compilation and preview
- In `includes/services/class-astra-builder-template-service.php`, implement the compile pipeline: convert block markup into rendered HTML, extract critical CSS, and generate resource hints.
- Wire preview transients so draft templates/components render with the correct token scope and condition context.
- Complete pattern export/fallback helpers to emit valid WordPress patterns when templates/components are shared or the plugin is deactivated.

## 3) Wire REST controllers to persistence
- Implement CRUD handlers in `includes/rest/class-astra-builder-templates-controller.php` that validate capabilities, nonces, and map payloads to template service methods.
- Complete token CRUD/snapshot endpoints in `includes/rest/class-astra-builder-tokens-controller.php` and ensure responses include rendered CSS variables for previews.
- Finish collaboration, backup, diff, and form submission endpoints so they perform validation, sanitize inputs, and delegate to services (`includes/rest/class-astra-builder-collaboration-controller.php`, `includes/rest/class-astra-builder-backup-controller.php`, `includes/rest/class-astra-builder-forms-controller.php`).

## 4) Implement editor UI features
- Extend `assets/editor.js` to surface responsive controls, inspector panels (layout/design/effects/data/visibility), and connect them to Gutenberg block attributes.
- Add marketplace/catalog surfaces and data-binding controls that call the REST endpoints from task #3.
- Layer collaboration UI (comments, locks, presence indicators) and history/snapshot controls that reflect REST state.

## 5) Front-end behaviors and critical CSS
- Ensure `includes/class-astra-builder.php` conditionally prints global token CSS and attaches per-template critical CSS/resource hints during front-end rendering.
- Implement media lazy-loading and asset enqueuing guards so front-end scripts/styles load only when blocks require them.
- Validate all rendered markup is escaped/sanitized and honors design token overrides.

## 6) Collaboration and versioning plumbing
- Add storage for snapshots/diffs (post meta or dedicated tables) and expose diff/rollback helpers in services.
- Implement presence polling and section-level locking, ensuring permissions match roles/capabilities.
- Store and render inline comments linked to canvas node IDs with resolve/delete actions.

## 7) Testing and tooling
- Introduce linting for PHP (e.g., PHPCS) and JavaScript/TypeScript (ESLint/Prettier), adding CI tasks to run them.
- Add unit/integration tests for services and REST controllers (PHPUnit) plus component/UI tests for the editor (Jest/Playwright).
- Document the build/test commands in `README.md` once tooling is available, and ensure translation catalogs are generated during the build.

## Suggested sequencing
Prioritize tasks in order: (1) editor bundles, (2) template compilation, (3) REST wiring, then (4) editor UI features that consume those APIs. Follow with (5) front-end behaviors, (6) collaboration/versioning, and (7) testing/tooling to lock down regressions.
