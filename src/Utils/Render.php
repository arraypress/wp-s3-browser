<?php

namespace ArrayPress\S3\Utils;

use Elementify\Element;
use Elementify\Elements\Select;
use Elementify\Create;

class Render {

    /**
     * Render bucket select field using Elementify
     *
     * @param array $args Field arguments
     *
     * @return void
     */
    public static function select( array $args = [] ): void {
        $defaults = [
                'name'         => '',
                'id'           => '',
                'current'      => '',
                'buckets'      => [],
                'show_refresh' => true,
                'show_empty'   => true,
                'empty_text'   => __( '— Select a bucket —', 'arraypress' ),
                'class'        => '',
                'desc'         => ''
        ];

        $args = wp_parse_args( $args, $defaults );

        // Build select options
        $options = [];
        if ( $args['show_empty'] ) {
            $options[''] = $args['empty_text'];
        }

        foreach ( $args['buckets'] as $bucket ) {
            $options[ $bucket ] = $bucket;
        }

        // Create select element
        $select = new Select(
                $args['name'],
                $options,
                $args['current'],
                [
                        'id'    => $args['id'],
                        'class' => trim( 's3-bucket-select ' . $args['class'] )
                ]
        );

        // Create wrapper div
        $wrapper = Create::div()
                         ->add_class( 's3-bucket-select-wrapper' );

        // Add select
        $wrapper->add_child( $select );

        // Add refresh button if needed
        if ( $args['show_refresh'] ) {
            $button = Create::button(
                    Create::span()->add_class( 'dashicons dashicons-update' )->render() .
                    ' ' . __( 'Refresh', 'arraypress' )
            )
                            ->add_class( 'button s3-refresh-buckets' )
                            ->set_attribute( 'type', 'button' )
                            ->set_data( 'select-id', $args['id'] );

            $wrapper->add_child( $button );

            // Add status span
            $status = Create::span()
                            ->add_class( 's3-bucket-status' )
                            ->set_attribute( 'style', 'margin-left: 10px;' );

            $wrapper->add_child( $status );
        }

        // Output the wrapper
        $wrapper->output();

        // Add description if provided
        if ( $args['desc'] ) {
            Create::p( $args['desc'] )
                  ->add_class( 'description' )
                  ->output();
        }
    }

    /**
     * Create a bucket select element (returns Elementify object instead of outputting)
     *
     * @param array $args Field arguments
     *
     * @return Element
     */
    public static function create( array $args = [] ): Element {
        $defaults = [
                'name'         => '',
                'id'           => '',
                'current'      => '',
                'buckets'      => [],
                'show_refresh' => true,
                'show_empty'   => true,
                'empty_text'   => __( '— Select a bucket —', 'arraypress' ),
                'class'        => ''
        ];

        $args = wp_parse_args( $args, $defaults );

        // Build options array
        $options = [];
        if ( $args['show_empty'] ) {
            $options[''] = $args['empty_text'];
        }

        foreach ( $args['buckets'] as $bucket ) {
            $options[ $bucket ] = $bucket;
        }

        // Return the select element
        return new Select(
                $args['name'],
                $options,
                $args['current'],
                [
                        'id'    => $args['id'],
                        'class' => trim( 's3-bucket-select ' . $args['class'] )
                ]
        );
    }

}