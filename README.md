# AstraBuilder

Astra Builder is a WordPress plugin that layers a drag-and-drop layout experience on top of the Gutenberg editor. It keeps native block compatibility while introducing a Wix- or Squarespace-style workflow for composing pages.

## Features

- Visual palette of curated Gutenberg blocks that you can drag directly into the canvas.
- Drag-and-drop reordering of existing blocks using WordPress core APIs so the block tree always stays in sync.
- Works either as a dedicated sidebar inside the editor or as an inline panel when the sidebar system is unavailable.
- Preserves full Gutenberg compatibility—blocks are inserted and arranged using the official `core/block-editor` data store.

## Getting started

1. Copy the `astra-builder` directory into your WordPress installation's `wp-content/plugins` folder.
2. Activate **Astra Builder** from the WordPress admin Plugins screen.
3. Open any post or page in the block editor. A new "Astra Builder" sidebar (or inline panel) will appear with the drag-and-drop interface.
4. Drag blocks from the palette into the canvas to build layouts, or drag existing blocks within the canvas to reorder them.

Because Astra Builder builds directly on Gutenberg's block editor APIs, anything you create remains compatible with themes, patterns, and native block settings.
