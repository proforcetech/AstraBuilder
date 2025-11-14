( function( wp ) {
    const { registerPlugin } = wp.plugins;
    const { __ } = wp.i18n;
    const { Fragment, useCallback, useMemo, useState } = wp.element;
    const { PanelBody, Card, CardBody, CardHeader, Icon } = wp.components;
    const { PluginSidebarMoreMenuItem, PluginSidebar } = wp.editor || wp.editPost || {};
    const { createBlock, getBlockType } = wp.blocks;
    const { useDispatch, useSelect } = wp.data;
    const { useViewportMatch } = wp.compose;

    const PALETTE_BLOCKS = [
        {
            name: 'core/heading',
            title: __( 'Heading', 'astra-builder' ),
            description: __( 'Introduce a new section or hero area.', 'astra-builder' ),
            attributes: {
                level: 2,
                content: __( 'Your heading here', 'astra-builder' ),
            },
        },
        {
            name: 'core/paragraph',
            title: __( 'Paragraph', 'astra-builder' ),
            description: __( 'Add descriptive copy and supporting details.', 'astra-builder' ),
            attributes: {
                placeholder: __( 'Start typing…', 'astra-builder' ),
            },
        },
        {
            name: 'core/image',
            title: __( 'Image', 'astra-builder' ),
            description: __( 'Drop in a media highlight or product shot.', 'astra-builder' ),
        },
        {
            name: 'core/columns',
            title: __( 'Columns', 'astra-builder' ),
            description: __( 'Compose multi-column layouts with optional content.', 'astra-builder' ),
            innerBlocks: [
                {
                    name: 'core/column',
                    innerBlocks: [
                        {
                            name: 'core/paragraph',
                            attributes: {
                                placeholder: __( 'Left column content…', 'astra-builder' ),
                            },
                        },
                    ],
                },
                {
                    name: 'core/column',
                    innerBlocks: [
                        {
                            name: 'core/paragraph',
                            attributes: {
                                placeholder: __( 'Right column content…', 'astra-builder' ),
                            },
                        },
                    ],
                },
            ],
        },
        {
            name: 'core/buttons',
            title: __( 'Call to Action', 'astra-builder' ),
            description: __( 'Drive visitors to your key action.', 'astra-builder' ),
            innerBlocks: [
                {
                    name: 'core/button',
                    attributes: {
                        text: __( 'Click me', 'astra-builder' ),
                    },
                },
            ],
        },
    ];

    const DROP_TYPE = {
        NEW_BLOCK: 'astra-builder/new-block',
        EXISTING_BLOCK: 'astra-builder/existing-block',
    };

    const createBlockFromBlueprint = ( blueprint ) => {
        if ( ! blueprint ) {
            return null;
        }

        const innerBlocks = ( blueprint.innerBlocks || [] )
            .map( createBlockFromBlueprint )
            .filter( Boolean );

        return createBlock( blueprint.name, blueprint.attributes || {}, innerBlocks );
    };

    const normalizeBlockIcon = ( blockIcon ) => {
        if ( ! blockIcon ) {
            return 'layout';
        }

        // String icons (Dashicon slugs) can be used directly
        if ( typeof blockIcon === 'string' ) {
            return blockIcon;
        }

        // Functions/components can be used directly
        if ( typeof blockIcon === 'function' ) {
            return blockIcon;
        }

        // Handle object-based icons
        if ( typeof blockIcon === 'object' ) {
            // If it's a React element (has $$typeof), return it directly
            if ( blockIcon.$$typeof ) {
                return blockIcon;
            }

            // If it has a src property, recursively normalize that
            if ( blockIcon.src ) {
                return normalizeBlockIcon( blockIcon.src );
            }
        }

        // Fallback to default icon for any unhandled cases
        return 'layout';
    };

    const PaletteItem = ( { blueprint } ) => {
        const blockType = getBlockType( blueprint.name );
        const icon = blockType && blockType.icon ? normalizeBlockIcon( blockType.icon ) : 'layout';

        const onDragStart = useCallback( ( event ) => {
            event.dataTransfer.setData( 'text/plain', blueprint.name );
            event.dataTransfer.setData( DROP_TYPE.NEW_BLOCK, JSON.stringify( { name: blueprint.name } ) );
            event.dataTransfer.effectAllowed = 'copyMove';
        }, [ blueprint.name ] );

        return wp.element.createElement(
            Card,
            {
                className: 'astra-builder__palette-item',
                draggable: true,
                onDragStart,
            },
            wp.element.createElement( CardHeader, null, wp.element.createElement( Icon, { icon } ), wp.element.createElement( 'span', null, blueprint.title ) ),
            wp.element.createElement( CardBody, null, blueprint.description )
        );
    };

    const DropZone = ( { index, onDrop, onDragOver, onDragLeave, isActive } ) =>
        wp.element.createElement( 'div', {
            className: 'astra-builder__drop-zone' + ( isActive ? ' is-active' : '' ),
            onDragOver: ( event ) => onDragOver( index, event ),
            onDragEnter: ( event ) => onDragOver( index, event ),
            onDragLeave: onDragLeave,
            onDrop: ( event ) => onDrop( event, index ),
        }, wp.element.createElement( 'span', null, __( 'Drop block here', 'astra-builder' ) ) );

    const ExistingBlockCard = ( { block, index, onStartDrag, onFocus } ) => {
        const blockType = getBlockType( block.name );
        const icon = blockType && blockType.icon ? normalizeBlockIcon( blockType.icon ) : 'layout';
        const title = ( blockType && blockType.title ) || block.name;

        return wp.element.createElement(
            Card,
            {
                className: 'astra-builder__canvas-block',
                draggable: true,
                onDragStart: ( event ) => onStartDrag( event, block, index ),
                onClick: () => onFocus( block.clientId ),
            },
            wp.element.createElement( CardHeader, null, wp.element.createElement( Icon, { icon } ), wp.element.createElement( 'span', null, title ) ),
            wp.element.createElement( CardBody, null, wp.element.createElement( 'code', null, block.clientId ) )
        );
    };

    const BuilderSidebar = () => {
        const blocks = useSelect( ( select ) => select( 'core/block-editor' ).getBlocks(), [] );
        const isMobile = useViewportMatch( 'medium', '<' );
        const { insertBlocks, moveBlockToPosition, selectBlock } = useDispatch( 'core/block-editor' );

        const [ activeDropIndex, setActiveDropIndex ] = useState( null );

        const handleDrop = useCallback( ( event, index ) => {
            event.preventDefault();
            const draggedExisting = event.dataTransfer.getData( DROP_TYPE.EXISTING_BLOCK );
            const draggedNew = event.dataTransfer.getData( DROP_TYPE.NEW_BLOCK );

            if ( draggedExisting ) {
                try {
                    const { clientId } = JSON.parse( draggedExisting );
                    if ( clientId ) {
                        moveBlockToPosition( clientId, undefined, undefined, index );
                        selectBlock( clientId );
                    }
                } catch ( error ) {
                    window.console.error( 'Failed to move block', error );
                }
            } else if ( draggedNew ) {
                try {
                    const { name } = JSON.parse( draggedNew );
                    const blueprint = PALETTE_BLOCKS.find( ( block ) => block.name === name );
                    const newBlock = createBlockFromBlueprint( blueprint );
                    if ( newBlock ) {
                        insertBlocks( newBlock, index );
                        selectBlock( newBlock.clientId );
                    }
                } catch ( error ) {
                    window.console.error( 'Failed to insert block', error );
                }
            }

            setActiveDropIndex( null );
        }, [ insertBlocks, moveBlockToPosition ] );

        const handleDragOver = useCallback( ( indexOrNull, event ) => {
            if ( event ) {
                event.preventDefault();
            }
            setActiveDropIndex( indexOrNull );
        }, [] );

        const handleExistingStartDrag = useCallback( ( event, block ) => {
            event.dataTransfer.setData( DROP_TYPE.EXISTING_BLOCK, JSON.stringify( { clientId: block.clientId } ) );
            event.dataTransfer.effectAllowed = 'move';
        }, [] );

        const dropZones = useMemo( () => {
            const zones = [];
            const length = blocks.length;
            for ( let i = 0; i <= length; i += 1 ) {
                zones.push( wp.element.createElement( DropZone, {
                    key: `drop-${ i }`,
                    index: i,
                    isActive: activeDropIndex === i,
                    onDrop: handleDrop,
                    onDragOver: handleDragOver,
                    onDragLeave: () => handleDragOver( null ),
                } ) );
                if ( i < length ) {
                    const block = blocks[ i ];
                    zones.push( wp.element.createElement( ExistingBlockCard, {
                        key: block.clientId,
                        block,
                        index: i,
                        onStartDrag: ( event ) => handleExistingStartDrag( event, block ),
                        onFocus: selectBlock,
                    } ) );
                }
            }
            return zones;
        }, [ blocks, activeDropIndex, handleDragOver, handleDrop, handleExistingStartDrag, selectBlock ] );

        if ( isMobile ) {
            return wp.element.createElement( PanelBody, {
                title: __( 'Drag-and-drop layout', 'astra-builder' ),
                initialOpen: true,
            }, wp.element.createElement( 'p', null, __( 'The layout builder works best on larger screens. Rotate your device or use a desktop to reorder blocks.', 'astra-builder' ) ) );
        }

        return wp.element.createElement(
            Fragment,
            null,
            wp.element.createElement( 'div', { className: 'astra-builder__palette' },
                wp.element.createElement( 'h2', null, __( 'Block palette', 'astra-builder' ) ),
                wp.element.createElement( 'p', null, __( 'Drag components onto the canvas to compose your page.', 'astra-builder' ) ),
                PALETTE_BLOCKS.map( ( block ) => wp.element.createElement( PaletteItem, { blueprint: block, key: block.name } ) )
            ),
            wp.element.createElement( 'div', { className: 'astra-builder__canvas' },
                wp.element.createElement( 'h2', null, __( 'Canvas layout', 'astra-builder' ) ),
                wp.element.createElement( 'p', null, __( 'Drag existing blocks to reorder them, or drop new blocks between the highlighted regions.', 'astra-builder' ) ),
                dropZones
            )
        );
    };

    const BuilderPlugin = () =>
        wp.element.createElement( Fragment, null,
            PluginSidebarMoreMenuItem ? wp.element.createElement( PluginSidebarMoreMenuItem, { target: 'astra-builder-sidebar' }, __( 'Astra Builder', 'astra-builder' ) ) : null,
            PluginSidebar ? wp.element.createElement( PluginSidebar, { name: 'astra-builder-sidebar', title: __( 'Astra Builder', 'astra-builder' ) },
                wp.element.createElement( BuilderSidebar, null )
            ) : wp.element.createElement( 'div', { className: 'astra-builder__inline' }, wp.element.createElement( BuilderSidebar, null ) )
        );

    registerPlugin( 'astra-builder', {
        render: BuilderPlugin,
        icon: 'screenoptions',
    } );
} )( window.wp );
