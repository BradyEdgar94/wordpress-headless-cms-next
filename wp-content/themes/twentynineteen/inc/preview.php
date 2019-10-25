<?php
/**
 * Customize the preview button in the WordPress admin to point to the headless client.
 *
 * @param  str $link The WordPress preview link.
 * @return str The headless WordPress preview link.
 */
function set_headless_preview_link( $link ) {
	if ($_SERVER['ENV'] == 'production') {
		return 'https://[PLEASE_UPDATE_LINK]'
			. '_preview/'
			. get_the_ID() . '/'
			. wp_create_nonce( 'wp_rest' );
	}	else if ($_SERVER['ENV'] == 'development') {
		return 'https://[PLEASE_UPDATE_LINK]'
			. '_preview/'
			. get_the_ID() . '/'
			. wp_create_nonce( 'wp_rest' );
	} else {
		return 'http://localhost:3000/'
			. '_preview/'
			. get_the_ID() . '/'
			. wp_create_nonce( 'wp_rest' );
	}
}

add_filter( 'preview_post_link', 'set_headless_preview_link' );



/**
* Register custom REST API routes.
*/
add_action( 'rest_api_init', function () {

	register_rest_route('__/v1', '/post/preview', array(
		'methods'  => 'GET',
		'callback' => 'rest_get_post_preview',
		'args' => array(
			'id' => array(
				'validate_callback' => function($param, $request, $key) {
					return ( is_numeric( $param ) );
				},
				'required' => true,
				'description' => 'Valid WordPress post ID',
			),
		),
		'permission_callback' => function() {
			return current_user_can( 'edit_posts' );
		}
	) );
});


function get_excerpt_by_id($post_id){
  $the_excerpt = get_post_field('post_excerpt', $post_id);
  $the_excerpt = '<p>' . $the_excerpt . '</p>';

  return $the_excerpt;
}


/**
 * Respond to a REST API request to get a post's latest revision.
 * * Requires a valid _wpnonce on the query string
 * * User must have 'edit_posts' rights
 * * Will return draft revisions of even published posts
 *
 * @param  WP_REST_Request $request
 * @return WP_REST_Response
 */
function rest_get_post_preview(WP_REST_Request $request) {
	$post_id = $request->get_param('id');

	// Revisions are drafts so here we remove the default 'publish' status
	remove_action('pre_get_posts', 'set_default_status_to_publish');

	// $revisions = wp_get_post_revisions( $post_id, array( 'check_enabled' => false ) )
	// $last_revision = reset($revisions);
	// $rev_post = wp_get_post_revision($last_revision->ID);
	//
	// return new WP_REST_Response($rev_post);

	if ( $revisions = wp_get_post_revisions( $post_id, array( 'check_enabled' => false ) )) {
		$last_revision = reset($revisions);
		$rev_post = wp_get_post_revision($last_revision->ID);
		$controller = new WP_REST_Posts_Controller('post');
		$data = $controller->prepare_item_for_response( $rev_post, $request );



	} elseif ( $post = get_post( $post_id ) ) { // There are no revisions, just return the saved parent post
		$controller = new WP_REST_Posts_Controller('post');
		$data = $controller->prepare_item_for_response( $post, $request );
	} else {
		return new WP_Error( 'rest_get_post_preview', 'Post ' . $post_id . ' does not exist',
			array( 'status' => 404 ) );
	}

	$response = $controller->prepare_response_for_collection( $data );
	// get template since wp_get_post_revision doesn't return the template :(

	// merge post data with revised content content beacuse wordpress only returns the changed content
	$parentId = str_replace("-autosave-v1", "", $response['slug']);
	$response['template'] = get_page_template_slug($parentId);
	$response['excerpt'] =  get_excerpt_by_id($parentId);

	return new WP_REST_Response($response);
};
