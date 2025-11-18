( function( wp ) {
    const { registerPlugin } = wp.plugins;
    const { __, sprintf } = wp.i18n;
    const { Fragment, useCallback, useMemo, useState } = wp.element;
    const { PanelBody, Card, CardBody, CardHeader, Icon, Button, ButtonGroup, TextControl, Notice } = wp.components;
    const { PluginSidebarMoreMenuItem, PluginSidebar } = wp.editPost || {};
    const { createBlock, getBlockType } = wp.blocks;
    const { useDispatch, useSelect } = wp.data;
    const { useViewportMatch } = wp.compose;

    const canvasUtils = window.AstraBuilderCanvas || {};
    const {
        ROW_HEIGHT = 72,
        createLayoutMap = () => [],
        computeGridGeometry = () => ( { nodes: [], columns: 1, rowHeight: ROW_HEIGHT } ),
        computeSnapLines = () => [],
        computeSpacingIndicators = () => [],
        useKeyboardControls = () => {},
    } = canvasUtils;

    const responsiveUtils = window.AstraBuilderResponsive || {};
    const {
        ResponsiveProvider = ( { children } ) => children,
        useResponsiveContext = () => ( {
            breakpoints: [],
            activeBreakpoint: 'global',
            setActiveBreakpoint: () => {},
            getParentId: () => null,
        } ),
        useResponsiveAttribute = () => ( {
            value: undefined,
            setValue: () => {},
            resetValue: () => {},
            isInherited: false,
            hasOverride: false,
            sourceBreakpoint: 'global',
        } ),
    } = responsiveUtils;

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
            attributes: {
                url: '',
                alt: '',
            },
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

    const { addFilter } = wp.hooks || {};

    const ensureResponsiveAttribute = ( settings = {} ) => {
        const nextSettings = Object.assign( {}, settings );
        nextSettings.attributes = nextSettings.attributes || {};
        if ( ! nextSettings.attributes.astraBuilderResponsive ) {
            nextSettings.attributes.astraBuilderResponsive = {
                type: 'object',
                default: {},
            };
        }
        return nextSettings;
    };

    if ( addFilter ) {
        addFilter( 'blocks.registerBlockType', 'astra-builder/responsive-attributes', ensureResponsiveAttribute );
    }

    const RESPONSIVE_SECTIONS = [
        {
            id: 'layout',
            title: __( 'Layout', 'astra-builder' ),
            description: __( 'Control spacing and structure.', 'astra-builder' ),
            controls: [
                { id: 'content-size', label: __( 'Content width', 'astra-builder' ), path: [ 'style', 'layout', 'contentSize' ], placeholder: '1200px' },
                { id: 'justify', label: __( 'Justify content', 'astra-builder' ), path: [ 'style', 'layout', 'justifyContent' ], placeholder: 'flex-start' },
            ],
        },
        {
            id: 'design',
            title: __( 'Design', 'astra-builder' ),
            description: __( 'Tweak visual styles per breakpoint.', 'astra-builder' ),
            controls: [
                { id: 'text-color', label: __( 'Text color', 'astra-builder' ), path: [ 'style', 'color', 'text' ], placeholder: '#1f2937' },
                { id: 'background-color', label: __( 'Background', 'astra-builder' ), path: [ 'style', 'color', 'background' ], placeholder: '#ffffff' },
            ],
        },
        {
            id: 'effects',
            title: __( 'Effects', 'astra-builder' ),
            description: __( 'Apply depth, motion, and polish.', 'astra-builder' ),
            controls: [
                { id: 'radius', label: __( 'Border radius', 'astra-builder' ), path: [ 'style', 'border', 'radius' ], placeholder: '8px' },
                { id: 'shadow', label: __( 'Shadow color', 'astra-builder' ), path: [ 'style', 'shadow', 'color' ], placeholder: 'rgba(15,23,42,0.18)' },
            ],
        },
        {
            id: 'interactions',
            title: __( 'Interactions', 'astra-builder' ),
            description: __( 'Describe how the block responds to input.', 'astra-builder' ),
            controls: [
                { id: 'transition', label: __( 'Transition duration', 'astra-builder' ), path: [ 'style', 'transition', 'duration' ], placeholder: '200ms' },
                { id: 'animation', label: __( 'Animation', 'astra-builder' ), path: [ 'style', 'animation', 'name' ], placeholder: 'fade-in' },
            ],
        },
        {
            id: 'data',
            title: __( 'Data', 'astra-builder' ),
            description: __( 'Link to APIs or datasets.', 'astra-builder' ),
            controls: [
                { id: 'endpoint', label: __( 'Endpoint', 'astra-builder' ), path: [ 'metadata', 'astraBuilder', 'endpoint' ], placeholder: 'https://api.example.com' },
                { id: 'binding', label: __( 'Binding key', 'astra-builder' ), path: [ 'metadata', 'astraBuilder', 'binding' ], placeholder: 'hero.title' },
            ],
        },
        {
            id: 'visibility',
            title: __( 'Visibility', 'astra-builder' ),
            description: __( 'Show or hide content responsively.', 'astra-builder' ),
            controls: [
                { id: 'display', label: __( 'Display', 'astra-builder' ), path: [ 'style', 'display' ], placeholder: 'flex' },
                { id: 'opacity', label: __( 'Opacity', 'astra-builder' ), path: [ 'style', 'opacity' ], placeholder: '1' },
            ],
        },
    ];

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

        if ( typeof blockIcon === 'string' ) {
            return blockIcon;
        }

        if ( typeof blockIcon === 'function' ) {
            return blockIcon;
        }

        if ( typeof blockIcon === 'object' ) {
            if ( blockIcon.$$typeof ) {
                return blockIcon;
            }

            if ( blockIcon.src ) {
                return normalizeBlockIcon( blockIcon.src );
            }
        }

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

    const CanvasDropZone = ( { index, isActive, onDragOver, onDrop, onDragLeave } ) =>
        wp.element.createElement( 'div', {
            className: 'astra-builder__canvas-drop-target' + ( isActive ? ' is-active' : '' ),
            style: { gridColumn: '1 / -1' },
            onDragOver: ( event ) => onDragOver( index, event ),
            onDragEnter: ( event ) => onDragOver( index, event ),
            onDragLeave,
            onDrop: ( event ) => onDrop( event, index ),
        }, wp.element.createElement( 'span', null, __( 'Drop block here', 'astra-builder' ) ) );

    const CanvasBlockToolbar = ( { isVisible, onDuplicate, onGroup, onRemove } ) =>
        wp.element.createElement( 'div', { className: 'astra-builder__canvas-node__toolbar' + ( isVisible ? ' is-visible' : '' ) },
            wp.element.createElement( Button, { isSmall: true, onClick: onDuplicate }, __( 'Duplicate', 'astra-builder' ) ),
            wp.element.createElement( Button, { isSmall: true, onClick: onGroup }, __( 'Group', 'astra-builder' ) ),
            wp.element.createElement( Button, { isSmall: true, isDestructive: true, onClick: onRemove }, __( 'Remove', 'astra-builder' ) )
        );

    const CanvasBlockNode = ( {
        block,
        geometry,
        isSelected,
        isHovered,
        onHover,
        onHoverEnd,
        onDragStart,
        onFocus,
        onDuplicate,
        onGroup,
        onRemove,
    } ) => {
        const blockType = getBlockType( block.name );
        const icon = blockType && blockType.icon ? normalizeBlockIcon( blockType.icon ) : 'layout';
        const title = ( blockType && blockType.title ) || block.name;
        const className = [
            'astra-builder__canvas-node',
            isSelected ? 'is-selected' : '',
            isHovered ? 'is-hovered' : '',
        ].filter( Boolean ).join( ' ' );

        return wp.element.createElement(
            'div',
            {
                className,
                style: { gridColumn: `${ geometry.column } / span ${ geometry.columnSpan }` },
                draggable: true,
                onDragStart: ( event ) => onDragStart( event, block ),
                onMouseEnter: () => onHover( block.clientId ),
                onMouseLeave: onHoverEnd,
                onClick: () => onFocus( block.clientId ),
            },
            wp.element.createElement( 'div', { className: 'astra-builder__canvas-node__body' },
                wp.element.createElement( Icon, { icon } ),
                wp.element.createElement( 'div', null,
                    wp.element.createElement( 'strong', null, title ),
                    wp.element.createElement( 'code', null, block.clientId ),
                    block.innerBlocks && block.innerBlocks.length ? wp.element.createElement( 'small', null, __( 'Contains child blocks', 'astra-builder' ) ) : null
                )
            ),
            wp.element.createElement( 'div', { className: 'astra-builder__canvas-node__handle', role: 'presentation' }, '⋮⋮' ),
            wp.element.createElement( CanvasBlockToolbar, {
                isVisible: isSelected || isHovered,
                onDuplicate: () => onDuplicate( block.clientId ),
                onGroup: () => onGroup( block.clientId ),
                onRemove: () => onRemove( block.clientId ),
            } )
        );
    };

    const SnapLinesOverlay = ( { lines, columns } ) =>
        wp.element.createElement( 'div', { className: 'astra-builder__canvas-snaps' }, lines.map( ( line ) => {
            if ( line.orientation === 'horizontal' ) {
                return wp.element.createElement( 'div', {
                    key: line.key,
                    className: 'astra-builder__canvas-snaps-line is-horizontal',
                    style: { top: `${ line.position }px` },
                } );
            }

            const fraction = columns > 1 ? ( ( line.column - 1 ) / columns ) : 0;
            return wp.element.createElement( 'div', {
                key: line.key,
                className: 'astra-builder__canvas-snaps-line is-vertical',
                style: { left: `${ fraction * 100 }%` },
            } );
        } ) );

    const SpacingOverlay = ( { indicators } ) =>
        wp.element.createElement( 'div', { className: 'astra-builder__canvas-spacing' }, indicators.map( ( indicator ) =>
            wp.element.createElement( 'div', {
                key: indicator.key,
                className: 'astra-builder__canvas-spacing__item',
                style: { top: `${ indicator.top }px` },
            }, indicator.label )
        ) );

    const BreakpointToolbar = () => {
        const { activeBreakpoint, breakpoints, setActiveBreakpoint } = useResponsiveContext();
        return wp.element.createElement( ButtonGroup, { className: 'astra-builder__breakpoint-toolbar', 'aria-label': __( 'Select breakpoint', 'astra-builder' ) },
            breakpoints.map( ( breakpoint ) =>
                wp.element.createElement( Button, {
                    key: breakpoint.id,
                    isPrimary: activeBreakpoint === breakpoint.id,
                    isSecondary: activeBreakpoint !== breakpoint.id,
                    onClick: () => setActiveBreakpoint( breakpoint.id ),
                }, breakpoint.label )
            )
        );
    };

    const ResponsiveControl = ( { clientId, control } ) => {
        const { activeBreakpoint, breakpoints } = useResponsiveContext();
        const { value, setValue, resetValue, isInherited, hasOverride, sourceBreakpoint } = useResponsiveAttribute( clientId, control.path );
        const resolvedValue = value === undefined || value === null ? '' : value;
        const source = breakpoints.find( ( bp ) => bp.id === sourceBreakpoint );
        const helpText = isInherited && source ? sprintf( __( 'Inherited from %s', 'astra-builder' ), source.label ) : control.help;

        return wp.element.createElement( 'div', { className: 'astra-builder__responsive-control' + ( hasOverride ? ' has-override' : '' ) },
            wp.element.createElement( TextControl, {
                label: control.label,
                value: resolvedValue,
                placeholder: control.placeholder,
                onChange: ( nextValue ) => setValue( nextValue || undefined ),
                help: helpText,
            } ),
            activeBreakpoint !== 'global' && hasOverride ?
                wp.element.createElement( Button, { isSmall: true, isSecondary: true, onClick: resetValue }, __( 'Reset to global', 'astra-builder' ) ) :
                null
        );
    };

    const ResponsiveSection = ( { section, clientId } ) =>
        wp.element.createElement( PanelBody, {
            title: section.title,
            initialOpen: section.initialOpen !== false,
            className: 'astra-builder__responsive-section',
        },
        section.description ? wp.element.createElement( 'p', { className: 'astra-builder__responsive-description' }, section.description ) : null,
        section.controls.map( ( control ) =>
            wp.element.createElement( ResponsiveControl, {
                key: `${ section.id }-${ control.id }`,
                clientId,
                control,
            } )
        ) );

    const ResponsiveInspector = () => {
        const selectedBlock = useSelect( ( select ) => {
            const blockEditor = select( 'core/block-editor' );
            const selectedId = blockEditor.getSelectedBlockClientId ? blockEditor.getSelectedBlockClientId() : null;
            return selectedId ? blockEditor.getBlock( selectedId ) : null;
        }, [] );

        const blockType = selectedBlock ? getBlockType( selectedBlock.name ) : null;
        const blockLabel = selectedBlock ? ( ( blockType && blockType.title ) || selectedBlock.name ) : null;

        return wp.element.createElement( 'div', { className: 'astra-builder__inspector' },
            wp.element.createElement( PanelBody, { title: __( 'Responsive controls', 'astra-builder' ), initialOpen: true },
                selectedBlock ? wp.element.createElement( Fragment, null,
                    wp.element.createElement( 'p', { className: 'astra-builder__inspector-target' }, sprintf( __( 'Editing %s', 'astra-builder' ), blockLabel ) ),
                    wp.element.createElement( BreakpointToolbar, null )
                ) : wp.element.createElement( Notice, { status: 'info', isDismissible: false }, __( 'Select a block to edit responsive settings.', 'astra-builder' ) )
            ),
            selectedBlock ? RESPONSIVE_SECTIONS.map( ( section ) =>
                wp.element.createElement( ResponsiveSection, { section, clientId: selectedBlock.clientId, key: section.id } )
            ) : null
        );
    };

    const CanvasRenderer = () => {
        const blocks = useSelect( ( select ) => select( 'core/block-editor' ).getBlocks(), [] );
        const selectedClientIds = useSelect( ( select ) => {
            const blockEditor = select( 'core/block-editor' );
            if ( blockEditor.getSelectedBlockClientIds ) {
                return blockEditor.getSelectedBlockClientIds();
            }
            const selectedId = blockEditor.getSelectedBlockClientId ? blockEditor.getSelectedBlockClientId() : null;
            return selectedId ? [ selectedId ] : [];
        }, [] );
        const { insertBlocks, moveBlockToPosition, selectBlock, duplicateBlocks, removeBlocks, wrapBlocks } = useDispatch( 'core/block-editor' );
        const [ activeDropIndex, setActiveDropIndex ] = useState( null );
        const [ hoveredBlockId, setHoveredBlockId ] = useState( null );

        const layoutMap = useMemo( () => createLayoutMap( blocks ), [ blocks ] );
        const topLevelNodes = useMemo( () => layoutMap.filter( ( node ) => node.depth === 0 ), [ layoutMap ] );
        const geometry = useMemo( () => computeGridGeometry( topLevelNodes ), [ topLevelNodes ] );
        const geometryMap = useMemo( () => {
            const map = new Map();
            geometry.nodes.forEach( ( node ) => map.set( node.id, node ) );
            return map;
        }, [ geometry ] );
        const snapLines = useMemo( () => computeSnapLines( geometry ), [ geometry ] );
        const spacingIndicators = useMemo( () => computeSpacingIndicators( geometry ), [ geometry ] );

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
        }, [ insertBlocks, moveBlockToPosition, selectBlock ] );

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

        const handleDuplicate = useCallback( ( clientId ) => {
            const ids = clientId ? [ clientId ] : selectedClientIds;
            if ( ! ids.length ) {
                return;
            }
            duplicateBlocks( ids );
            selectBlock( ids[ ids.length - 1 ] );
        }, [ duplicateBlocks, selectBlock, selectedClientIds ] );

        const handleGroup = useCallback( ( clientId ) => {
            const ids = selectedClientIds.length > 1 ? selectedClientIds : ( clientId ? [ clientId ] : [] );
            if ( ! ids.length ) {
                return;
            }
            wrapBlocks( ids, 'core/group' );
        }, [ selectedClientIds, wrapBlocks ] );

        const handleRemove = useCallback( ( clientId ) => {
            const ids = clientId ? [ clientId ] : selectedClientIds;
            if ( ids.length ) {
                removeBlocks( ids );
            }
        }, [ removeBlocks, selectedClientIds ] );

        const handleMoveSelection = useCallback( ( direction ) => {
            if ( ! selectedClientIds.length ) {
                return;
            }
            const targetId = selectedClientIds[ 0 ];
            const currentIndex = blocks.findIndex( ( block ) => block.clientId === targetId );
            if ( currentIndex < 0 ) {
                return;
            }
            let desiredIndex = currentIndex + direction;
            desiredIndex = Math.max( 0, Math.min( blocks.length, desiredIndex ) );
            if ( desiredIndex === currentIndex ) {
                return;
            }
            moveBlockToPosition( targetId, undefined, undefined, desiredIndex );
            selectBlock( targetId );
        }, [ blocks, moveBlockToPosition, selectBlock, selectedClientIds ] );

        useKeyboardControls( {
            selectedClientIds,
            onDuplicate: () => handleDuplicate(),
            onGroup: () => handleGroup(),
            onDelete: () => handleRemove(),
            onMove: handleMoveSelection,
        } );

        const dropTargets = useMemo( () => {
            const zones = [];
            const length = blocks.length;
            for ( let i = 0; i <= length; i += 1 ) {
                zones.push( wp.element.createElement( CanvasDropZone, {
                    key: `drop-${ i }`,
                    index: i,
                    isActive: activeDropIndex === i,
                    onDrop: handleDrop,
                    onDragOver: handleDragOver,
                    onDragLeave: () => handleDragOver( null ),
                } ) );
                if ( i < length ) {
                    const block = blocks[ i ];
                    const geometryNode = geometryMap.get( block.clientId );
                    if ( geometryNode ) {
                        zones.push( wp.element.createElement( CanvasBlockNode, {
                            key: block.clientId,
                            block,
                            geometry: geometryNode,
                            isSelected: selectedClientIds.includes( block.clientId ),
                            isHovered: hoveredBlockId === block.clientId,
                            onHover: setHoveredBlockId,
                            onHoverEnd: () => setHoveredBlockId( null ),
                            onDragStart: handleExistingStartDrag,
                            onFocus: selectBlock,
                            onDuplicate: handleDuplicate,
                            onGroup: handleGroup,
                            onRemove: handleRemove,
                        } ) );
                    }
                }
            }
            return zones;
        }, [
            blocks,
            activeDropIndex,
            geometryMap,
            handleDrop,
            handleDragOver,
            handleExistingStartDrag,
            handleDuplicate,
            handleGroup,
            handleRemove,
            hoveredBlockId,
            selectedClientIds,
            selectBlock,
        ] );

        return wp.element.createElement( 'div', { className: 'astra-builder__canvas-surface' },
            wp.element.createElement( 'div', {
                className: 'astra-builder__canvas-grid',
                style: { gridTemplateColumns: `repeat(${ geometry.columns }, minmax(0, 1fr))`, gridAutoRows: `${ ROW_HEIGHT }px` },
            }, dropTargets ),
            wp.element.createElement( SnapLinesOverlay, { lines: snapLines, columns: geometry.columns } ),
            wp.element.createElement( SpacingOverlay, { indicators: spacingIndicators } )
        );
    };

    const BuilderSidebar = () => {
        const isMobile = useViewportMatch( 'medium', '<' );

        if ( isMobile ) {
            return wp.element.createElement( PanelBody, {
                title: __( 'Drag-and-drop layout', 'astra-builder' ),
                initialOpen: true,
            }, wp.element.createElement( 'p', null, __( 'The layout builder works best on larger screens. Rotate your device or use a desktop to reorder blocks.', 'astra-builder' ) ) );
        }

        return wp.element.createElement( Fragment, null,
            wp.element.createElement( 'div', { className: 'astra-builder__palette' },
                wp.element.createElement( 'h2', null, __( 'Block palette', 'astra-builder' ) ),
                wp.element.createElement( 'p', null, __( 'Drag components onto the canvas to compose your page.', 'astra-builder' ) ),
                PALETTE_BLOCKS.map( ( block ) => wp.element.createElement( PaletteItem, { blueprint: block, key: block.name } ) )
            ),
            wp.element.createElement( 'div', { className: 'astra-builder__canvas' },
                wp.element.createElement( 'h2', null, __( 'Canvas layout', 'astra-builder' ) ),
                wp.element.createElement( 'p', null, __( 'Use drag handles, snap lines, and keyboard shortcuts to rearrange content.', 'astra-builder' ) ),
                wp.element.createElement( CanvasRenderer, null )
            ),
            wp.element.createElement( ResponsiveInspector, null )
        );
    };

    const BuilderPlugin = () =>
        wp.element.createElement( ResponsiveProvider, null,
            wp.element.createElement( Fragment, null,
                PluginSidebarMoreMenuItem ? wp.element.createElement( PluginSidebarMoreMenuItem, { target: 'astra-builder-sidebar' }, __( 'Astra Builder', 'astra-builder' ) ) : null,
                PluginSidebar ? wp.element.createElement( PluginSidebar, { name: 'astra-builder-sidebar', title: __( 'Astra Builder', 'astra-builder' ) },
                    wp.element.createElement( BuilderSidebar, null )
                ) : wp.element.createElement( 'div', { className: 'astra-builder__inline' }, wp.element.createElement( BuilderSidebar, null ) )
            )
        );

    registerPlugin( 'astra-builder', {
        render: BuilderPlugin,
        icon: 'screenoptions',
    } );
} )( window.wp );
