<?php
/**
 * Plugin Name: Relevanssi to WP API
 * Description: Creates a custom endpoint in WP REST API for making relevanssi search queries
 * Author: Renan Batel
 * Author URI: https://github.com/renanbatel
 * Version: 1.2.0
 */

class RelevanssiToWPAPI {

  const API_NAMESPACE = "relevanssi/v1";

  /**
	 * Constructor
	 *
	 * @author Renan Batel <renanbatel@gmail.com>
	 *
	 * @since 1.0.0
	 */
  function __construct() {
    add_action( "rest_api_init", [ $this, "register" ] );
    register_activation_hook( __FILE__, [ $this, "activate" ] );
  }

  /**
	 * Registers the custom rest api endpoints
	 *
	 * @author Renan Batel <renanbatel@gmail.com>
   * 
   * @return void
	 *
	 * @since 1.0.0
	 */
  public function register() {

    register_rest_route( self::API_NAMESPACE, "search", [
      "methods"  => WP_REST_Server::READABLE,
      "callback" => [ $this, "searchCallback" ],
    ] );
  }

  /**
	 * Creates and returns WP_REST_Response with body and status code
	 *
	 * @author Renan Batel <renanbatel@gmail.com>
   * 
   * @param array $body   The response data
   * @param int   $status The response status code
   * 
   * @return object $response The WP_REST_Response Object
	 *
	 * @since 1.0.0
	 */
  public function response( $body, $status = 200 ) {

    return new WP_REST_Response( $body, $status );
  }

  /**
   * Creates next or previous request urls
   * 
   * @author Renan Batel <renanbatel@gmail.com>
   * 
   * @param array $arguments The query arguments
   * @param array $parameters The request parameters
   * @param string $type If it's next or previous link
   * 
   * @return string The request url
   * 
   * @since 1.1.0
   */
  public function getSearchRequestUrl( $arguments, $parameters, $type = "next" ) {
    $baseUrl = get_rest_url( null, self::API_NAMESPACE . "/search" );
    $query   = [
      "posts_per_page" => $arguments[ "posts_per_page" ],
      "paged"          => $type === "previous" ? intval( $arguments[ "paged" ] ) - 1 : intval( $arguments[ "paged" ] ) + 1,
      "s"              => $arguments[ "s" ],
    ];

    if ( isset( $parameters[ "post_type" ] ) && $parameters[ "post_type" ] ) {
      $query[ "post_type" ] = $parameters[ "post_type" ];
    }
    if ( isset( $parameters[ "category" ] ) && $parameters[ "category" ] ) {
      $query[ "category" ] = $parameters[ "category" ];
    }
    if ( isset( $parameters[ "fields" ] ) && $parameters[ "fields" ] ) {
      $query[ "fields" ] = $parameters[ "fields" ];
    }

    $queryString = build_query( $query );

    return "{$baseUrl}?{$queryString}";
  }

  /**
	 * Prepares the argument value for the query
	 *
	 * @author Renan Batel <renanbatel@gmail.com>
   * 
   * @param mixed  $argument The argument value
   * @param string $key      The argument key
   * 
   * @return mixed The argument value after manipulation
	 *
	 * @since 1.0.0
	 */
  public function prepareArgument( $argument, $key ) {

    switch( $key ) {
      case "post_type":
      case "fields":
        
        return wp_parse_list( $argument );
      case "posts_per_page":
      case "paged":
        
        return intval( $argument );
      default:

        return $argument;
    }
  }

  /**
	 * Returns the query parameter value if it was given or the default parameter value
	 *
	 * @author Renan Batel <renanbatel@gmail.com>
   * 
   * @param array  $parameters The request parameters
   * @param array  $defaults   The defaults query parameters values
   * @param string $key        The parameter key
   * 
   * @return mixed The argument value
	 *
	 * @since 1.0.0
	 */
  public function filterArgument( $parameters, $defaults, $key ) {

    return isset( $parameters[ $key ] ) && $parameters[ $key ] 
      ? $this->prepareArgument( $parameters[ $key ], $key )
      : $defaults[ $key ];
  }

  /**
   * Get post taxonomies
   * 
   * @author Renan Batel <renanbatel@gmail.com>
   * 
   * @param stdClass $post The post
   * 
   * @return array The post taxonomies
   * 
   * @since 1.2.0
   */

  public function getPostTaxonomies( $post ) {

    return array_reduce( get_post_taxonomies( $post ), function( $carry, $taxonomy ) use ( $post ) {
      $terms = wp_get_post_terms( $post->ID, $taxonomy );
      $carry[ $taxonomy ] = array_map( function( $term ) use ( $taxonomy ) {
        if ( $term->parent != 0 ) {
          $term->parent = get_term( $term->parent, $taxonomy );
        }

        return $term;
      }, $terms );

      return $carry;
    }, [] );
  }

