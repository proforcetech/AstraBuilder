( function( window ) {
    const ROW_HEIGHT = 72;

    const createLayoutMap = ( blocks = [], parentId = null, depth = 0, orderPath = [] ) => {
        const nodes = [];

        blocks.forEach( ( block, index ) => {
            const childPath = orderPath.concat( index );
            const innerBlocks = block.innerBlocks || [];
            nodes.push( {
                id: block.clientId,
                blockName: block.name,
                block,
                parentId,
                depth,
                index,
                orderPath: childPath,
                hasInnerBlocks: innerBlocks.length > 0,
            } );

            if ( innerBlocks.length ) {
                nodes.push( ...createLayoutMap( innerBlocks, block.clientId, depth + 1, childPath ) );
            }
        } );

        return nodes;
    };

    const computeGridGeometry = ( nodes = [] ) => {
        if ( ! nodes.length ) {
            return { nodes: [], columns: 1, rowHeight: ROW_HEIGHT };
        }

        const columns = Math.max( 1, nodes.reduce( ( acc, node ) => Math.max( acc, node.depth ), 0 ) + 1 );

        const geometryNodes = nodes.map( ( node, index ) => {
            const row = index + 1;
            const column = node.depth + 1;
            const columnSpan = Math.max( 1, columns - node.depth );

            return Object.assign( {}, node, {
                row,
                column,
                columnSpan,
                y: row * ROW_HEIGHT,
                height: ROW_HEIGHT,
            } );
        } );

        return {
            nodes: geometryNodes,
            columns,
            rowHeight: ROW_HEIGHT,
        };
    };

    const computeSnapLines = ( geometry ) => {
        if ( ! geometry || ! geometry.nodes ) {
            return [];
        }

        const lines = [];
        geometry.nodes.forEach( ( node ) => {
            lines.push( {
                key: `${ node.id }-h`,
                orientation: 'horizontal',
                position: ( node.row - 1 ) * ROW_HEIGHT,
            } );
            lines.push( {
                key: `${ node.id }-v`,
                orientation: 'vertical',
                column: node.column,
            } );
        } );

        return lines;
    };

    const computeSpacingIndicators = ( geometry ) => {
        if ( ! geometry || ! geometry.nodes ) {
            return [];
        }

        const indicators = [];
        for ( let i = 0; i < geometry.nodes.length - 1; i += 1 ) {
            const current = geometry.nodes[ i ];
            const next = geometry.nodes[ i + 1 ];
            const gap = Math.max( 0, next.row - current.row - 1 );
            indicators.push( {
                key: `${ current.id }-gap-${ next.id }`,
                top: ( current.row * ROW_HEIGHT ) - ( ROW_HEIGHT / 4 ),
                label: gap ? `${ gap } row gap` : 'Stacked',
            } );
        }

        return indicators;
    };

    const useKeyboardControls = ( config = {} ) => {
        const { useEffect } = window.wp.element;

        useEffect( () => {
            const handler = ( event ) => {
                if ( ! config.selectedClientIds || ! config.selectedClientIds.length ) {
                    return;
                }

                const isModifier = event.metaKey || event.ctrlKey;
                const lowercaseKey = event.key.toLowerCase();

                if ( lowercaseKey === 'arrowup' ) {
                    event.preventDefault();
                    config.onMove && config.onMove( -1 );
                    return;
                }

                if ( lowercaseKey === 'arrowdown' ) {
                    event.preventDefault();
                    config.onMove && config.onMove( 1 );
                    return;
                }

                if ( isModifier && lowercaseKey === 'd' ) {
                    event.preventDefault();
                    config.onDuplicate && config.onDuplicate();
                    return;
                }

                if ( isModifier && lowercaseKey === 'g' ) {
                    event.preventDefault();
                    config.onGroup && config.onGroup();
                    return;
                }

                if ( lowercaseKey === 'delete' || lowercaseKey === 'backspace' ) {
                    event.preventDefault();
                    config.onDelete && config.onDelete();
                }
            };

            window.addEventListener( 'keydown', handler );
            return () => window.removeEventListener( 'keydown', handler );
        }, [
            config.selectedClientIds && config.selectedClientIds.join( ':' ),
            config.onDuplicate,
            config.onGroup,
            config.onDelete,
            config.onMove,
        ] );
    };

    window.AstraBuilderCanvas = {
        ROW_HEIGHT,
        createLayoutMap,
        computeGridGeometry,
        computeSnapLines,
        computeSpacingIndicators,
        useKeyboardControls,
    };
} )( window );
