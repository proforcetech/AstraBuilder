# Astra Builder developer guide

This document describes the extensibility points that Astra Builder exposes to third-party plugins or custom code. The goal is to make it easy to register bespoke blocks, inspector controls, lifecycle hooks, and marketplace entries with a predictable TypeScript surface.

## Global browser API and TypeScript definitions

The editor script registers a global helper on `window.AstraBuilder`. The TypeScript definitions that describe this API live in [`astra-builder/types/index.d.ts`](../astra-builder/types/index.d.ts). The main entry points are:

```ts
window.AstraBuilder.registerPreset( slug, {
    blockName: 'core/paragraph',
    title: 'Hero summary',
    description: 'Reusable hero copy.',
    attributes: { placeholder: 'Describe your product…' },
} );

window.AstraBuilder.registerWrapperBlock( 'acme/hero-tabs', {
    title: 'Hero tabs',
    description: 'Tabbed hero area with large icons.',
    variant: 'tabs',
    icon: 'index-card',
    block: {/* @wordpress/blocks settings */},
} );

window.AstraBuilder.registerInspectorControl( 'core/heading', ( props ) => {
    return wp.element.createElement( wp.components.PanelBody, { title: 'Brand voice' },
        wp.element.createElement( wp.components.TextControl, {
            label: 'Voice',
            value: props.attributes.brandVoice || '',
            onChange: ( value ) => props.setAttributes( { brandVoice: value } ),
        } )
    );
} );

// Register client-side block migrations (see below).
window.AstraBuilder.registerBlockMigration( ( block ) => {
    if ( block.name !== 'core/paragraph' ) {
        return null;
    }
    return {
        attributes: Object.assign( {}, block.attributes, {
            style: Object.assign( {}, block.attributes.style || {}, { color: { text: '#0f172a' } } ),
        } ),
    };
} );
```

These helpers are thin wrappers around the internal registry used by the editor, so anything registered through the global object is immediately available in the UI. The `.d.ts` file also ships literal event names so TypeScript-aware tooling can autocomplete them.

## Lifecycle hooks (PHP and JavaScript)

### Template save actions

* **PHP filter:** `astra_builder_template_pre_save( $params, $request, $is_update )` lets you mutate the REST payload before `wp_insert_post`/`wp_update_post` is executed.
* **PHP actions:**
  * `astra_builder_before_template_save( $params, $request, $is_update )`
  * `astra_builder_after_template_save( $post, $request, $is_update )`
* **JavaScript actions:** Subscribe via the global hook names `astra_builder.beforeSave` and `astra_builder.afterSave`:

```js
wp.hooks.addAction( 'astra_builder.beforeSave', 'acme/custom-save', ( payload ) => {
    // Inspect payload.meta or payload.content before Gutenberg persists the post.
} );
```

The JS helpers fire whenever a template or component is saved in the editor (autosaves are ignored).

### Render filters

Template compilation now exposes filters for every stage:

* `astra_builder_rendered_markup( $markup, $post )`
* `astra_builder_rendered_css( $css, $post )`
* `astra_builder_rendered_assets( $assets, $post )`
* `astra_builder_rendered_hints( $hints, $post )`
* `astra_builder_compiled_template( $compiled, $post )`

Use these to inject wrapper markup, tweak inline CSS, or append additional resource hints before the preview HTML is stored.

### Token changes

Design token updates emit events in both runtimes:

* **PHP action:** `astra_builder_tokens_changed( $new_tokens, $previous_tokens )`
* **JS action:** `astra_builder.tokenChange` (or `window.AstraBuilder.events.TOKEN_CHANGE`) fires every time the user edits a token inside the sidebar.

This makes it easy to sync theme.json overrides or trigger live previews while a user edits the design system.

### Block migrations

Both PHP and JavaScript share the same `astra_builder_block_migrations` filter. Pass an array of callables through the filter (or register them with `window.AstraBuilder.registerBlockMigration`) to normalize block attributes whenever content is parsed:

```php
add_filter( 'astra_builder_block_migrations', function( $migrations ) {
    $migrations[] = static function( $block ) {
        if ( 'core/cover' !== $block['blockName'] ) {
            return $block;
        }
        $block['attrs']['dimRatio'] = 40;
        return $block;
    };
    return $migrations;
} );
```

The same callbacks run in the editor so new insertions and existing layouts stay in sync.

## Marketplace manifest

The marketplace data that powers the admin catalog is stored in [`astra-builder/assets/marketplace-manifest.json`](../astra-builder/assets/marketplace-manifest.json). The schema is intentionally lightweight:

```json
{
  "version": 1,
  "packages": [
    {
      "slug": "vendor/package",
      "title": "Package title",
      "description": "Optional summary",
      "type": "block|collection|library",
      "version": "1.0.0",
      "vendor": "Publisher name",
      "preview": {
        "type": "image",
        "src": "https://example.com/preview.png",
        "caption": "Screenshot caption"
      },
      "requires": {
        "capabilities": [ "install_plugins" ],
        "packages": [ "vendor/dependency" ],
        "tokens": [ "components.buttons" ]
      },
      "tags": [ "commerce", "hero" ]
    }
  ]
}
```

When the editor loads, the manifest is validated and rendered inside the **Marketplace** card. Each entry:

* Runs through capability checks (e.g., `install_plugins`, `upload_files`). The CTA is disabled for users lacking required capabilities, and the UI shows an informational notice.
* Resolves package dependencies automatically—selecting a package auto-selects anything listed in `requires.packages`. Missing dependencies are surfaced as warnings.
* Provides preview support through the `preview` object. Clicking **Preview** opens the screenshot/modal directly in the admin UI.

You can filter or amend the manifest in PHP or JavaScript via the `astra_builder.marketplace_manifest` filter before it reaches the UI.
