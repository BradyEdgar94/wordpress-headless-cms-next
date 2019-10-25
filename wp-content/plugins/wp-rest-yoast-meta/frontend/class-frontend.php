<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @since      2018.1.0
 *
 * @package    WP_Rest_Yoast_Meta_Plugin
 * @subpackage WP_Rest_Yoast_Meta_Plugin/Frontend
 */

namespace WP_Rest_Yoast_Meta_Plugin\Frontend;

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    WP_Rest_Yoast_Meta_Plugin
 * @subpackage WP_Rest_Yoast_Meta_Plugin/Frontend
 * @author     Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class Frontend {

	/**
	 * The ID of this plugin.
	 *
	 * @since    2018.1.0
	 * @access   private
	 * @var      string $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    2018.1.0
	 * @access   private
	 * @var      string $version The current version of this plugin.
	 */
	private $version;

	/**
	 * Variable to store the current post object.
	 *
	 * @since   2018.1.0
	 * @access  private
	 * @var     \WP_Post
	 */
	private $post;

	/**
	 * Variable to store the original WP Query object (needed to restore it).
	 *
	 * @since   2018.1.0
	 * @access  private
	 * @var     \WP_Query
	 */
	private $old_wp_query;

	/**
	 * Array containing filters that need to be removed prior to resetting WPSEO_Frontend
	 *
	 * @since   2018.1.1
	 * @access  private
	 * @var     array
	 */
	private $remove_filters;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    2018.1.0
	 *
	 * @param      string $plugin_name The name of the plugin.
	 * @param      string $version The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		$this->remove_filters = [
			'wp_head'    => [
				'front_page_specific_init' => 0,
				'head'                     => 1,
			],
			'wpseo_head' => [
				'debug_mark'         => 2,
				'metadesc'           => 6,
				'robots'             => 10,
				'canonical'          => 20,
				'adjacent_rel_links' => 21,
				'publisher'          => 22,
			],
		];
	}

	/**
	 * Add the Yoast meta data to the WP REST output
	 *
	 * @since   2018.1.0
	 * @access  public
	 *
	 * @param \WP_REST_Response $response The response object.
	 * @param \WP_Post          $post Post object.
	 * @param \WP_REST_Request  $request Request object.
	 *
	 * @return  \WP_REST_Response
	 */
	public function rest_add_yoast( $response, $post, $request ) {

		$yoast_data = $this->get_yoast_data( $post );

		/**
		 * Filter meta array.
		 *
		 * Allows to alter the meta array in order to add or remove meta keys and values.
		 *
		 * @since   2018.1.2
		 *
		 * @param   array $yoast_meta An array of meta key/value pairs.
		 */
		$yoast_meta = apply_filters( 'wp_rest_yoast_meta/filter_yoast_meta', $yoast_data['meta'] );

		$response->data['yoast_meta'] = $yoast_meta;

		/**
		 * Filter json ld array.
		 *
		 * Allows to alter the json ld array.
		 *
		 * @since   2019.4.0
		 *
		 * @param   array $yoast_json_ld An array of json ld data.
		 */
		$yoast_json_ld = apply_filters( 'wp_rest_yoast_meta/filter_yoast_json_ld', $yoast_data['json_ld'] );

		$response->data['yoast_json_ld'] = $yoast_json_ld;

		return $response;
	}

	/**
	 * Update transient with new yoast meta upon post update
	 *
	 * @param int      $post_ID Post ID.
	 * @param \WP_Post $post Post object.
	 */
	public function update_yoast_meta( $post_ID, $post ) {
		if ( $this->should_cache() ) {
			delete_transient( 'yoast_meta_' . $post_ID );
		}
	}

	/**
	 * Delete yoast meta transient upon post deletion.
	 * This function does not look if the plugin should use it's cache at the moment of deletion, it might've been
	 * cached in the past.
	 *
	 * @param int $post_ID Post ID.
	 */
	public function delete_yoast_meta( $post_ID ) {
		delete_transient( 'yoast_meta_' . $post_ID );
	}

	/**
	 * Check if the plugin should use it's own cache based on the activation of other plugins
	 *
	 * @return bool
	 */
	protected function should_cache() {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		return ! is_plugin_active( 'wp-rest-cache/wp-rest-cache.php' );
	}

	/**
	 * Get the cached yoast data
	 *
	 * @param int $post_ID Post ID.
	 *
	 * @return bool|mixed
	 */
	protected function get_cache( $post_ID ) {
		if ( ! $this->should_cache() ) {
			return false;
		}

		$transient_key = 'yoast_meta_' . $post_ID;

		return get_transient( $transient_key );
	}

	/**
	 * Fetch yoast meta and possibly json ld and store in transient if needed
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return array|mixed
	 */
	public function get_yoast_data( $post ) {
		global $wp_query;
		$this->post = $post;
		$yoast_data = $this->get_cache( $post->ID );
		if ( false === $yoast_data || ! isset( $yoast_data['meta'] ) || ! isset( $yoast_data['json_ld'] ) ) {

			remove_action( 'wpseo_head', array( 'WPSEO_Twitter', 'get_instance' ), 40 );

			// Let Yoast generate the html and fetch it.
			$frontend = \WPSEO_Frontend::get_instance();
			ob_start();
			add_action( 'wpseo_head', [ $this, 'setup_postdata_and_wp_query' ], 1 );
			add_action( 'wpseo_opengraph', [ $this, 'setup_postdata_and_wp_query' ], 1 );
			$frontend->head();
			$twitter = new \WPSEO_Twitter();
			$html    = ob_get_clean();

			// Remove filters to prevent double output these are added again on reinitializing WPSEO_Frontend.
			foreach ( $this->remove_filters as $filter => $functions ) {
				foreach ( $functions as $function => $prio ) {
					remove_filter( $filter, [ $frontend, $function ], $prio );
				}
			}
			$frontend->reset();

			$html = html_entity_decode( $html ); // Replaces &hellip; to ...
			$html = preg_replace( '/&(?!#?[a-z0-9]+;)/', '&amp;', $html ); // Replaces & to '&amp;.

			// Parse the xml to create an array of meta items.
			$yoast_data = $this->parse( $html );

			if ( is_array( $yoast_data['meta'] ) && count( $yoast_data['meta'] ) ) {
				$transient_key = 'yoast_meta_' . $post->ID;
				set_transient( $transient_key, $yoast_data, MONTH_IN_SECONDS );
			}

			// Reset postdata & wp_query.
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$wp_query = $this->old_wp_query;
			wp_reset_postdata();
		}

		return $yoast_data;
	}

	/**
	 * Parse HTML to an array of meta key/value pairs using \DOMDocument or simplexml.
	 *
	 * @since 2019.3.0
	 *
	 * @param string $html The HTML as generated by Yoast SEO.
	 *
	 * @return array An array containing all meta key/value pairs.
	 */
	private function parse( $html ) {
		if ( class_exists( 'DOMDocument' ) ) {
			return $this->parse_using_domdocument( $html );
		} else {
			return $this->parse_using_simplexml( $html );
		}
	}

	/**
	 * Parse HTML to an array of meta key/value pairs using \DOMDocument.
	 *
	 * @since 2019.3.0
	 *
	 * @param string $html The HTML as generated by Yoast SEO.
	 *
	 * @return array An array containing all meta key/value pairs.
	 */
	private function parse_using_domdocument( $html ) {
		$dom = new \DOMDocument();

		$internal_errors = libxml_use_internal_errors( true );
		$dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );

		$metas       = $dom->getElementsByTagName( 'meta' );
		$yoast_metas = [];
		foreach ( $metas as $meta ) {
			if ( $meta->hasAttributes() ) {
				$yoast_meta = [];
				foreach ( $meta->attributes as $attr ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$yoast_meta[ $attr->nodeName ] = $attr->nodeValue;
				}
				$yoast_metas[] = $yoast_meta;
			}
		}

		$xpath         = new \DOMXPath( $dom );
		$yoast_json_ld = [];
		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $node ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$yoast_json_ld[] = json_decode( (string) $node->nodeValue, true );
		}
		libxml_use_internal_errors( $internal_errors );

		return [
			'meta'    => $yoast_metas,
			'json_ld' => $yoast_json_ld,
		];
	}

	/**
	 * Parse HTML to an array of meta key/value pairs using simplexml as a fallback if \DOMDocument is unavailable.
	 *
	 * @since 2019.3.0
	 *
	 * @param string $html The HTML as generated by Yoast SEO.
	 *
	 * @return array An array containing all meta key/value pairs.
	 */
	private function parse_using_simplexml( $html ) {
		$xml         = simplexml_load_string( '<yoast>' . $html . '</yoast>' );
		$yoast_metas = [];
		foreach ( $xml->meta as $meta ) {
			$yoast_meta = [];
			$attributes = $meta->attributes();
			foreach ( $attributes as $key => $value ) {
				$yoast_meta[ (string) $key ] = (string) $value;
			}
			$yoast_metas[] = $yoast_meta;
		}

		$yoast_json_ld = [];
		foreach ( $xml->xpath( '//script[@type="application/ld+json"]' ) as $node ) {
			$yoast_json_ld[] = json_decode( (string) $node, true );
		}

		return [
			'meta'    => $yoast_metas,
			'json_ld' => $yoast_json_ld,
		];
	}

	/**
	 * Temporary set up postdata and wp_query to represent the current post (so Yoast will process it correctly)
	 *
	 * @since   2018.1.0
	 * @access  public
	 */
	public function setup_postdata_and_wp_query() {
		global $wp_query;

		$post = $this->post;
		setup_postdata( $post );
		$this->old_wp_query = $wp_query;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query = new \WP_Query(
			[
				'p'         => $post->ID,
				'post_type' => $post->post_type,
			]
		);
	}

	/**
	 * Register an endpoint for retrieving redirects.
	 */
	public function register_redirects_endpoint() {
		register_rest_route(
			'wp-rest-yoast-meta/v1',
			'redirects',
			array(
				'methods'  => 'GET',
				'callback' => [ $this, 'return_redirects' ],
			)
		);
	}

	/**
	 * Retrieve an array of all redirects as set in Yoast SEO Premium.
	 *
	 * @return array An array containing all redirects.
	 */
	public function return_redirects() {
		$manager   = new \WPSEO_Redirect_Manager();
		$redirects = $manager->get_all_redirects();

		$data = [];
		foreach ( $redirects as $redirect ) {
			$data[] = sprintf( '/%s/ /%s/ %d', $redirect->get_origin(), $redirect->get_target(), $redirect->get_type() );
		}

		return $data;
	}

}