  /**
	 * Prepare the post for response
	 *
	 * @author Renan Batel <renanbatel@gmail.com>
   * 
   * @param object $post       The post object
   * @param array  $parameters The request parameters
   * 
   * @return array The prepared post
	 *
	 * @since 1.0.0
	 */
  public function preparePostForResponse( $post, $parameters ) {
    $fieldKeys = [
      "id"        => "ID",
      "title"     => "post_title",
      "slug"      => "post_name",
      "content"   => "post_content",
      "excerpt"   => "post_excerpt",
      "date"      => "post_date",
      "modified"  => "post_modified",
      "type"      => "post_type",
      "relevance" => "relevance_score",
    ];
    $default = [
      "id",
      "title",
      "slug",
      "excerpt",
      "date",
      "modified",
      "taxonomies",
    ];
    $fields = isset( $parameters[ "fields" ] ) && $parameters[ "fields" ]
      ? $this->prepareArgument( $parameters[ "fields" ], "fields" )
      : $default;

    return array_reduce( $fields, function( $carry, $field ) use ( $post, $fieldKeys ) {
      if ( $field === "taxonomies" ) {
        $carry[ $field ] = $this->getPostTaxonomies( $post );
      } else {
        if ( isset( $fieldKeys[ $field ] ) ) {
          $key = $fieldKeys[ $field ];
  
          $carry[ $field ] = $post->{ $key };
        }
      }

      return $carry;
    }, [] );
  }

  /**
	 * Builds the search query arguments
	 *
	 * @author Renan Batel <renanbatel@gmail.com>
   * 
   * @param array $parameters The request parameters
   * 
   * @return array The search query arguments
	 *
	 * @since 1.0.0
	 */
  public function searchBuildArguments( $parameters ) {
    $defaults = [
      "posts_per_page" => 10,
      "paged"          => 1,
      "post_type"      => "any",
      "s"              => null,
    ];
    $arguments = [
      "posts_per_page" => $this->filterArgument( $parameters, $defaults, "posts_per_page" ),
      "paged"          => $this->filterArgument( $parameters, $defaults, "paged" ),
      "post_type"      => $this->filterArgument( $parameters, $defaults, "post_type" ),
      "s"              => $this->filterArgument( $parameters, $defaults, "s" ),
    ];
    
    // optional parameters
    if ( isset( $parameters[ "category" ] ) && $parameters[ "category" ] ) {
      $arguments[ "tax_query" ] = [
        [
          "taxonomy" => "category",
          "field"    => "slug",
          "terms"    => $parameters[ "category" ]
        ]
      ];

      if ( isset( $parameters[ "taxonomy" ] ) && $parameters[ "taxonomy" ] ) {
        $arguments[ "tax_query" ][ 0 ][ "taxonomy" ] = $parameters[ "taxonomy" ];
      }
    }

    return $arguments;
  }

  /**
	 * The search custom rest api endpoint callback
	 *
	 * @author Renan Batel <renanbatel@gmail.com>
   * 
   * @param object $request The request
   * 
   * @return object The request response
	 *
	 * @since 1.0.0
	 */
  public function searchCallback( WP_REST_Request $request ) {
    $parameters = $request->get_query_params();
    $arguments  = $this->searchBuildArguments( $parameters );

    if ( $arguments[ "s" ] ) {
      $wpQuery = new WP_Query( $arguments );

      relevanssi_do_query( $wpQuery );

      if ( !empty( $wpQuery->posts ) ) {
        $posts = array_map( function( $post ) use ( $parameters ) {

          return $this->preparePostForResponse( $post, $parameters );
        }, $wpQuery->posts );
        $response = [
          "success" => true,
          "results" => $posts,
          "meta"    => [
            "filters"      => [],
            "total"        => $wpQuery->found_posts,
            "pages"        => $wpQuery->max_num_pages,
            "current_page" => $arguments[ "paged" ],
            "per_page"     => $arguments[ "posts_per_page" ],
            "s"            => $arguments[ "s" ],
          ]
        ];

        if ( isset( $arguments[ "tax_query" ] ) ) {
          $response[ "meta" ][ "filters" ][ "category" ] = $arguments[ "tax_query" ][ 0 ][ "terms" ];
          $response[ "meta" ][ "filters" ][ "taxonomy" ] = $arguments[ "tax_query" ][ 0 ][ "taxonomy" ];
        }
        if ( intval( $arguments[ "paged" ] ) < $wpQuery->max_num_pages ) {
          $response[ "meta" ][ "next" ] = $this->getSearchRequestUrl( $arguments, $parameters, "next" );
        }
        if ( intval( $arguments[ "paged" ] ) > 1 ) {
          $response[ "meta" ][ "previous" ] = $this->getSearchRequestUrl( $arguments, $parameters, "previous" );
        }
  
        return $this->response( $response );
      } else {
        
        return $this->response( [
          "error"   => true,
          "message" => "Nothing found"
        ], 404 );
      }
    } else {

      return $this->response( [
        "error"   => true,
        "message" => "Empty search query",
      ], 400 );
    }
  }

  /**
	 * The plugin activation routines
	 *
	 * @author Renan Batel <renanbatel@gmail.com>
   * 
   * @return void
	 *
	 * @since 1.0.0
	 */
  public function activate() {
    if ( !is_plugin_active( "relevanssi/relevanssi.php" ) &&  !is_plugin_active( "relevanssi-premium/relevanssi.php" ) && current_user_can( "activate_plugins" ) ) {
      $pluginsUrl = admin_url( "plugins.php" );
      
      wp_die( "
        Sorry, but this plugin requires the Relevanssi Plugin to be installed and active. 
        <br><a href=\"{$pluginsUrl}\">&laquo; Return to Plugins</a>
      " );
    }
  }

}

$RelevanssiToWPAPI = new RelevanssiToWPAPI();
