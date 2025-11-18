( function( window ) {
    const { __ } = window.wp.i18n;
    const { createContext, useContext, useMemo, useState, useCallback } = window.wp.element;
    const { useDispatch, useSelect } = window.wp.data;

    const BREAKPOINTS = [
        { id: 'global', label: __( 'Global', 'astra-builder' ), inheritsFrom: null },
        { id: 'desktop', label: __( 'Desktop', 'astra-builder' ), inheritsFrom: 'global' },
        { id: 'tablet', label: __( 'Tablet', 'astra-builder' ), inheritsFrom: 'desktop' },
        { id: 'mobile', label: __( 'Mobile', 'astra-builder' ), inheritsFrom: 'tablet' },
    ];

    const ResponsiveContext = createContext( {
        breakpoints: BREAKPOINTS,
        activeBreakpoint: 'global',
        setActiveBreakpoint: () => {},
        getBreakpoint: () => null,
    } );

    const useBreakpointMap = () =>
        useMemo( () => {
            const map = new Map();
            BREAKPOINTS.forEach( ( bp ) => map.set( bp.id, bp ) );
            return map;
        }, [] );

    const useResponsiveContextValue = () => {
        const [ activeBreakpoint, setActiveBreakpoint ] = useState( 'global' );
        const breakpointMap = useBreakpointMap();

        const getBreakpoint = useCallback( ( id ) => breakpointMap.get( id ), [ breakpointMap ] );
        const getParentId = useCallback( ( id ) => {
            const bp = breakpointMap.get( id );
            return bp ? bp.inheritsFrom : null;
        }, [ breakpointMap ] );

        return useMemo( () => ( {
            breakpoints: BREAKPOINTS,
            breakpointMap,
            activeBreakpoint,
            setActiveBreakpoint,
            getBreakpoint,
            getParentId,
        } ), [ activeBreakpoint, breakpointMap, getBreakpoint, getParentId ] );
    };

    const ResponsiveProvider = ( { children } ) => {
        const value = useResponsiveContextValue();
        return window.wp.element.createElement( ResponsiveContext.Provider, { value }, children );
    };

    const useResponsiveContext = () => useContext( ResponsiveContext );

    const getFromPath = ( source, pathArray ) => {
        if ( ! source ) {
            return undefined;
        }
        return pathArray.reduce( ( acc, key ) => {
            if ( null === acc || undefined === acc ) {
                return undefined;
            }
            return acc[ key ];
        }, source );
    };

    const cloneContainer = ( value ) => {
        if ( Array.isArray( value ) ) {
            return value.slice();
        }
        if ( value && typeof value === 'object' ) {
            return Object.assign( {}, value );
        }
        return {};
    };

    const buildAttributePatch = ( attributes = {}, pathArray = [], value, remove = false ) => {
        if ( ! pathArray.length ) {
            return {};
        }

        const [ rootKey, ...rest ] = pathArray;
        if ( ! rest.length ) {
            return { [ rootKey ]: remove ? undefined : value };
        }

        const rootClone = cloneContainer( attributes[ rootKey ] );
        let cursor = rootClone;

        rest.forEach( ( key, index ) => {
            const isLast = index === rest.length - 1;
            if ( isLast ) {
                if ( remove ) {
                    delete cursor[ key ];
                } else {
                    cursor[ key ] = value;
                }
                return;
            }
            cursor[ key ] = cloneContainer( cursor[ key ] );
            cursor = cursor[ key ];
        } );

        return { [ rootKey ]: rootClone };
    };

    const useResponsiveAttribute = ( clientId, path ) => {
        const pathArray = Array.isArray( path ) ? path : String( path ).split( '.' );
        const pathKey = pathArray.join( '.' );
        const { activeBreakpoint, getParentId } = useResponsiveContext();
        const block = useSelect( ( select ) => {
            if ( ! clientId ) {
                return null;
            }
            return select( 'core/block-editor' ).getBlock( clientId );
        }, [ clientId ] );
        const { updateBlockAttributes } = useDispatch( 'core/block-editor' );

        const attributes = block ? block.attributes || {} : {};
        const responsiveMap = attributes.astraBuilderResponsive || {};
        const globalValue = getFromPath( attributes, pathArray );

        const resolveValue = useCallback( ( breakpointId ) => {
            if ( ! breakpointId || breakpointId === 'global' ) {
                return { source: 'global', value: globalValue };
            }
            const overrides = responsiveMap[ breakpointId ] || {};
            if ( Object.prototype.hasOwnProperty.call( overrides, pathKey ) ) {
                return { source: breakpointId, value: overrides[ pathKey ] };
            }
            const parentId = getParentId( breakpointId ) || 'global';
            return resolveValue( parentId );
        }, [ getParentId, globalValue, pathKey, responsiveMap ] );

        const resolved = resolveValue( activeBreakpoint );
        const isInherited = resolved.source !== activeBreakpoint && activeBreakpoint !== 'global';
        const hasOverride = !! ( responsiveMap[ activeBreakpoint ] && Object.prototype.hasOwnProperty.call( responsiveMap[ activeBreakpoint ], pathKey ) );

        const setValue = useCallback( ( nextValue ) => {
            if ( ! clientId ) {
                return;
            }
            if ( activeBreakpoint === 'global' ) {
                const patch = buildAttributePatch( attributes, pathArray, nextValue );
                updateBlockAttributes( clientId, patch );
                return;
            }
            const nextResponsive = Object.assign( {}, responsiveMap );
            const currentBpValues = Object.assign( {}, nextResponsive[ activeBreakpoint ] || {} );
            currentBpValues[ pathKey ] = nextValue;
            nextResponsive[ activeBreakpoint ] = currentBpValues;
            updateBlockAttributes( clientId, { astraBuilderResponsive: nextResponsive } );
        }, [ activeBreakpoint, attributes, clientId, pathArray, pathKey, responsiveMap, updateBlockAttributes ] );

        const resetValue = useCallback( () => {
            if ( ! clientId ) {
                return;
            }
            if ( activeBreakpoint === 'global' ) {
                const patch = buildAttributePatch( attributes, pathArray, undefined, true );
                updateBlockAttributes( clientId, patch );
                return;
            }
            const nextResponsive = Object.assign( {}, responsiveMap );
            if ( ! nextResponsive[ activeBreakpoint ] ) {
                return;
            }
            const currentBpValues = Object.assign( {}, nextResponsive[ activeBreakpoint ] );
            delete currentBpValues[ pathKey ];
            if ( ! Object.keys( currentBpValues ).length ) {
                delete nextResponsive[ activeBreakpoint ];
            } else {
                nextResponsive[ activeBreakpoint ] = currentBpValues;
            }
            updateBlockAttributes( clientId, { astraBuilderResponsive: nextResponsive } );
        }, [ activeBreakpoint, attributes, clientId, pathArray, pathKey, responsiveMap, updateBlockAttributes ] );

        return {
            value: resolved.value,
            sourceBreakpoint: resolved.source,
            isInherited,
            hasOverride,
            setValue,
            resetValue,
        };
    };

    window.AstraBuilderResponsive = {
        BREAKPOINTS,
        ResponsiveProvider,
        useResponsiveContext,
        useResponsiveAttribute,
    };
} )( window );
