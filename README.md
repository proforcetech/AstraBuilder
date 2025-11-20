# Astra Builder

Astra Builder is a WordPress plugin that layers a drag-and-drop layout experience on top of the Gutenberg editor. It keeps native block compatibility while introducing a Wix- or Squarespace-style workflow for composing pages and templates.

## What it does today

The plugin already wires several core systems together:

- **Bootstrap & services.** The main loader spins up services for templates, design tokens, forms, data bindings, media helpers, analytics, backups, localization, and REST controllers, then enqueues editor/front-end assets and prints global token CSS. 【F:astra-builder/includes/class-astra-builder.php†L32-L169】
- **Templates and components.** Custom post types store templates and reusable components, with meta fields for conditions, compiled markup, critical CSS, pattern export data, and collaboration state. 【F:astra-builder/includes/services/class-astra-builder-template-service.php†L11-L137】
- **Design tokens.** A token service persists global tokens, renders CSS variables into both editor and front-end output, and exposes REST endpoints for token CRUD plus snapshot/versioning. 【F:astra-builder/includes/services/class-astra-builder-token-service.php†L11-L195】
- **Forms and submissions.** A form service registers spam settings, validation rules, and a REST endpoint used by the front-end script loaded only when form blocks are present. 【F:astra-builder/includes/services/class-astra-builder-form-service.php†L11-L118】【F:astra-builder/includes/class-astra-builder.php†L170-L241】
- **Extensibility.** A developer-facing TypeScript definition advertises the `window.AstraBuilder` API for presets, wrapper blocks, inspector controls, and block migrations. 【F:astra-builder/types/index.d.ts†L1-L48】

## What still needs to be completed

See [`docs/IMPLEMENTATION_STATUS.md`](docs/IMPLEMENTATION_STATUS.md) for a deeper checklist, but the high-level gaps are:

- Build and ship the editor-side bundles that the PHP enqueues expect (canvas renderer, responsive context, main editor UI), including CSS and translation catalogs.
- Finish the template rendering pipeline so compiled markup, critical CSS, and resource hints are generated and previewed reliably for both templates and components.
- Connect the REST controllers to real storage and validation for tokens, templates, snapshots, backups, diffs, collaboration presence, and form submissions.
- Flesh out the editor UI for responsive controls, layout inspectors, marketplace catalog, collaboration overlays, and data-binding/config panels.
- Add tests and tooling (linting, build steps) to keep the PHP and JavaScript sides verifiable in CI.

## Installing

1. Copy the `astra-builder` directory into your WordPress installation's `wp-content/plugins` folder.
2. Activate **Astra Builder** from the WordPress admin Plugins screen.
3. Open any post or page in the block editor. The plugin registers its scripts/styles and exposes editor data through `window.AstraBuilderData` for the UI to consume.

Because Astra Builder builds directly on Gutenberg's block editor APIs and custom post types, anything you create remains compatible with themes, patterns, and native block settings. 【F:astra-builder/includes/class-astra-builder.php†L75-L145】【F:astra-builder/includes/services/class-astra-builder-template-service.php†L69-L97】

## Contributing

- Read the [developer guide](docs/DEVELOPERS.md) to learn how to register presets, wrapper blocks, inspector controls, and block migrations via JavaScript or PHP filters.
- Track completion tasks in [`docs/IMPLEMENTATION_STATUS.md`](docs/IMPLEMENTATION_STATUS.md).

