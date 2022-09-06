<?php
/**
 * Overrides Core's wp-includes/rest-api.php and registers the new endpoint for WP 6.0.
 *
 * @package gutenberg
 */

/**
 * Update `wp_template` and `wp_template-part` post types to use
 * Gutenberg's REST controller.
 *
 * @param array  $args Array of arguments for registering a post type.
 * @param string $post_type Post type key.
 */
function gutenberg_update_templates_template_parts_rest_controller( $args, $post_type ) {
	if ( in_array( $post_type, array( 'wp_template', 'wp_template-part' ), true ) ) {
		$args['rest_controller_class'] = 'Gutenberg_REST_Templates_Controller';
	}
	return $args;
}
add_filter( 'register_post_type_args', 'gutenberg_update_templates_template_parts_rest_controller', 10, 2 );


/**
 * Add the post type's `icon`(menu_icon) in the response.
 * When we backport this change we will need to add the
 * `icon` to WP_REST_Post_Types_Controller schema.
 *
 * @param WP_REST_Response $response  The response object.
 * @param WP_Post_Type     $post_type The original post type object.
 */
function gutenberg_update_post_types_rest_response( $response, $post_type ) {
	$response->data['icon'] = $post_type->menu_icon;
	return $response;
}
add_filter( 'rest_prepare_post_type', 'gutenberg_update_post_types_rest_response', 10, 2 );

/**
 * Registers the block patterns REST API routes.
 */
function gutenberg_register_gutenberg_rest_block_patterns() {
	$block_patterns = new Gutenberg_REST_Block_Patterns_Controller();
	$block_patterns->register_routes();
}
add_action( 'rest_api_init', 'gutenberg_register_gutenberg_rest_block_patterns', 100 );

/**
 * Exposes the site logo URL through the WordPress REST API.
 *
 * This is used for fetching this information when user has no rights
 * to update settings.
 *
 * Note: Backports into wp-includes/rest-api/class-wp-rest-server.php file.
 *
 * @param WP_REST_Response $response REST API response.
 * @return WP_REST_Response $response REST API response.
 */
function gutenberg_add_site_icon_url_to_index( WP_REST_Response $response ) {
	$response->data['site_icon_url'] = get_site_icon_url();

	return $response;
}
add_action( 'rest_index', 'gutenberg_add_site_icon_url_to_index' );

/**
 * Returns the has_archive post type field.
 *
 * @param array  $type       The response data.
 * @param string $field_name The field name. The function handles field has_archive.
 */
function gutenberg_get_post_type_has_archive_field( $type, $field_name ) {
	if ( ! empty( $type ) && ! empty( $type['slug'] ) && 'has_archive' === $field_name ) {
		$post_type_object = get_post_type_object( $type['slug'] );
		return $post_type_object->has_archive;
	}
}

/**
 * Registers the has_archive post type REST API field.
 */
function gutenberg_register_has_archive_on_post_types_endpoint() {
	register_rest_field(
		'type',
		'has_archive',
		array(
			'get_callback' => 'gutenberg_get_post_type_has_archive_field',
			'schema'       => array(
				'description' => __( 'If the value is a string, the value will be used as the archive slug. If the value is false the post type has no archive.', 'gutenberg' ),
				'type'        => array( 'string', 'boolean' ),
				'context'     => array( 'view', 'edit' ),
			),
		)
	);
}
add_action( 'rest_api_init', 'gutenberg_register_has_archive_on_post_types_endpoint' );

/**
 * Use custom REST API Controller for Navigation Posts
 *
 * @param array $args the post type arguments.
 * @return array the filtered post type arguments with the new REST Controller set.
 */
function gutenberg_update_navigation_rest_controller( $args, $post_type ) {
	if ( in_array( $post_type, array( 'wp_navigation' ), true ) ) {
		// Original set in
		// https://github.com/WordPress/wordpress-develop/blob/6cbed78c94b9d8c6a9b4c8b472b88ee0cd56528c/src/wp-includes/post.php#L528.
		$args['rest_controller_class'] = 'Gutenberg_REST_Navigation_Controller';
	}
	return $args;
}
add_filter( 'register_post_type_args', 'gutenberg_update_navigation_rest_controller', 10, 2 );

function gutenberg_transform_slug_to_post_id( $response, $handler, WP_REST_Request $request ) {

	$route = rest_get_route_for_post_type_items( 'wp_navigation' );

	// Ignore non-Navigation REST API requests.
	if ( ! str_starts_with( $request->get_route(), $route ) ) {
		return $response;
	}

	// Get the slug from the request **URL** (not the request body).
	// PUT requests will have a param of `slug` in both the URL and (potentially)
	// in the body. In all cases only the slug in the URL should be mapped to a
	// postId in order that the correct post to be updated can be retrived.
	// The `slug` within the body should be preserved "as is".
	$slug = isset( $request->get_url_params()['slug'] ) ? $request->get_url_params()['slug'] : null;

	// If no slug provided assume ID and continue as normal.
	if ( empty( $slug ) ) {
		return $response;
	}

	$args = array(
		'name'                   => $slug, // query by slug
		'post_type'              => 'wp_navigation',
		'nopaging'               => true,
		'posts_per_page'         => '1',
		'update_post_term_cache' => false,
		'no_found_rows'          => true,
	);

	// Query for the Navigation Post by slug (post_name).
	$query = new WP_Query( $args );

	if ( empty( $query->posts ) ) {
		return new WP_Error(
			'rest_post_not_found',
			__( 'No navigation found.' ),
			array( 'status' => 404 )
		);
	}

	// Set the post ID based on the slug.
	$request['id'] = $query->posts[0]->ID;

	return $response;
}
add_filter( 'rest_request_before_callbacks', 'gutenberg_transform_slug_to_post_id', 10, 3 );

function gutenberg_update_navigation_rest_route_for_post( $route, WP_Post $post ) {

	if ( $post->post_type !== 'wp_navigation' ) {
		return $route;
	}

	$post_type_route = rest_get_route_for_post_type_items( $post->post_type );

	if ( ! $post_type_route ) {
		return '';
	}

	// Replace Post ID in route with Post "Slug" (post_name).
	return sprintf( '%s/%s', $post_type_route, $post->post_name );
}


add_filter( 'rest_route_for_post', 'gutenberg_update_navigation_rest_route_for_post', 10, 2 );
