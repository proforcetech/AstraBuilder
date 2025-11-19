( function( wp ) {
    const { registerPlugin } = wp.plugins;
    const { __, sprintf } = wp.i18n;
    const { Fragment, useCallback, useMemo, useState, useEffect } = wp.element;
    const {
        PanelBody,
        Card,
        CardBody,
        CardHeader,
        Icon,
        Button,
        ButtonGroup,
        TextControl,
        Notice,
        CheckboxControl,
        SelectControl,
        TextareaControl,
        ToggleControl,
        RangeControl,
        Spinner,
    } = wp.components;
    const { PluginSidebarMoreMenuItem, PluginSidebar } = wp.editPost || {};
    const blockEditor = wp.blockEditor || wp.editor || {};
    const InspectorControls = blockEditor.InspectorControls || null;
    const InnerBlocks = blockEditor.InnerBlocks || null;
    const RichText = blockEditor.RichText || null;
    const useBlockProps = blockEditor.useBlockProps ? blockEditor.useBlockProps : ( () => ( {} ) );
    const useBlockPropsSave = blockEditor.useBlockProps && blockEditor.useBlockProps.save ? blockEditor.useBlockProps.save : ( () => ( {} ) );
    const { registerBlockType, createBlock, getBlockType } = wp.blocks;
    const { useDispatch, useSelect } = wp.data;
    const { useViewportMatch } = wp.compose;
    const { addFilter } = wp.hooks || {};
    const apiFetch = wp.apiFetch ? wp.apiFetch : null;

    const pluginData = window.AstraBuilderData || {};
    const metaKeys = pluginData.metaKeys || {};
    const restNamespace = pluginData.restNamespace || 'astra-builder/v1';
    const conditionOptions = pluginData.conditions || {};
    const bindingConfig = pluginData.binding || {};
    const formConfig = pluginData.forms || {};
    const initialTokenState = pluginData.tokens && pluginData.tokens.initial ? pluginData.tokens.initial : null;
    const previewMetricTargets = pluginData.preview && pluginData.preview.metrics ? pluginData.preview.metrics : { lcpTarget: 2500, clsTarget: 0.1 };

    const defaultSpamSettings = formConfig.spam || {};

    const sanitizeConditionList = ( list ) => Array.from( new Set( ( Array.isArray( list ) ? list : [] ).filter( Boolean ) ) );

    const cloneDeep = ( value ) => {
        if ( Array.isArray( value ) ) {
            return value.map( ( item ) => cloneDeep( item ) );
        }
        if ( value && typeof value === 'object' ) {
            return Object.keys( value ).reduce( ( acc, key ) => {
                acc[ key ] = cloneDeep( value[ key ] );
                return acc;
            }, {} );
        }
        return value;
    };

    const ensurePathArray = ( path ) => ( Array.isArray( path ) ? path : String( path ).split( '.' ) );

    const getPathValue = ( source, path ) => {
        const parts = ensurePathArray( path );
        return parts.reduce( ( acc, key ) => {
            if ( null === acc || undefined === acc ) {
                return undefined;
            }
            return acc[ key ];
        }, source );
    };

    const setPathValue = ( source, path, value ) => {
        const parts = ensurePathArray( path );
        if ( ! parts.length ) {
            return source;
        }

        const next = cloneDeep( source || {} );
        let cursor = next;

        parts.forEach( ( key, index ) => {
            const isLast = index === parts.length - 1;
            if ( isLast ) {
                cursor[ key ] = value;
                return;
            }
            if ( ! cursor[ key ] || typeof cursor[ key ] !== 'object' ) {
                cursor[ key ] = {};
            }
            cursor = cursor[ key ];
        } );

        return next;
    };

    const unsetPathValue = ( source, path ) => {
        const parts = ensurePathArray( path );
        if ( ! parts.length ) {
            return source;
        }

        const next = cloneDeep( source || {} );
        let cursor = next;
        const stack = [];

        for ( let i = 0; i < parts.length - 1; i += 1 ) {
            const key = parts[ i ];
            if ( ! cursor[ key ] || typeof cursor[ key ] !== 'object' ) {
                return next;
            }
            stack.push( { parent: cursor, key } );
            cursor = cursor[ key ];
        }

        const lastKey = parts[ parts.length - 1 ];
        if ( cursor && Object.prototype.hasOwnProperty.call( cursor, lastKey ) ) {
            delete cursor[ lastKey ];
        }

        for ( let i = stack.length - 1; i >= 0; i -= 1 ) {
            const frame = stack[ i ];
            if ( frame.parent[ frame.key ] && ! Object.keys( frame.parent[ frame.key ] ).length ) {
                delete frame.parent[ frame.key ];
            }
        }

        return next;
    };

    const stripHTML = ( value ) => ( value ? String( value ).replace( /<[^>]+>/g, '' ) : '' );

    const parseHexColor = ( value ) => {
        const hex = value.trim();
        if ( ! /^#([0-9a-f]{3})$/i.test( hex ) ) {
            return hex;
        }
        const shorthand = hex.replace( '#', '' );
        return '#' + shorthand.split( '' ).map( ( char ) => char + char ).join( '' );
    };

    const parseColorValue = ( value ) => {
        if ( ! value || 'string' !== typeof value ) {
            return null;
        }
        const color = value.trim();
        if ( /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test( color ) ) {
            const normalized = color.length === 4 ? parseHexColor( color ) : color;
            const hex = normalized.replace( '#', '' );
            return [
                parseInt( hex.slice( 0, 2 ), 16 ),
                parseInt( hex.slice( 2, 4 ), 16 ),
                parseInt( hex.slice( 4, 6 ), 16 ),
            ];
        }
        const rgbMatch = color.match( /^rgba?\((\d+),\s*(\d+),\s*(\d+)/i );
        if ( rgbMatch ) {
            return [
                parseInt( rgbMatch[ 1 ], 10 ),
                parseInt( rgbMatch[ 2 ], 10 ),
                parseInt( rgbMatch[ 3 ], 10 ),
            ];
        }
        return null;
    };

    const relativeLuminance = ( rgb ) => {
        if ( ! Array.isArray( rgb ) ) {
            return null;
        }
        const channel = rgb.map( ( value ) => {
            const channelValue = value / 255;
            return channelValue <= 0.03928 ? channelValue / 12.92 : Math.pow( ( channelValue + 0.055 ) / 1.055, 2.4 );
        } );
        return 0.2126 * channel[ 0 ] + 0.7152 * channel[ 1 ] + 0.0722 * channel[ 2 ];
    };

    const getContrastRatio = ( rgbA, rgbB ) => {
        const lumA = relativeLuminance( rgbA );
        const lumB = relativeLuminance( rgbB );
        if ( null === lumA || null === lumB ) {
            return null;
        }
        const lighter = Math.max( lumA, lumB );
        const darker = Math.min( lumA, lumB );
        return ( lighter + 0.05 ) / ( darker + 0.05 );
    };

    const traverseBlocks = ( list, callback ) => {
        if ( ! Array.isArray( list ) ) {
            return;
        }
        list.forEach( ( block ) => {
            if ( ! block ) {
                return;
            }
            callback( block );
            if ( Array.isArray( block.innerBlocks ) && block.innerBlocks.length ) {
                traverseBlocks( block.innerBlocks, callback );
            }
        } );
    };

    const getReadableBlockLabel = ( block ) => {
        if ( ! block || ! block.name ) {
            return __( 'Block', 'astra-builder' );
        }
        const type = getBlockType ? getBlockType( block.name ) : null;
        if ( type && type.title ) {
            return type.title;
        }
        return block.name;
    };

    const extractReadableText = ( value ) => stripHTML( value || '' ).trim();

    const formatMilliseconds = ( value ) => {
        if ( 'number' === typeof value && value > 0 ) {
            return Math.round( value ) + 'ms';
        }
        return __( 'n/a', 'astra-builder' );
    };

    const formatClsScore = ( value ) => {
        if ( 'number' === typeof value ) {
            return value.toFixed( 3 );
        }
        return __( 'n/a', 'astra-builder' );
    };

    const TEXT_TRANSFORM_OPTIONS = [
        { label: __( 'Inherit', 'astra-builder' ), value: '' },
        { label: __( 'Uppercase', 'astra-builder' ), value: 'uppercase' },
        { label: __( 'Lowercase', 'astra-builder' ), value: 'lowercase' },
        { label: __( 'Capitalize', 'astra-builder' ), value: 'capitalize' },
    ];

    const LIST_MARKER_OPTIONS = [
        { label: __( 'Disc', 'astra-builder' ), value: 'disc' },
        { label: __( 'Circle', 'astra-builder' ), value: 'circle' },
        { label: __( 'Square', 'astra-builder' ), value: 'square' },
        { label: __( 'Decimal', 'astra-builder' ), value: 'decimal' },
    ];

    const DESIGN_SECTIONS = [
        {
            id: 'typography',
            title: __( 'Typography', 'astra-builder' ),
            description: __( 'Control the primary body and heading styles that power the site.', 'astra-builder' ),
            fields: [
                { path: 'typography.body.fontFamily', label: __( 'Body font family', 'astra-builder' ), placeholder: 'Inter, sans-serif' },
                { path: 'typography.body.fontWeight', label: __( 'Body font weight', 'astra-builder' ), placeholder: '400' },
                { path: 'typography.body.lineHeight', label: __( 'Body line height', 'astra-builder' ), placeholder: '1.6' },
                { path: 'typography.body.textTransform', label: __( 'Body text transform', 'astra-builder' ), control: 'select', options: TEXT_TRANSFORM_OPTIONS },
                { path: 'typography.heading.fontFamily', label: __( 'Heading font family', 'astra-builder' ), placeholder: 'Outfit, sans-serif' },
                { path: 'typography.heading.fontWeight', label: __( 'Heading font weight', 'astra-builder' ), placeholder: '600' },
                { path: 'typography.heading.lineHeight', label: __( 'Heading line height', 'astra-builder' ), placeholder: '1.3' },
                { path: 'typography.heading.textTransform', label: __( 'Heading text transform', 'astra-builder' ), control: 'select', options: TEXT_TRANSFORM_OPTIONS },
            ],
        },
        {
            id: 'buttons',
            title: __( 'Buttons', 'astra-builder' ),
            description: __( 'Define the shared look and feel for every button template.', 'astra-builder' ),
            fields: [
                { path: 'components.buttons.fontWeight', label: __( 'Font weight', 'astra-builder' ), placeholder: '600' },
                { path: 'components.buttons.textTransform', label: __( 'Text transform', 'astra-builder' ), control: 'select', options: TEXT_TRANSFORM_OPTIONS },
                { path: 'components.buttons.borderRadius', label: __( 'Border radius', 'astra-builder' ), placeholder: '999px' },
                { path: 'components.buttons.paddingY', label: __( 'Vertical padding', 'astra-builder' ), placeholder: '0.85rem' },
                { path: 'components.buttons.paddingX', label: __( 'Horizontal padding', 'astra-builder' ), placeholder: '1.5rem' },
            ],
        },
        {
            id: 'lists',
            title: __( 'Lists', 'astra-builder' ),
            description: __( 'Adjust list spacing and markers for articles and sections.', 'astra-builder' ),
            fields: [
                { path: 'components.lists.gap', label: __( 'Row gap', 'astra-builder' ), placeholder: '0.75rem' },
                { path: 'components.lists.markerColor', label: __( 'Marker color', 'astra-builder' ), placeholder: '#3a4f66' },
                { path: 'components.lists.markerStyle', label: __( 'Marker style', 'astra-builder' ), control: 'select', options: LIST_MARKER_OPTIONS },
            ],
        },
        {
            id: 'forms',
            title: __( 'Forms', 'astra-builder' ),
            description: __( 'Set consistent spacing and states for inputs, textareas, and select fields.', 'astra-builder' ),
            fields: [
                { path: 'components.forms.fieldPaddingY', label: __( 'Field padding (Y)', 'astra-builder' ), placeholder: '0.75rem' },
                { path: 'components.forms.fieldPaddingX', label: __( 'Field padding (X)', 'astra-builder' ), placeholder: '1rem' },
                { path: 'components.forms.borderRadius', label: __( 'Border radius', 'astra-builder' ), placeholder: '8px' },
                { path: 'components.forms.borderColor', label: __( 'Border color', 'astra-builder' ), placeholder: 'rgba(15,23,42,0.12)' },
                { path: 'components.forms.focusColor', label: __( 'Focus color', 'astra-builder' ), placeholder: '#2563eb' },
                { path: 'components.forms.background', label: __( 'Field background', 'astra-builder' ), placeholder: '#ffffff' },
            ],
        },
        {
            id: 'modes',
            title: __( 'Dark mode', 'astra-builder' ),
            description: __( 'Control how typography and surfaces adapt for dark themes.', 'astra-builder' ),
            fields: [
                { path: 'modes.dark.enabled', label: __( 'Enable dark mode variables', 'astra-builder' ), control: 'toggle' },
                { path: 'modes.dark.background', label: __( 'Background', 'astra-builder' ), placeholder: '#0f172a' },
                { path: 'modes.dark.surface', label: __( 'Surface', 'astra-builder' ), placeholder: '#1f2937' },
                { path: 'modes.dark.text', label: __( 'Text color', 'astra-builder' ), placeholder: '#e2e8f0' },
                { path: 'modes.dark.muted', label: __( 'Muted color', 'astra-builder' ), placeholder: '#94a3b8' },
                { path: 'modes.dark.accent', label: __( 'Accent color', 'astra-builder' ), placeholder: '#38bdf8' },
                { path: 'modes.dark.buttons.background', label: __( 'Button background', 'astra-builder' ), placeholder: '#2563eb' },
                { path: 'modes.dark.buttons.color', label: __( 'Button text', 'astra-builder' ), placeholder: '#f8fafc' },
            ],
        },
    ];

    const AstraBlockRegistry = ( () => {
        const presets = [];
        const wrappers = [];
        const inspectorControls = {};

        const registerPreset = ( slug, config ) => {
            if ( ! config || ! config.blockName ) {
                return;
            }
            presets.push( Object.assign( { slug }, config ) );
        };

        const getPresetBlueprints = () => presets.map( ( preset ) => ( {
            name: preset.blockName,
            title: preset.title || preset.slug,
            description: preset.description || '',
            attributes: preset.attributes || {},
            innerBlocks: preset.innerBlocks || [],
        } ) );

        const getWrapperBlueprints = () => wrappers.map( ( wrapper ) => ( {
            name: wrapper.name,
            title: wrapper.title,
            description: wrapper.description,
        } ) );

        const registerInspectorControl = ( blockName, ControlComponent ) => {
            if ( ! blockName || ! ControlComponent ) {
                return;
            }
            if ( ! inspectorControls[ blockName ] ) {
                inspectorControls[ blockName ] = [];
            }
            inspectorControls[ blockName ].push( ControlComponent );
        };

        if ( addFilter ) {
            addFilter( 'editor.BlockEdit', 'astra-builder/custom-inspectors', ( BlockEdit ) => ( props ) => {
                const list = ( inspectorControls[ props.name ] || [] ).concat( inspectorControls['*'] || [] );
                if ( ! list.length ) {
                    return wp.element.createElement( BlockEdit, props );
                }
                return wp.element.createElement(
                    Fragment,
                    null,
                    wp.element.createElement( BlockEdit, props ),
                    list.map( ( ControlComponent, index ) =>
                        wp.element.createElement( ControlComponent, Object.assign( {}, props, { key: `${ props.clientId }-control-${ index }` } ) )
                    )
                );
            } );
        }

        const registerWrapperBlock = ( name, settings ) => {
            if ( ! registerBlockType || ! settings || ! settings.block ) {
                return;
            }

            registerBlockType( name, settings.block );
            if ( settings.palette ) {
                wrappers.push( Object.assign( { name }, settings.palette ) );
            }
        };

        return {
            registerPreset,
            getPresetBlueprints,
            registerWrapperBlock,
            registerInspectorControl,
            getWrapperBlueprints,
        };
    } )();

    const bindingSources = bindingConfig.sources || {};
    const bindingSourceOptions = Object.keys( bindingSources ).map( ( key ) => ( {
        value: key,
        label: bindingSources[ key ] && bindingSources[ key ].label ? bindingSources[ key ].label : key,
    } ) );

    const DataBindingInspector = ( props ) => {
        if ( ! InspectorControls ) {
            return null;
        }

        const { attributes, setAttributes, name } = props;
        if ( ! attributes || ! Object.prototype.hasOwnProperty.call( attributes, 'astraBinding' ) ) {
            return null;
        }

        const binding = attributes.astraBinding || {};
        const selectedSource = binding.source || ( bindingSourceOptions[ 0 ] ? bindingSourceOptions[ 0 ].value : 'wp_field' );
        const sourceOptions = bindingSources[ selectedSource ] && Array.isArray( bindingSources[ selectedSource ].options ) ? bindingSources[ selectedSource ].options : [];
        const blockType = getBlockType( name );
        const attributeOptions = blockType && blockType.attributes ? Object.keys( blockType.attributes ).map( ( attr ) => ( { label: attr, value: attr } ) ) : [];

        const updateBinding = ( next ) => {
            setAttributes( { astraBinding: Object.assign( {}, binding, next ) } );
        };

        const resetBinding = () => {
            setAttributes( { astraBinding: {} } );
        };

        return wp.element.createElement( InspectorControls, { group: 'advanced' },
            wp.element.createElement( PanelBody, { title: __( 'Data binding', 'astra-builder' ), initialOpen: false },
                bindingSourceOptions.length ? wp.element.createElement( SelectControl, {
                    label: __( 'Source', 'astra-builder' ),
                    value: selectedSource,
                    options: bindingSourceOptions,
                    onChange: ( value ) => updateBinding( { source: value, key: '' } ),
                } ) : null,
                sourceOptions.length ? wp.element.createElement( SelectControl, {
                    label: __( 'Field or key', 'astra-builder' ),
                    value: binding.key || '',
                    options: sourceOptions,
                    onChange: ( value ) => updateBinding( { key: value } ),
                } ) : wp.element.createElement( TextControl, {
                    label: __( 'Field or key', 'astra-builder' ),
                    value: binding.key || '',
                    onChange: ( value ) => updateBinding( { key: value } ),
                } ),
                attributeOptions.length ? wp.element.createElement( SelectControl, {
                    label: __( 'Target attribute', 'astra-builder' ),
                    value: binding.attribute || '',
                    options: [ { label: __( 'Select attribute', 'astra-builder' ), value: '' } ].concat( attributeOptions ),
                    onChange: ( value ) => updateBinding( { attribute: value } ),
                } ) : wp.element.createElement( TextControl, {
                    label: __( 'Target attribute', 'astra-builder' ),
                    value: binding.attribute || '',
                    onChange: ( value ) => updateBinding( { attribute: value } ),
                } ),
                wp.element.createElement( Button, { onClick: resetBinding, isSecondary: true }, __( 'Clear binding', 'astra-builder' ) )
            )
        );
    };

    AstraBlockRegistry.registerInspectorControl( '*', DataBindingInspector );

    const generatePanelId = () => 'panel-' + Math.random().toString( 36 ).slice( 2, 10 );

    const getDefaultPanel = ( index ) => ( {
        id: generatePanelId(),
        label: sprintf( __( 'Panel %d', 'astra-builder' ), index ),
        content: '',
    } );

    const ensurePanels = ( panels ) => {
        if ( Array.isArray( panels ) && panels.length ) {
            return panels;
        }
        return [ getDefaultPanel( 1 ) ];
    };

    const renderPanelContent = ( panel ) => {
        if ( RichText && RichText.Content ) {
            return wp.element.createElement( RichText.Content, {
                tagName: 'div',
                className: 'astra-builder-wrapper__panel-content',
                value: panel.content,
            } );
        }

        return wp.element.createElement( 'div', {
            className: 'astra-builder-wrapper__panel-content',
            dangerouslySetInnerHTML: { __html: panel.content || '' },
        } );
    };

    const createWrapperEditComponent = ( variant ) => ( props ) => {
        const { attributes, setAttributes } = props;
        const panels = ensurePanels( attributes.panels || [] );
        const blockProps = useBlockProps( { className: `astra-builder-wrapper-editor is-${ variant }` } );

        useEffect( () => {
            if ( ! attributes.panels || ! attributes.panels.length ) {
                setAttributes( { panels } );
            }
        }, [] );

        const updatePanels = ( nextPanels ) => {
            setAttributes( { panels: nextPanels } );
        };

        const updatePanel = ( panelId, payload ) => {
            const nextPanels = panels.map( ( panel ) => ( panel.id === panelId ? Object.assign( {}, panel, payload ) : panel ) );
            updatePanels( nextPanels );
        };

        const addPanel = () => {
            const nextPanel = getDefaultPanel( panels.length + 1 );
            updatePanels( panels.concat( nextPanel ) );
        };

        const removePanel = ( panelId ) => {
            if ( panels.length <= 1 ) {
                return;
            }
            updatePanels( panels.filter( ( panel ) => panel.id !== panelId ) );
        };

        return wp.element.createElement( 'div', blockProps,
            wp.element.createElement( 'div', { className: 'astra-builder-wrapper-preview' },
                panels.map( ( panel, index ) =>
                    wp.element.createElement( 'span', {
                        key: panel.id,
                        className: index === 0 ? 'is-active' : '',
                    }, panel.label || sprintf( __( 'Panel %d', 'astra-builder' ), index + 1 ) )
                )
            ),
            panels.map( ( panel ) => wp.element.createElement( Card, { key: panel.id, className: 'astra-builder-wrapper-panel' },
                wp.element.createElement( CardHeader, null, panel.label || __( 'Panel', 'astra-builder' ) ),
                wp.element.createElement( CardBody, null,
                    wp.element.createElement( TextControl, {
                        label: __( 'Label', 'astra-builder' ),
                        value: panel.label,
                        onChange: ( value ) => updatePanel( panel.id, { label: value } ),
                    } ),
                    RichText ? wp.element.createElement( RichText, {
                        tagName: 'div',
                        value: panel.content,
                        onChange: ( value ) => updatePanel( panel.id, { content: value } ),
                        placeholder: __( 'Panel content…', 'astra-builder' ),
                    } ) : wp.element.createElement( TextareaControl, {
                        label: __( 'Content', 'astra-builder' ),
                        value: panel.content,
                        onChange: ( value ) => updatePanel( panel.id, { content: value } ),
                    } ),
                    panels.length > 1 ? wp.element.createElement( Button, {
                        isDestructive: true,
                        variant: 'secondary',
                        onClick: () => removePanel( panel.id ),
                    }, __( 'Remove panel', 'astra-builder' ) ) : null
                )
            ) ),
            wp.element.createElement( Button, { onClick: addPanel, variant: 'secondary' }, __( 'Add panel', 'astra-builder' ) )
        );
    };

    const createWrapperSaveComponent = ( variant ) => ( props ) => {
        const { attributes } = props;
        const panels = ensurePanels( attributes.panels || [] );
        const blockProps = useBlockPropsSave( {
            className: `astra-builder-wrapper is-${ variant }`,
            'data-wrapper-variant': variant,
            'data-autoplay': attributes.autoplay ? 'true' : 'false',
            'data-interval': attributes.interval || 5,
        } );

        return wp.element.createElement( 'div', blockProps,
            wp.element.createElement( 'div', { className: 'astra-builder-wrapper__nav' },
                panels.map( ( panel, index ) =>
                    wp.element.createElement( 'button', {
                        key: panel.id,
                        type: 'button',
                        className: 'astra-builder-wrapper__tab' + ( 0 === index ? ' is-active' : '' ),
                        tabIndex: -1,
                    }, panel.label || sprintf( __( 'Panel %d', 'astra-builder' ), index + 1 ) )
                )
            ),
            wp.element.createElement( 'div', { className: 'astra-builder-wrapper__panels' },
                panels.map( ( panel, index ) =>
                    wp.element.createElement( 'div', {
                        key: panel.id,
                        className: 'astra-builder-wrapper__panel',
                        'data-panel-index': index,
                    },
                    wp.element.createElement( 'div', { className: 'astra-builder-wrapper__panel-label' }, panel.label || sprintf( __( 'Panel %d', 'astra-builder' ), index + 1 ) ),
                    renderPanelContent( panel ) )
                )
            )
        );
    };

    const createWrapperInspector = ( variant ) => ( props ) => {
        if ( ! InspectorControls ) {
            return null;
        }

        const { attributes, setAttributes } = props;
        const showCarousel = 'carousel' === variant;

        return wp.element.createElement( InspectorControls, null,
            wp.element.createElement( PanelBody, { title: __( 'Wrapper behavior', 'astra-builder' ), initialOpen: false },
                wp.element.createElement( TextControl, {
                    label: __( 'Wrapper label', 'astra-builder' ),
                    value: attributes.wrapperLabel || '',
                    onChange: ( value ) => setAttributes( { wrapperLabel: value } ),
                } ),
                showCarousel ? wp.element.createElement( ToggleControl, {
                    label: __( 'Enable autoplay', 'astra-builder' ),
                    checked: !! attributes.autoplay,
                    onChange: ( value ) => setAttributes( { autoplay: value } ),
                } ) : null,
                showCarousel ? wp.element.createElement( RangeControl, {
                    label: __( 'Slide interval (seconds)', 'astra-builder' ),
                    min: 2,
                    max: 15,
                    value: attributes.interval || 5,
                    onChange: ( value ) => setAttributes( { interval: value } ),
                } ) : null
            )
        );
    };

    const registerWrapperBlock = ( name, options ) => {
        if ( ! registerBlockType ) {
            return;
        }

        const settings = {
            palette: {
                title: options.title,
                description: options.description,
            },
            block: {
                title: options.title,
                icon: options.icon || 'layout',
                category: 'layout',
                description: options.description,
                supports: { html: false },
                attributes: {
                    panels: { type: 'array', default: [] },
                    autoplay: { type: 'boolean', default: false },
                    interval: { type: 'number', default: 5 },
                    wrapperLabel: { type: 'string', default: '' },
                    astraBinding: { type: 'object', default: {} },
                },
                edit: createWrapperEditComponent( options.variant ),
                save: createWrapperSaveComponent( options.variant ),
            },
        };

        AstraBlockRegistry.registerWrapperBlock( name, settings );
        AstraBlockRegistry.registerInspectorControl( name, createWrapperInspector( options.variant ) );
    };

    registerWrapperBlock( 'astra-builder/tabs', {
        title: __( 'Tabs wrapper', 'astra-builder' ),
        description: __( 'Organize content into tabbed sections.', 'astra-builder' ),
        variant: 'tabs',
        icon: 'index-card',
    } );

    registerWrapperBlock( 'astra-builder/accordion', {
        title: __( 'Accordion wrapper', 'astra-builder' ),
        description: __( 'Toggle FAQs or dense information.', 'astra-builder' ),
        variant: 'accordion',
        icon: 'menu',
    } );

    registerWrapperBlock( 'astra-builder/carousel', {
        title: __( 'Carousel wrapper', 'astra-builder' ),
        description: __( 'Loop through testimonials or highlights.', 'astra-builder' ),
        variant: 'carousel',
        icon: 'images-alt2',
    } );

    AstraBlockRegistry.registerPreset( 'astra-hero-highlight', {
        blockName: 'core/cover',
        title: __( 'Hero highlight', 'astra-builder' ),
        description: __( 'Full-bleed hero with strong call to action.', 'astra-builder' ),
        attributes: {
            dimRatio: 30,
            overlayColor: '#0f172a',
            contentPosition: 'center center',
            minHeight: 520,
        },
        innerBlocks: [
            {
                name: 'core/heading',
                attributes: { level: 1, content: __( 'Launch something beautiful', 'astra-builder' ) },
            },
            {
                name: 'core/paragraph',
                attributes: { content: __( 'Pair bold copy with immersive imagery to guide visitors toward action.', 'astra-builder' ) },
            },
            {
                name: 'core/buttons',
                innerBlocks: [
                    { name: 'core/button', attributes: { text: __( 'Get started', 'astra-builder' ), url: '#' } },
                ],
            },
        ],
    } );

    AstraBlockRegistry.registerPreset( 'astra-feature-grid', {
        blockName: 'core/columns',
        title: __( 'Feature grid', 'astra-builder' ),
        description: __( 'Three-column layout for benefits or services.', 'astra-builder' ),
        attributes: { columns: 3 },
        innerBlocks: [
            { name: 'core/column', innerBlocks: [ { name: 'core/heading', attributes: { level: 3, content: __( 'Speed', 'astra-builder' ) } }, { name: 'core/paragraph', attributes: { content: __( 'Lightning fast publishing.', 'astra-builder' ) } } ] },
            { name: 'core/column', innerBlocks: [ { name: 'core/heading', attributes: { level: 3, content: __( 'Control', 'astra-builder' ) } }, { name: 'core/paragraph', attributes: { content: __( 'Pixel-perfect layouts.', 'astra-builder' ) } } ] },
            { name: 'core/column', innerBlocks: [ { name: 'core/heading', attributes: { level: 3, content: __( 'Support', 'astra-builder' ) } }, { name: 'core/paragraph', attributes: { content: __( 'Guided onboarding and success.', 'astra-builder' ) } } ] },
        ],
    } );

    AstraBlockRegistry.registerPreset( 'astra-story-carousel', {
        blockName: 'core/group',
        title: __( 'Story carousel', 'astra-builder' ),
        description: __( 'Stacked quotes ready for the carousel wrapper.', 'astra-builder' ),
        innerBlocks: [
            { name: 'core/quote', attributes: { value: __( 'This builder unlocked our creativity.', 'astra-builder' ), citation: 'Alex, Founder' } },
            { name: 'core/quote', attributes: { value: __( 'Building complex layouts now feels simple.', 'astra-builder' ), citation: 'Jamie, Designer' } },
        ],
    } );

    const createFormField = ( type = 'text' ) => ( {
        id: 'field-' + Math.random().toString( 36 ).slice( 2, 10 ),
        label: '',
        name: '',
        type,
        placeholder: '',
        required: false,
        options: '',
        helperText: '',
    } );

    const createFormStep = ( index ) => ( {
        id: 'step-' + Math.random().toString( 36 ).slice( 2, 10 ),
        label: sprintf( __( 'Step %d', 'astra-builder' ), index ),
        description: '',
        fields: [ createFormField() ],
    } );

    const ensureSteps = ( steps ) => {
        if ( Array.isArray( steps ) && steps.length ) {
            return steps;
        }
        return [ createFormStep( 1 ) ];
    };

    const FORM_FIELD_TYPES = [
        { label: __( 'Text', 'astra-builder' ), value: 'text' },
        { label: __( 'Email', 'astra-builder' ), value: 'email' },
        { label: __( 'Textarea', 'astra-builder' ), value: 'textarea' },
        { label: __( 'Select', 'astra-builder' ), value: 'select' },
        { label: __( 'Checkbox', 'astra-builder' ), value: 'checkbox' },
    ];

    const FormFieldEditor = ( { field, onChange, onRemove } ) => {
        const optionsControl = 'select' === field.type ? wp.element.createElement( TextareaControl, {
            label: __( 'Options (one per line)', 'astra-builder' ),
            value: field.options || '',
            onChange: ( value ) => onChange( Object.assign( {}, field, { options: value } ) ),
        } ) : null;

        return wp.element.createElement( Card, { className: 'astra-builder-form-field', key: field.id },
            wp.element.createElement( CardHeader, null, field.label || __( 'Form field', 'astra-builder' ) ),
            wp.element.createElement( CardBody, null,
                wp.element.createElement( TextControl, {
                    label: __( 'Label', 'astra-builder' ),
                    value: field.label,
                    onChange: ( value ) => onChange( Object.assign( {}, field, { label: value } ) ),
                } ),
                wp.element.createElement( TextControl, {
                    label: __( 'Field name', 'astra-builder' ),
                    value: field.name,
                    onChange: ( value ) => onChange( Object.assign( {}, field, { name: value } ) ),
                    help: __( 'Lowercase with no spaces. Used as the submission key.', 'astra-builder' ),
                } ),
                wp.element.createElement( SelectControl, {
                    label: __( 'Field type', 'astra-builder' ),
                    value: field.type,
                    options: FORM_FIELD_TYPES,
                    onChange: ( value ) => onChange( Object.assign( {}, field, { type: value } ) ),
                } ),
                wp.element.createElement( TextControl, {
                    label: __( 'Placeholder', 'astra-builder' ),
                    value: field.placeholder,
                    onChange: ( value ) => onChange( Object.assign( {}, field, { placeholder: value } ) ),
                } ),
                optionsControl,
                wp.element.createElement( ToggleControl, {
                    label: __( 'Required', 'astra-builder' ),
                    checked: !! field.required,
                    onChange: ( value ) => onChange( Object.assign( {}, field, { required: value } ) ),
                } ),
                wp.element.createElement( TextareaControl, {
                    label: __( 'Helper text', 'astra-builder' ),
                    value: field.helperText,
                    onChange: ( value ) => onChange( Object.assign( {}, field, { helperText: value } ) ),
                } ),
                wp.element.createElement( Button, {
                    isDestructive: true,
                    variant: 'secondary',
                    onClick: () => onRemove( field.id ),
                }, __( 'Remove field', 'astra-builder' ) )
            )
        );
    };

    const FormStepEditor = ( { step, index, onChange, onRemove, onAddField } ) => wp.element.createElement( Card, { className: 'astra-builder-form-step', key: step.id },
        wp.element.createElement( CardHeader, null,
            wp.element.createElement( 'strong', null, step.label || sprintf( __( 'Step %d', 'astra-builder' ), index + 1 ) ),
            index > 0 ? wp.element.createElement( Button, { isDestructive: true, onClick: () => onRemove( step.id ) }, __( 'Remove step', 'astra-builder' ) ) : null
        ),
        wp.element.createElement( CardBody, null,
            wp.element.createElement( TextControl, {
                label: __( 'Step label', 'astra-builder' ),
                value: step.label,
                onChange: ( value ) => onChange( Object.assign( {}, step, { label: value } ) ),
            } ),
            wp.element.createElement( TextareaControl, {
                label: __( 'Description', 'astra-builder' ),
                value: step.description,
                onChange: ( value ) => onChange( Object.assign( {}, step, { description: value } ) ),
            } ),
            ( step.fields || [] ).map( ( field ) =>
                wp.element.createElement( FormFieldEditor, {
                    key: field.id,
                    field,
                    onChange: ( nextField ) => {
                        const nextFields = step.fields.map( ( existing ) => ( existing.id === nextField.id ? nextField : existing ) );
                        onChange( Object.assign( {}, step, { fields: nextFields } ) );
                    },
                    onRemove: ( fieldId ) => {
                        const nextFields = step.fields.filter( ( existing ) => existing.id !== fieldId );
                        onChange( Object.assign( {}, step, { fields: nextFields } ) );
                    },
                } )
            ),
            wp.element.createElement( Button, { variant: 'secondary', onClick: () => onAddField( step.id ) }, __( 'Add field', 'astra-builder' ) )
        )
    );

    const FormEdit = ( props ) => {
        const { attributes, setAttributes } = props;
        const steps = ensureSteps( attributes.steps || [] );
        const blockProps = useBlockProps( { className: 'astra-builder-form-editor' } );

        useEffect( () => {
            if ( ! attributes.steps || ! attributes.steps.length ) {
                setAttributes( { steps } );
            }
        }, [] );

        const updateSteps = ( nextSteps ) => setAttributes( { steps: nextSteps } );

        const updateStep = ( stepId, payload ) => {
            const nextSteps = steps.map( ( step ) => ( step.id === stepId ? Object.assign( {}, step, payload ) : step ) );
            updateSteps( nextSteps );
        };

        const removeStep = ( stepId ) => {
            if ( steps.length <= 1 ) {
                return;
            }
            updateSteps( steps.filter( ( step ) => step.id !== stepId ) );
        };

        const addStep = () => {
            const nextStep = createFormStep( steps.length + 1 );
            updateSteps( steps.concat( nextStep ) );
        };

        const addField = ( stepId ) => {
            const nextSteps = steps.map( ( step ) => {
                if ( step.id !== stepId ) {
                    return step;
                }
                const nextField = createFormField();
                const count = ( step.fields || [] ).length + 1;
                nextField.label = sprintf( __( 'Field %d', 'astra-builder' ), count );
                nextField.name = 'field_' + Math.random().toString( 36 ).slice( 2, 7 );
                return Object.assign( {}, step, { fields: ( step.fields || [] ).concat( nextField ) } );
            } );
            updateSteps( nextSteps );
        };

        const preview = steps.map( ( step, index ) =>
            wp.element.createElement( 'span', { key: step.id, className: index === 0 ? 'is-active' : '' }, step.label || sprintf( __( 'Step %d', 'astra-builder' ), index + 1 ) )
        );

        return wp.element.createElement( 'div', blockProps,
            wp.element.createElement( 'div', { className: 'astra-builder-form-editor__meta' },
                wp.element.createElement( TextControl, {
                    label: __( 'Form ID', 'astra-builder' ),
                    value: attributes.formId || '',
                    onChange: ( value ) => setAttributes( { formId: value } ),
                    help: __( 'Used to connect REST submissions with this block.', 'astra-builder' ),
                } ),
                wp.element.createElement( TextControl, {
                    label: __( 'Submit button label', 'astra-builder' ),
                    value: attributes.submitLabel || __( 'Submit', 'astra-builder' ),
                    onChange: ( value ) => setAttributes( { submitLabel: value } ),
                } )
            ),
            wp.element.createElement( 'div', { className: 'astra-builder-form-editor__preview' }, preview ),
            steps.map( ( step, index ) =>
                wp.element.createElement( FormStepEditor, {
                    key: step.id,
                    step,
                    index,
                    onChange: ( payload ) => updateStep( step.id, payload ),
                    onRemove: removeStep,
                    onAddField: addField,
                } )
            ),
            wp.element.createElement( Button, { variant: 'secondary', onClick: addStep }, __( 'Add step', 'astra-builder' ) )
        );
    };

    const renderFormField = ( field ) => {
        const name = field.name || field.id;
        const commonProps = {
            name,
            placeholder: field.placeholder || '',
            'data-required-field': field.required ? 'true' : 'false',
        };

        const label = wp.element.createElement( 'label', { className: 'astra-builder-form__label', htmlFor: name }, field.label || name );
        let control = null;

        if ( 'textarea' === field.type ) {
            control = wp.element.createElement( 'textarea', Object.assign( {}, commonProps, { id: name, required: field.required ? true : undefined } ) );
        } else if ( 'select' === field.type ) {
            const options = ( field.options || '' ).split( /\r?\n/ ).filter( Boolean );
            control = wp.element.createElement( 'select', Object.assign( {}, commonProps, { id: name, required: field.required ? true : undefined } ),
                options.map( ( option ) => wp.element.createElement( 'option', { key: option, value: option }, option ) )
            );
        } else if ( 'checkbox' === field.type ) {
            control = wp.element.createElement( 'input', Object.assign( {}, commonProps, { id: name, type: 'checkbox', value: '1', required: field.required ? true : undefined } ) );
        } else {
            const inputType = 'email' === field.type ? 'email' : 'text';
            control = wp.element.createElement( 'input', Object.assign( {}, commonProps, { id: name, type: inputType, required: field.required ? true : undefined } ) );
        }

        const helper = field.helperText ? wp.element.createElement( 'small', { className: 'astra-builder-form__help' }, field.helperText ) : null;

        return wp.element.createElement( 'div', { className: 'astra-builder-form__field', key: field.id }, label, control, helper );
    };

    const FormSave = ( props ) => {
        const { attributes } = props;
        const steps = ensureSteps( attributes.steps || [] );
        const honeypotName = ( attributes.spamProtection && attributes.spamProtection.honeypotField ) || defaultSpamSettings.honeypotField || 'astra_builder_field';
        const successMessage = attributes.successMessage || __( 'Thanks! We received your submission.', 'astra-builder' );
        const requiredFields = [];
        steps.forEach( ( step ) => {
            ( step.fields || [] ).forEach( ( field ) => {
                if ( field.required && field.name ) {
                    requiredFields.push( field.name );
                }
            } );
        } );

        const requirementsValue = JSON.stringify( requiredFields );
        const formProps = useBlockPropsSave( {
            className: 'astra-builder-form',
            'data-form-id': attributes.formId || '',
            'data-success-message': successMessage,
            'data-stepper': steps.length > 1 ? 'true' : 'false',
            'data-endpoint': `/wp-json/${ restNamespace }/form-submissions`,
            'data-integration': attributes.integration || 'local',
            'data-honeypot': honeypotName,
        } );

        const stepElements = steps.map( ( step, index ) => {
            const controls = [];
            if ( index > 0 ) {
                controls.push( wp.element.createElement( 'button', { type: 'button', className: 'astra-builder-form__nav is-prev', 'data-astra-step-prev': 'true' }, __( 'Previous', 'astra-builder' ) ) );
            }
            if ( index < steps.length - 1 ) {
                controls.push( wp.element.createElement( 'button', { type: 'button', className: 'astra-builder-form__nav is-next', 'data-astra-step-next': 'true' }, __( 'Next', 'astra-builder' ) ) );
            }

            return wp.element.createElement( 'fieldset', {
                key: step.id,
                className: 'astra-builder-form__step',
                'data-astra-form-step': index,
            },
                wp.element.createElement( 'legend', null, step.label || sprintf( __( 'Step %d', 'astra-builder' ), index + 1 ) ),
                step.description ? wp.element.createElement( 'p', { className: 'astra-builder-form__description' }, step.description ) : null,
                ( step.fields || [] ).map( renderFormField ),
                controls.length ? wp.element.createElement( 'div', { className: 'astra-builder-form__step-controls' }, controls ) : null
            );
        } );

        return wp.element.createElement( 'form', formProps,
            wp.element.createElement( 'input', { type: 'hidden', name: '_astra_form_id', value: attributes.formId || '' } ),
            wp.element.createElement( 'input', { type: 'hidden', name: '_astra_timestamp', value: '' } ),
            wp.element.createElement( 'input', { type: 'hidden', name: '_astra_requirements', value: requirementsValue } ),
            wp.element.createElement( 'input', { type: 'text', className: 'astra-builder-form__honeypot', name: honeypotName, tabIndex: '-1', autoComplete: 'off', style: { position: 'absolute', left: '-999em' } } ),
            wp.element.createElement( 'div', { className: 'astra-builder-form__steps-indicator' },
                steps.map( ( step, index ) => wp.element.createElement( 'span', { key: step.id, className: index === 0 ? 'is-active' : '' }, step.label || index + 1 ) )
            ),
            stepElements,
            wp.element.createElement( 'div', { className: 'astra-builder-form__status', 'aria-live': 'polite' } ),
            wp.element.createElement( 'button', { type: 'submit', className: 'astra-builder-form__submit' }, attributes.submitLabel || __( 'Submit', 'astra-builder' ) )
        );
    };

    if ( registerBlockType ) {
        registerBlockType( 'astra-builder/form', {
            title: __( 'Astra Form', 'astra-builder' ),
            icon: 'feedback',
            category: 'widgets',
            description: __( 'Collect submissions with validation, spam protection, and REST persistence.', 'astra-builder' ),
            supports: { html: false },
            attributes: {
                formId: { type: 'string', default: '' },
                submitLabel: { type: 'string', default: __( 'Submit', 'astra-builder' ) },
                successMessage: { type: 'string', default: __( 'Thanks! We received your submission.', 'astra-builder' ) },
                integration: { type: 'string', default: 'local' },
                spamProtection: { type: 'object', default: defaultSpamSettings },
                steps: { type: 'array', default: [] },
                astraBinding: { type: 'object', default: {} },
            },
            edit: FormEdit,
            save: FormSave,
        } );
    }

    const FormBehaviorInspector = ( props ) => {
        if ( ! InspectorControls ) {
            return null;
        }

        const { attributes, setAttributes } = props;
        const spam = attributes.spamProtection || defaultSpamSettings;

        return wp.element.createElement( InspectorControls, null,
            wp.element.createElement( PanelBody, { title: __( 'Form behavior', 'astra-builder' ), initialOpen: false },
                wp.element.createElement( TextControl, {
                    label: __( 'Success message', 'astra-builder' ),
                    value: attributes.successMessage || '',
                    onChange: ( value ) => setAttributes( { successMessage: value } ),
                } ),
                wp.element.createElement( TextControl, {
                    label: __( 'Honeypot field name', 'astra-builder' ),
                    value: spam.honeypotField || defaultSpamSettings.honeypotField || 'astra_builder_field',
                    onChange: ( value ) => setAttributes( { spamProtection: Object.assign( {}, spam, { honeypotField: value } ) } ),
                } ),
                wp.element.createElement( RangeControl, {
                    label: __( 'Minimum seconds before submission', 'astra-builder' ),
                    min: 0,
                    max: 30,
                    value: spam.minimumSeconds || defaultSpamSettings.minimumSeconds || 3,
                    onChange: ( value ) => setAttributes( { spamProtection: Object.assign( {}, spam, { minimumSeconds: value } ) } ),
                } ),
                wp.element.createElement( SelectControl, {
                    label: __( 'Integration', 'astra-builder' ),
                    value: attributes.integration || 'local',
                    options: [
                        { label: __( 'Store in WordPress', 'astra-builder' ), value: 'local' },
                        { label: __( 'Email notification', 'astra-builder' ), value: 'email' },
                        { label: __( 'CRM / webhook', 'astra-builder' ), value: 'crm' },
                    ],
                    onChange: ( value ) => setAttributes( { integration: value } ),
                } )
            )
        );
    };

    AstraBlockRegistry.registerInspectorControl( 'astra-builder/form', FormBehaviorInspector );

    AstraBlockRegistry.getPresetBlueprints().forEach( ( blueprint ) => {
        PALETTE_BLOCKS.push( blueprint );
    } );

    AstraBlockRegistry.getWrapperBlueprints().forEach( ( blueprint ) => {
        PALETTE_BLOCKS.push( blueprint );
    } );

    PALETTE_BLOCKS.push( {
        name: 'astra-builder/form',
        title: __( 'Advanced form', 'astra-builder' ),
        description: __( 'Validated multi-step forms.', 'astra-builder' ),
    } );

    const getDefaultConditionList = ( key ) => {
        if ( pluginData.defaults && pluginData.defaults.conditions && Array.isArray( pluginData.defaults.conditions[ key ] ) ) {
            return pluginData.defaults.conditions[ key ];
        }
        return [];
    };

    const CONDITION_DEFAULTS = {
        postTypes: sanitizeConditionList( getDefaultConditionList( 'postTypes' ) ),
        taxonomies: sanitizeConditionList( getDefaultConditionList( 'taxonomies' ) ),
        roles: sanitizeConditionList( getDefaultConditionList( 'roles' ) ),
    };

    const cloneConditions = ( source ) => ({
        postTypes: sanitizeConditionList( source && source.postTypes ? source.postTypes : CONDITION_DEFAULTS.postTypes ),
        taxonomies: sanitizeConditionList( source && source.taxonomies ? source.taxonomies : CONDITION_DEFAULTS.taxonomies ),
        roles: sanitizeConditionList( source && source.roles ? source.roles : CONDITION_DEFAULTS.roles ),
    });

    const CONDITIONS_META_KEY = metaKeys.conditions || '_astra_builder_conditions';
    const STYLE_META_KEY = metaKeys.styles || '_astra_builder_style_overrides';

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

    const ConditionChecklist = ( { items, value, onChange } ) => {
        if ( ! items || ! items.length ) {
            return wp.element.createElement( 'p', { className: 'astra-builder__conditions-empty' }, __( 'No options available for this condition.', 'astra-builder' ) );
        }

        return wp.element.createElement( Fragment, null,
            items.map( ( item ) => wp.element.createElement( CheckboxControl, {
                key: item.slug,
                label: item.label || item.slug,
                checked: value.includes( item.slug ),
                onChange: ( isChecked ) => {
                    const next = value.slice();
                    const index = next.indexOf( item.slug );
                    if ( isChecked && index === -1 ) {
                        next.push( item.slug );
                    } else if ( ! isChecked && index > -1 ) {
                        next.splice( index, 1 );
                    }
                    onChange( sanitizeConditionList( next ) );
                },
            } ) )
        );
    };

    const useDesignTokens = () => {
        const [ tokens, setTokens ] = useState( initialTokenState );
        const [ isLoading, setIsLoading ] = useState( ! initialTokenState );
        const [ isSaving, setIsSaving ] = useState( false );
        const [ error, setError ] = useState( null );
        const [ hasChanges, setHasChanges ] = useState( false );

        useEffect( () => {
            if ( ! apiFetch ) {
                setIsLoading( false );
                setError( __( 'REST API unavailable.', 'astra-builder' ) );
                return;
            }

            setIsLoading( true );

            apiFetch( { path: '/' + restNamespace + '/tokens' } ).then( ( response ) => {
                setTokens( response );
                setHasChanges( false );
                setIsLoading( false );
                setError( null );
            } ).catch( ( fetchError ) => {
                setIsLoading( false );
                setError( fetchError && fetchError.message ? fetchError.message : __( 'Unable to load tokens.', 'astra-builder' ) );
            } );
        }, [ restNamespace ] );

        const updateToken = useCallback( ( path, value ) => {
            setTokens( ( current ) => setPathValue( current || {}, path, value ) );
            setHasChanges( true );
        }, [] );

        const saveTokens = useCallback( () => {
            if ( ! apiFetch || ! tokens ) {
                return;
            }

            setIsSaving( true );
            setError( null );

            apiFetch( {
                path: '/' + restNamespace + '/tokens',
                method: 'POST',
                data: tokens,
            } ).then( ( response ) => {
                setTokens( response );
                setHasChanges( false );
                setIsSaving( false );
            } ).catch( ( fetchError ) => {
                setIsSaving( false );
                setError( fetchError && fetchError.message ? fetchError.message : __( 'Unable to save tokens.', 'astra-builder' ) );
            } );
        }, [ tokens, restNamespace ] );

        return { tokens, isLoading, error, updateToken, saveTokens, isSaving, hasChanges };
    };

    const TokenField = ( { field, value, onChange } ) => {
        if ( 'toggle' === field.control ) {
            return wp.element.createElement( ToggleControl, {
                label: field.label,
                checked: !! value,
                help: field.help,
                onChange: ( nextValue ) => onChange( !! nextValue ),
            } );
        }

        if ( 'select' === field.control && Array.isArray( field.options ) ) {
            return wp.element.createElement( SelectControl, {
                label: field.label,
                value: value === undefined || value === null ? '' : value,
                options: field.options,
                help: field.help,
                onChange: ( nextValue ) => onChange( nextValue ),
            } );
        }

        return wp.element.createElement( TextControl, {
            label: field.label,
            value: value === undefined || value === null ? '' : value,
            placeholder: field.placeholder,
            help: field.help,
            onChange: ( nextValue ) => onChange( nextValue ),
        } );
    };

    const useTemplateStyleOverrides = () => {
        const { overrides, meta } = useSelect( ( select ) => {
            const editor = select( 'core/editor' );
            const postMeta = editor.getEditedPostAttribute ? ( editor.getEditedPostAttribute( 'meta' ) || {} ) : {};
            const savedOverrides = postMeta[ STYLE_META_KEY ];
            return {
                overrides: savedOverrides && typeof savedOverrides === 'object' ? savedOverrides : {},
                meta: postMeta,
            };
        }, [ STYLE_META_KEY ] );

        const { editPost } = useDispatch( 'core/editor' );

        const persist = useCallback( ( nextOverrides ) => {
            const metaPatch = Object.assign( {}, meta );

            if ( nextOverrides && Object.keys( nextOverrides ).length ) {
                metaPatch[ STYLE_META_KEY ] = nextOverrides;
            } else {
                delete metaPatch[ STYLE_META_KEY ];
            }

            editPost( { meta: metaPatch } );
        }, [ editPost, meta ] );

        const updateOverride = useCallback( ( path, value ) => {
            const next = setPathValue( overrides, path, value );
            persist( next );
        }, [ overrides, persist ] );

        const resetOverride = useCallback( ( path ) => {
            const next = unsetPathValue( overrides, path );
            persist( next );
        }, [ overrides, persist ] );

        return { overrides, updateOverride, resetOverride };
    };

    const TokenOverrideField = ( { field, tokens, overrides, onChange, onReset } ) => {
        const overrideValue = getPathValue( overrides, field.path );
        const inheritedValue = getPathValue( tokens, field.path );
        const hasOverride = overrideValue !== undefined;

        if ( 'toggle' === field.control ) {
            const checked = hasOverride ? !! overrideValue : !! inheritedValue;
            return wp.element.createElement( 'div', { className: 'astra-builder__override-field' + ( hasOverride ? ' has-override' : '' ) },
                wp.element.createElement( ToggleControl, {
                    label: field.label,
                    checked,
                    help: hasOverride ? __( 'Override active', 'astra-builder' ) : __( 'Inherits global value', 'astra-builder' ),
                    onChange: ( next ) => onChange( field.path, !! next ),
                } ),
                hasOverride ? wp.element.createElement( Button, { isSmall: true, isSecondary: true, onClick: () => onReset( field.path ) }, __( 'Reset', 'astra-builder' ) ) : null
            );
        }

        const resolvedValue = hasOverride ? overrideValue : ( inheritedValue === undefined || inheritedValue === null ? '' : inheritedValue );

        const handleChange = ( nextValue ) => {
            if ( nextValue === '' ) {
                onReset( field.path );
                return;
            }
            onChange( field.path, nextValue );
        };

        if ( 'select' === field.control && Array.isArray( field.options ) ) {
            return wp.element.createElement( 'div', { className: 'astra-builder__override-field' + ( hasOverride ? ' has-override' : '' ) },
                wp.element.createElement( SelectControl, {
                    label: field.label,
                    value: resolvedValue,
                    options: field.options,
                    help: hasOverride ? __( 'Override active', 'astra-builder' ) : __( 'Inherits global value', 'astra-builder' ),
                    onChange: handleChange,
                } ),
                hasOverride ? wp.element.createElement( Button, { isSmall: true, isSecondary: true, onClick: () => onReset( field.path ) }, __( 'Reset', 'astra-builder' ) ) : null
            );
        }

        return wp.element.createElement( 'div', { className: 'astra-builder__override-field' + ( hasOverride ? ' has-override' : '' ) },
            wp.element.createElement( TextControl, {
                label: field.label,
                value: resolvedValue,
                placeholder: field.placeholder,
                help: hasOverride ? __( 'Override active', 'astra-builder' ) : __( 'Inherits global value', 'astra-builder' ),
                onChange: handleChange,
            } ),
            hasOverride ? wp.element.createElement( Button, { isSmall: true, isSecondary: true, onClick: () => onReset( field.path ) }, __( 'Reset', 'astra-builder' ) ) : null
        );
    };

    const TemplateOverridesPanel = ( { tokens } ) => {
        const { overrides, updateOverride, resetOverride } = useTemplateStyleOverrides();

        if ( ! tokens ) {
            return null;
        }

        return wp.element.createElement( PanelBody, { title: __( 'Per-template overrides', 'astra-builder' ), initialOpen: false, className: 'astra-builder__overrides-panel' },
            wp.element.createElement( 'p', { className: 'astra-builder__design-section-description' }, __( 'Override specific tokens for this template only. Reset to fall back to the global system.', 'astra-builder' ) ),
            DESIGN_SECTIONS.map( ( section ) =>
                wp.element.createElement( 'div', { className: 'astra-builder__override-section', key: section.id },
                    wp.element.createElement( 'h4', null, section.title ),
                    section.fields.map( ( field ) =>
                        wp.element.createElement( TokenOverrideField, {
                            key: `${ section.id }-${ field.path }`,
                            field,
                            tokens,
                            overrides,
                            onChange: updateOverride,
                            onReset: resetOverride,
                        } )
                    )
                )
            )
        );
    };

    const DesignSystemPanel = () => {
        const { tokens, isLoading, error, updateToken, saveTokens, isSaving, hasChanges } = useDesignTokens();

        return wp.element.createElement( Card, { className: 'astra-builder__design-card' },
            wp.element.createElement( CardHeader, null, __( 'Design tokens', 'astra-builder' ) ),
            wp.element.createElement( CardBody, null,
                wp.element.createElement( 'p', { className: 'astra-builder__design-description' }, __( 'Manage the site-wide typography, component, and mode tokens. Changes update theme.json and CSS variables automatically.', 'astra-builder' ) ),
                error ? wp.element.createElement( Notice, { status: 'error', isDismissible: false }, error ) : null,
                isLoading ? wp.element.createElement( Spinner, null ) : null,
                tokens ? DESIGN_SECTIONS.map( ( section ) =>
                    wp.element.createElement( PanelBody, { key: section.id, title: section.title, initialOpen: 'typography' === section.id },
                        section.description ? wp.element.createElement( 'p', { className: 'astra-builder__design-section-description' }, section.description ) : null,
                        section.fields.map( ( field ) =>
                            wp.element.createElement( TokenField, {
                                key: `${ section.id }-${ field.path }`,
                                field,
                                value: getPathValue( tokens, field.path ),
                                onChange: ( nextValue ) => updateToken( field.path, nextValue ),
                            } )
                        )
                    )
                ) : null,
                tokens ? wp.element.createElement( TemplateOverridesPanel, { tokens } ) : null,
                wp.element.createElement( Button, {
                    variant: 'primary',
                    onClick: saveTokens,
                    isBusy: isSaving,
                    disabled: ! hasChanges || ! tokens || isSaving,
                }, hasChanges ? __( 'Save design tokens', 'astra-builder' ) : __( 'Saved', 'astra-builder' ) )
            )
        );
    };

    const TemplateConditionsPanel = () => {
        const { meta, conditions } = useSelect( ( select ) => {
            const editor = select( 'core/editor' );
            const editedMeta = editor.getEditedPostAttribute ? ( editor.getEditedPostAttribute( 'meta' ) || {} ) : {};
            const savedConditions = editedMeta[ CONDITIONS_META_KEY ] || CONDITION_DEFAULTS;
            return {
                meta: editedMeta,
                conditions: cloneConditions( savedConditions ),
            };
        }, [ CONDITIONS_META_KEY ] );

        const { editPost } = useDispatch( 'core/editor' );

        const updateConditions = useCallback( ( key, values ) => {
            const next = cloneConditions( conditions );
            next[ key ] = sanitizeConditionList( values );
            editPost( { meta: Object.assign( {}, meta, { [ CONDITIONS_META_KEY ]: next } ) } );
        }, [ conditions, editPost, meta ] );

        const postTypeOptions = conditionOptions.postTypes || [];
        const taxonomyOptions = conditionOptions.taxonomies || [];
        const roleOptions = conditionOptions.roles || [];

        return wp.element.createElement( 'div', { className: 'astra-builder__conditions-panel' },
            wp.element.createElement( 'p', { className: 'astra-builder__conditions-description' }, __( 'Assign this template to specific post types, taxonomy archives, or user roles.', 'astra-builder' ) ),
            wp.element.createElement( 'div', { className: 'astra-builder__conditions-grid' },
                wp.element.createElement( 'div', { className: 'astra-builder__conditions-group' },
                    wp.element.createElement( 'h4', null, __( 'Post types', 'astra-builder' ) ),
                    wp.element.createElement( ConditionChecklist, {
                        items: postTypeOptions,
                        value: conditions.postTypes,
                        onChange: ( next ) => updateConditions( 'postTypes', next ),
                    } )
                ),
                wp.element.createElement( 'div', { className: 'astra-builder__conditions-group' },
                    wp.element.createElement( 'h4', null, __( 'Taxonomies', 'astra-builder' ) ),
                    wp.element.createElement( ConditionChecklist, {
                        items: taxonomyOptions,
                        value: conditions.taxonomies,
                        onChange: ( next ) => updateConditions( 'taxonomies', next ),
                    } )
                ),
                wp.element.createElement( 'div', { className: 'astra-builder__conditions-group' },
                    wp.element.createElement( 'h4', null, __( 'User roles', 'astra-builder' ) ),
                    wp.element.createElement( ConditionChecklist, {
                        items: roleOptions,
                        value: conditions.roles,
                        onChange: ( next ) => updateConditions( 'roles', next ),
                    } )
                )
            )
        );
    };

    const INTERACTIVE_AUDIT_CHECKS = [
        {
            names: [ 'core/button' ],
            label: __( 'Button', 'astra-builder' ),
            getText: ( block ) => block && block.attributes ? ( block.attributes.text || block.attributes.content || '' ) : '',
        },
        {
            names: [ 'core/navigation-link', 'core/navigation-submenu' ],
            label: __( 'Navigation link', 'astra-builder' ),
            getText: ( block ) => block && block.attributes ? ( block.attributes.label || block.attributes.title || '' ) : '',
        },
        {
            names: [ 'core/search' ],
            label: __( 'Search form', 'astra-builder' ),
            getText: ( block ) => block && block.attributes ? ( block.attributes.label || block.attributes.placeholder || '' ) : '',
        },
    ];

    const useAccessibilityAudits = () => {
        const blocks = useSelect( ( select ) => {
            const editor = select( 'core/block-editor' );
            return editor && editor.getBlocks ? editor.getBlocks() : [];
        }, [] );

        return useMemo( () => {
            const results = {
                contrast: [],
                headings: [],
                keyboard: [],
            };

            const headingOrder = [];

            traverseBlocks( blocks, ( block ) => {
                if ( ! block ) {
                    return;
                }

                const style = block.attributes && block.attributes.style ? block.attributes.style : null;
                const textColor = getPathValue( style, [ 'color', 'text' ] );
                const backgroundColor = getPathValue( style, [ 'color', 'background' ] );

                if ( textColor && backgroundColor ) {
                    const textRgb = parseColorValue( textColor );
                    const backgroundRgb = parseColorValue( backgroundColor );
                    const ratio = textRgb && backgroundRgb ? getContrastRatio( textRgb, backgroundRgb ) : null;
                    if ( ratio && ratio < 4.5 ) {
                        results.contrast.push( {
                            message: sprintf( __( '%1$s contrast is %2$s:1.', 'astra-builder' ), getReadableBlockLabel( block ), ratio.toFixed( 2 ) ),
                            action: __( 'Increase the color contrast to at least 4.5:1.', 'astra-builder' ),
                        } );
                    }
                }

                if ( 'core/heading' === block.name ) {
                    const level = parseInt( block.attributes && block.attributes.level ? block.attributes.level : 2, 10 );
                    const safeLevel = Number.isFinite( level ) ? level : 2;
                    const label = extractReadableText( block.attributes && block.attributes.content ? block.attributes.content : '' ) || __( 'Heading', 'astra-builder' );
                    headingOrder.push( { level: safeLevel, label } );
                }

                INTERACTIVE_AUDIT_CHECKS.forEach( ( check ) => {
                    if ( check.names.indexOf( block.name ) === -1 ) {
                        return;
                    }
                    const value = check.getText ? check.getText( block ) : '';
                    const readable = extractReadableText( value );
                    if ( ! readable ) {
                        results.keyboard.push( {
                            message: sprintf( __( '%s is missing a readable label.', 'astra-builder' ), check.label ),
                            action: __( 'Add descriptive text or aria-labels so keyboard users know what each control does.', 'astra-builder' ),
                        } );
                    }
                } );
            } );

            let previousLevel = 0;

            headingOrder.forEach( ( heading ) => {
                if ( previousLevel && heading.level > previousLevel + 1 ) {
                    results.headings.push( {
                        message: sprintf( __( 'Heading "%1$s" skips from H%2$d to H%3$d.', 'astra-builder' ), heading.label, previousLevel, heading.level ),
                        action: __( 'Promote or insert the missing heading level to maintain logical order.', 'astra-builder' ),
                    } );
                }
                previousLevel = heading.level;
            } );

            return results;
        }, [ blocks ] );
    };

    const AuditSection = ( { title, description, issues, emptyMessage } ) => {
        const hasIssues = issues.length > 0;
        return wp.element.createElement( 'div', { className: 'astra-builder__audit-section' },
            wp.element.createElement( 'div', { className: 'astra-builder__audit-section-header' },
                wp.element.createElement( 'strong', null, title ),
                wp.element.createElement( 'span', { className: 'astra-builder__audit-status ' + ( hasIssues ? 'is-alert' : 'is-pass' ) }, hasIssues ? sprintf( __( '%d issues', 'astra-builder' ), issues.length ) : __( 'All good', 'astra-builder' ) )
            ),
            description ? wp.element.createElement( 'p', { className: 'astra-builder__audit-description' }, description ) : null,
            hasIssues ? wp.element.createElement( 'ul', { className: 'astra-builder__audit-list' },
                issues.map( ( issue, index ) =>
                    wp.element.createElement( 'li', { className: 'astra-builder__audit-issue', key: index },
                        wp.element.createElement( 'strong', null, issue.message ),
                        issue.action ? wp.element.createElement( 'span', null, issue.action ) : null
                    )
                )
            ) : wp.element.createElement( 'p', { className: 'astra-builder__audit-pass' }, emptyMessage )
        );
    };

    const AccessibilityAuditPanel = () => {
        const audits = useAccessibilityAudits();
        const totalIssues = audits.contrast.length + audits.headings.length + audits.keyboard.length;

        return wp.element.createElement( Card, { className: 'astra-builder__accessibility-card' },
            wp.element.createElement( CardHeader, null, __( 'Accessibility audits', 'astra-builder' ) ),
            wp.element.createElement( CardBody, null,
                wp.element.createElement( 'p', { className: 'astra-builder__accessibility-description' },
                    totalIssues ? sprintf( __( '%d issues detected across the current layout.', 'astra-builder' ), totalIssues ) : __( 'No blocking accessibility issues detected.', 'astra-builder' )
                ),
                wp.element.createElement( AuditSection, {
                    title: __( 'Contrast', 'astra-builder' ),
                    description: __( 'Ensure text and surfaces meet WCAG contrast targets.', 'astra-builder' ),
                    issues: audits.contrast,
                    emptyMessage: __( 'Color pairs meet the minimum contrast ratio.', 'astra-builder' ),
                } ),
                wp.element.createElement( AuditSection, {
                    title: __( 'Heading order', 'astra-builder' ),
                    description: __( 'Headings should progress without skipping levels.', 'astra-builder' ),
                    issues: audits.headings,
                    emptyMessage: __( 'Heading levels are sequential.', 'astra-builder' ),
                } ),
                wp.element.createElement( AuditSection, {
                    title: __( 'Keyboard navigation', 'astra-builder' ),
                    description: __( 'Interactive controls need clear, focusable labels.', 'astra-builder' ),
                    issues: audits.keyboard,
                    emptyMessage: __( 'Interactive elements expose descriptive labels.', 'astra-builder' ),
                } )
            )
        );
    };

    const TemplatePreviewControls = () => {
        const [ isLoading, setIsLoading ] = useState( false );
        const [ error, setError ] = useState( null );
        const [ previewLink, setPreviewLink ] = useState( null );
        const [ metrics, setMetrics ] = useState( null );

        const { postId, content, status, conditions, styles } = useSelect( ( select ) => {
            const editor = select( 'core/editor' );
            const meta = editor.getEditedPostAttribute ? ( editor.getEditedPostAttribute( 'meta' ) || {} ) : {};
            const savedConditions = meta[ CONDITIONS_META_KEY ] || CONDITION_DEFAULTS;
            return {
                postId: editor.getCurrentPostId ? editor.getCurrentPostId() : null,
                content: editor.getEditedPostContent ? editor.getEditedPostContent() : '',
                status: editor.getEditedPostAttribute ? editor.getEditedPostAttribute( 'status' ) : 'draft',
                conditions: cloneConditions( savedConditions ),
                styles: meta[ STYLE_META_KEY ] || {},
            };
        }, [ CONDITIONS_META_KEY, STYLE_META_KEY ] );

        const handlePreview = useCallback( () => {
            if ( ! apiFetch || ! postId ) {
                setError( __( 'Previewing is unavailable right now.', 'astra-builder' ) );
                return;
            }

            setIsLoading( true );
            setError( null );

            apiFetch( {
                path: '/' + restNamespace + '/templates/' + postId + '/preview',
                method: 'POST',
                data: {
                    content,
                    conditions,
                    status,
                    styles,
                },
            } ).then( ( response ) => {
                setIsLoading( false );
                if ( response && response.preview_url ) {
                    setPreviewLink( response.preview_url );
                    window.open( response.preview_url, '_blank', 'noopener' );
                } else {
                    setError( __( 'Preview created but no URL was returned.', 'astra-builder' ) );
                }
            } ).catch( ( fetchError ) => {
                setIsLoading( false );
                setError( fetchError && fetchError.message ? fetchError.message : __( 'Failed to create preview.', 'astra-builder' ) );
            } );
        }, [ postId, content, conditions, status, styles, restNamespace ] );

        useEffect( () => {
            const handleMessage = ( event ) => {
                if ( ! event || ! event.data || event.data.source !== 'astra-builder-preview-metrics' ) {
                    return;
                }

                if ( event.origin && window.location && event.origin !== window.location.origin ) {
                    return;
                }

                const payload = event.data.payload || {};

                setMetrics( {
                    lcp: 'number' === typeof payload.lcp ? payload.lcp : null,
                    cls: 'number' === typeof payload.cls ? payload.cls : null,
                    lcpTarget: payload.lcpTarget || previewMetricTargets.lcpTarget,
                    clsTarget: payload.clsTarget || previewMetricTargets.clsTarget,
                } );
            };

            window.addEventListener( 'message', handleMessage );

            return () => window.removeEventListener( 'message', handleMessage );
        }, [ previewMetricTargets.lcpTarget, previewMetricTargets.clsTarget ] );

        const lcpExceeded = metrics && 'number' === typeof metrics.lcp && metrics.lcpTarget && metrics.lcp > metrics.lcpTarget;
        const clsExceeded = metrics && 'number' === typeof metrics.cls && metrics.clsTarget && metrics.cls > metrics.clsTarget;
        const hasMetricAlert = !! ( lcpExceeded || clsExceeded );

        return wp.element.createElement( 'div', { className: 'astra-builder__preview-controls' },
            wp.element.createElement( 'p', null, __( 'Generate a snapshot preview using the current template content and assignments.', 'astra-builder' ) ),
            metrics ? wp.element.createElement( 'div', { className: 'astra-builder__preview-metrics' },
                wp.element.createElement( 'div', { className: 'astra-builder__preview-metric' + ( lcpExceeded ? ' is-alert' : '' ) },
                    wp.element.createElement( 'strong', null, __( 'LCP', 'astra-builder' ) ),
                    wp.element.createElement( 'span', null, sprintf( __( '%1$s (target %2$s)', 'astra-builder' ), formatMilliseconds( metrics.lcp ), formatMilliseconds( metrics.lcpTarget ) ) )
                ),
                wp.element.createElement( 'div', { className: 'astra-builder__preview-metric' + ( clsExceeded ? ' is-alert' : '' ) },
                    wp.element.createElement( 'strong', null, __( 'CLS', 'astra-builder' ) ),
                    wp.element.createElement( 'span', null, sprintf( __( '%1$s (target %2$s)', 'astra-builder' ), formatClsScore( metrics.cls ), formatClsScore( metrics.clsTarget ) ) )
                )
            ) : null,
            hasMetricAlert ? wp.element.createElement( Notice, { status: 'warning', isDismissible: false }, __( 'Preview performance needs attention. Consider simplifying above-the-fold content or deferring non-critical assets.', 'astra-builder' ) ) : null,
            error ? wp.element.createElement( Notice, { status: 'error', isDismissible: false }, error ) : null,
            previewLink ? wp.element.createElement( 'p', { className: 'astra-builder__preview-meta' },
                __( 'Latest preview ready.', 'astra-builder' ),
                ' ',
                wp.element.createElement( 'a', { href: previewLink, target: '_blank', rel: 'noopener noreferrer' }, __( 'Open preview', 'astra-builder' ) )
            ) : null,
            wp.element.createElement( Button, {
                variant: 'primary',
                onClick: handlePreview,
                isBusy: isLoading,
                disabled: ! postId,
            }, __( 'Preview template', 'astra-builder' ) )
        );
    };

    const TemplateAssignmentsPanel = () =>
        wp.element.createElement( Card, { className: 'astra-builder__template-settings' },
            wp.element.createElement( CardHeader, null, __( 'Template assignments', 'astra-builder' ) ),
            wp.element.createElement( CardBody, null,
                wp.element.createElement( TemplateConditionsPanel, null ),
                wp.element.createElement( TemplatePreviewControls, null )
            )
        );

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

    const ensureBindingAttribute = ( settings = {} ) => {
        const nextSettings = Object.assign( {}, settings );
        nextSettings.attributes = nextSettings.attributes || {};
        if ( ! nextSettings.attributes.astraBinding ) {
            nextSettings.attributes.astraBinding = {
                type: 'object',
                default: {},
            };
        }
        return nextSettings;
    };

    if ( addFilter ) {
        addFilter( 'blocks.registerBlockType', 'astra-builder/responsive-attributes', ensureResponsiveAttribute );
        addFilter( 'blocks.registerBlockType', 'astra-builder/data-binding-attributes', ensureBindingAttribute );
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
            return wp.element.createElement( Fragment, null,
                wp.element.createElement( DesignSystemPanel, null ),
                wp.element.createElement( TemplateAssignmentsPanel, null ),
                wp.element.createElement( AccessibilityAuditPanel, null ),
                wp.element.createElement( PanelBody, {
                    title: __( 'Drag-and-drop layout', 'astra-builder' ),
                    initialOpen: true,
                }, wp.element.createElement( 'p', null, __( 'The layout builder works best on larger screens. Rotate your device or use a desktop to reorder blocks.', 'astra-builder' ) ) )
            );
        }

        return wp.element.createElement( Fragment, null,
            wp.element.createElement( DesignSystemPanel, null ),
            wp.element.createElement( TemplateAssignmentsPanel, null ),
            wp.element.createElement( AccessibilityAuditPanel, null ),
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
