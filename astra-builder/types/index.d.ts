import type { BlockConfiguration, BlockInstance } from '@wordpress/blocks';
import type { ComponentType } from '@wordpress/element';

declare global {
    interface AstraBuilderPreset {
        blockName: string;
        title?: string;
        description?: string;
        attributes?: Record<string, unknown>;
        innerBlocks?: AstraBuilderPreset[];
    }

    interface AstraBuilderWrapperPalette {
        title?: string;
        description?: string;
    }

    interface AstraBuilderWrapperSettings {
        title: string;
        description?: string;
        variant: string;
        icon?: string | ComponentType;
        block: BlockConfiguration<any>;
        palette?: AstraBuilderWrapperPalette;
    }

    type AstraBuilderBlockMigration = ( block: BlockInstance ) => Partial<BlockInstance> | BlockInstance | void | null;

    interface AstraBuilderEvents {
        BEFORE_SAVE: 'astra_builder.beforeSave';
        AFTER_SAVE: 'astra_builder.afterSave';
        TOKEN_CHANGE: 'astra_builder.tokenChange';
    }

    interface AstraBuilderHookAPI {
        events: AstraBuilderEvents;
        addAction( hookName: string, namespace: string, callback: ( ...args: any[] ) => void, priority?: number ): void;
        removeAction( hookName: string, namespace: string ): void;
        addFilter( hookName: string, namespace: string, callback: ( value: any, ...args: any[] ) => any, priority?: number ): void;
        removeFilter( hookName: string, namespace: string ): void;
        doAction( hookName: string, ...args: any[] ): void;
    }

    interface AstraBuilderAPI {
        events: AstraBuilderEvents;
        hooks: AstraBuilderHookAPI;
        registerPreset( slug: string, preset: AstraBuilderPreset ): void;
        registerWrapperBlock( name: string, settings: AstraBuilderWrapperSettings ): void;
        registerInspectorControl( blockName: string | '*', control: ComponentType<any> ): void;
        registerBlockMigration( migration: AstraBuilderBlockMigration ): void;
    }

    interface Window {
        AstraBuilder?: AstraBuilderAPI;
    }
}

export {};
