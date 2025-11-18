<?php
/**
 * Data binding service that hydrates block attributes from WordPress data.
 *
 * @package AstraBuilder\Services
 */

class Astra_Builder_Data_Binding_Service {
    const ATTRIBUTE_KEY = 'astraBinding';

    /**
     * Boot service hooks.
     */
    public function register() {
        add_filter( 'render_block_data', array( $this, 'hydrate_block_attributes' ), 10, 2 );
    }

    /**
     * Provide configuration for the editor panel.
     *
     * @return array
     */
    public function get_editor_config() {
        return array(
            'attributeKey' => self::ATTRIBUTE_KEY,
            'sources'      => array(
                'wp_field' => array(
                    'label'   => __( 'WordPress fields', 'astra-builder' ),
                    'options' => $this->get_wp_field_options(),
                ),
                'meta'     => array(
                    'label'   => __( 'Custom fields', 'astra-builder' ),
                    'options' => $this->get_meta_key_options(),
                ),
                'acf'      => array(
                    'label'   => __( 'ACF / MetaBox / Pods', 'astra-builder' ),
                    'options' => $this->get_meta_key_options(),
                ),
                'query'    => array(
                    'label'   => __( 'Query vars', 'astra-builder' ),
                    'options' => $this->get_query_var_options(),
                ),
            ),
        );
    }

    /**
     * Extend parsed block attributes prior to rendering.
     *
     * @param array $parsed_block Parsed block.
     * @param array $source_block Original block (unused).
     *
     * @return array
     */
    public function hydrate_block_attributes( $parsed_block, $source_block ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        if ( empty( $parsed_block['attrs'][ self::ATTRIBUTE_KEY ] ) ) {
            return $parsed_block;
        }

        $binding = $parsed_block['attrs'][ self::ATTRIBUTE_KEY ];
        if ( empty( $binding['attribute'] ) ) {
            return $parsed_block;
        }

        $value = $this->resolve_binding_value( $binding );

        if ( null === $value ) {
            return $parsed_block;
        }

        $attribute_key = sanitize_key( $binding['attribute'] );
        if ( empty( $attribute_key ) ) {
            return $parsed_block;
        }

        $parsed_block['attrs'][ $attribute_key ] = $value;

        return $parsed_block;
    }

    /**
     * Resolve the requested binding value.
     *
     * @param array $binding Binding configuration.
     *
     * @return string|null
     */
    protected function resolve_binding_value( $binding ) {
        $source = isset( $binding['source'] ) ? sanitize_key( $binding['source'] ) : 'wp_field';
        $key    = isset( $binding['key'] ) ? $binding['key'] : '';

        switch ( $source ) {
            case 'acf':
            case 'metabox':
            case 'pods':
            case 'meta':
                return $this->get_meta_value( $key );
            case 'query':
                return $this->get_query_value( $key );
            case 'wp_field':
            default:
                return $this->get_post_field_value( $key );
        }
    }

    /**
     * Fetch a core post field.
     *
     * @param string $key Field key.
     *
     * @return string|null
     */
    protected function get_post_field_value( $key ) {
        global $post;

        if ( ! $post ) {
            return null;
        }

        $allowed = array( 'post_title', 'post_excerpt', 'post_content', 'post_date', 'post_author' );
        $key     = in_array( $key, $allowed, true ) ? $key : 'post_title';

        $value = get_post_field( $key, $post );

        if ( 'post_author' === $key ) {
            $author = get_userdata( (int) $value );
            return $author ? $author->display_name : null;
        }

        return is_string( $value ) ? $value : null;
    }

    /**
     * Fetch a meta value from the current post.
     *
     * @param string $key Meta key.
     *
     * @return string|null
     */
    protected function get_meta_value( $key ) {
        global $post;

        if ( ! $post || empty( $key ) ) {
            return null;
        }

        $value = get_post_meta( $post->ID, sanitize_key( $key ), true );
        if ( is_array( $value ) ) {
            $value = implode( ', ', $value );
        }

        return $value;
    }

    /**
     * Fetch a value from the main query vars or request context.
     *
     * @param string $key Query descriptor.
     *
     * @return string|null
     */
    protected function get_query_value( $key ) {
        if ( empty( $key ) ) {
            return null;
        }

        if ( 0 === strpos( $key, 'query_var:' ) ) {
            $var = substr( $key, 10 );
            $val = get_query_var( sanitize_key( $var ) );
            return is_scalar( $val ) ? (string) $val : null;
        }

        if ( 0 === strpos( $key, 'option:' ) ) {
            $option = substr( $key, 7 );
            $val    = get_option( sanitize_key( $option ) );
            return is_scalar( $val ) ? (string) $val : null;
        }

        return null;
    }

    /**
     * Options for WP field bindings.
     *
     * @return array
     */
    protected function get_wp_field_options() {
        return array(
            array( 'value' => 'post_title', 'label' => __( 'Post title', 'astra-builder' ) ),
            array( 'value' => 'post_excerpt', 'label' => __( 'Post excerpt', 'astra-builder' ) ),
            array( 'value' => 'post_content', 'label' => __( 'Post content', 'astra-builder' ) ),
            array( 'value' => 'post_date', 'label' => __( 'Publish date', 'astra-builder' ) ),
            array( 'value' => 'post_author', 'label' => __( 'Author name', 'astra-builder' ) ),
        );
    }

    /**
     * Pull a list of known meta keys.
     *
     * @return array
     */
    protected function get_meta_key_options() {
        global $wpdb;

        $keys = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key NOT LIKE '\\_%' LIMIT 25" );

        $keys = array_filter( array_map( 'sanitize_key', (array) $keys ) );

        return array_map(
            function( $key ) {
                return array(
                    'value' => $key,
                    'label' => $key,
                );
            },
            $keys
        );
    }

    /**
     * Provide common query vars available for bindings.
     *
     * @return array
     */
    protected function get_query_var_options() {
        $vars = array(
            array( 'value' => 'query_var:category_name', 'label' => __( 'Category slug', 'astra-builder' ) ),
            array( 'value' => 'query_var:tag', 'label' => __( 'Tag slug', 'astra-builder' ) ),
            array( 'value' => 'query_var:s', 'label' => __( 'Search term', 'astra-builder' ) ),
            array( 'value' => 'option:blogdescription', 'label' => __( 'Site description', 'astra-builder' ) ),
        );

        return $vars;
    }
}
