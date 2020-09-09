<?php
/**
 * Plugin Name:     WP Gatsby Markdown Exporter
 * Plugin URI:      https://github.com/tinacms/wp-gatsby-markdown-exporter
 * Description:     Export WordPress content to Markdown for GatsbyJS
 * Author:          TinaCMS
 * Author URI:      https://www.tinacms.org
 * Text Domain:     gatsby-markdown-exporter
 * Version:         0.3.3
 *
 * @package         GatsbyMarkdownExporter
 */

/**
 * Main plugin file
 */

if ( file_exists( dirname( __FILE__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __FILE__ ) . '/vendor/autoload.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	$command_info = array(
		'shortdesc' => 'Export WordPress content to Markdown for GatsbyJS.',
		'synopsis'  => array(
			array(
				'type'        => 'assoc',
				'name'        => 'directory',
				'description' => 'The export output directory. Defaults to a random temp directory.',
				'optional'    => true,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'post_types',
				'description' => 'List of post types to include in the export.',
				'optional'    => true,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'post_status',
				'description' => 'Post status to include in the export, see see: https://developer.wordpress.org/reference/classes/wp_query/#status-parameters .',
				'optional'    => true,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'post_date_format',
				'description' => 'Set the format for post publish date, see: https://www.php.net/manual/en/function.date.php .',
				'optional'    => true,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'excluded_front_matter',
				'description' => 'List of front matter fields to exclude from the exported Markdown.',
				'optional'    => true,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'fields_to_markdown',
				'description' => 'List of front matter fields that should be converted to markdown.',
				'optional'    => true,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'upload_dir',
				'description' => 'Directory in the export to copy WordPress uploads.',
				'optional'    => true,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'remap_fields',
				'description' => 'Front matter field names to remap.',
				'optional'    => true,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'fields_to_array',
				'description' => 'Front matter fields to convert to arrays.',
				'optional'    => true,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'include_private_fields',
				'description' => 'Private post meta fields to include (they start with _).',
				'optional'    => true,
			),
			array(
				'type'        => 'flag',
				'name'        => 'skip_original_images',
				'optional'    => true,
				'description' => 'If image references should not use original file, rather the WordPress resized image.',
			),
			array(
				'type'        => 'flag',
				'name'        => 'skip_copy_uploads',
				'optional'    => true,
				'description' => 'If WordPress uploads should not be copied to the Gatsby destination directory.',
			),
			array(
				'type'        => 'flag',
				'name'        => 'skip_enforce_charset',
				'optional'    => true,
				'description' => 'If DOMDocument should not use the blog_charset for encoding.',
			),
			array(
				'type'        => 'flag',
				'name'        => 'create_type_directory',
				'optional'    => true,
				'description' => 'If post type directories should be created.',

			),
		),
	);
	WP_CLI::add_command( 'gatsby-markdown-export', 'Gatsby_Markdown_Exporter_CLI', $command_info );
}

add_action( 'admin_menu', 'gatsby_export_admin_menu' );
add_action( 'current_screen', 'gatsby_export_download' );

/**
 * Admin menu callback. Add menu page for plugin.
 */
function gatsby_export_admin_menu() {
	add_menu_page( __( 'Export to Gatsby', 'gatsby-markdown-exporter' ), __( 'Export to Gatsby', 'gatsby-markdown-exporter' ), 'manage_options', 'gatsby-markdown-export', 'gatsby_export_admin_form' );
}

/**
 * Admin page callback.
 */
function gatsby_export_admin_form() {
	$args        = array(
		'public' => true,
	);
	$post_types  = get_post_types( $args, 'objects' );
	$post_status = get_post_stati();

	// @TODO: check requirements: ziparchiver, etc

	$private_fields = '';

	if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) {
		$private_fields .= '_yoast_wpseo_primary_category
_yoast_wpseo_focuskw
_yoast_wpseo_linkdex
_yoast_wpseo_title
_yoast_wpseo_metadesc
_yoast_wpseo_content_score';
	}

	include_once dirname( __FILE__ ) . '/src/admin.php';
}

/**
 * Handle form submission.
 */
function gatsby_export_download() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['zip_exporter'] ) && wp_verify_nonce( $_POST['zip_exporter'], 'gatsby_export' ) ) {
		gatsby_export_admin_export();
	}
}

/**
 * Set the filesystem method to direct.
 *
 * @return string
 */
function gatsby_export_filesystem_method() {
	return 'direct';
}

/**
 * Export posts.
 */
function gatsby_export_admin_export() {
	ob_start();
	global $wp_filesystem;

	add_filter( 'filesystem_method', 'gatsby_export_filesystem_method' );

	WP_Filesystem();

	$directory = get_temp_dir() . md5( time() );
	$wp_filesystem->mkdir( $directory );
	$directory = trailingslashit( $directory );

	$exporter = new Gatsby_Markdown_Exporter( $directory );

	gatsby_export_prepare_exporter( $exporter );

	$exporter->prepare();
	$total_posts = $exporter->get_post_count();

	for ( $i = 0; $i < $total_posts; $i++ ) {
		$exported = $exporter->export_next();
	}

	$zip_file = get_temp_dir() . 'wp-gatsby-export-' . sanitize_file_name( get_bloginfo( 'name' ) ) . '.zip';
	gatsby_export_create_zip( $directory, $zip_file );

	ob_end_clean();
	gatsby_export_send_zip( $zip_file );
	gatsby_export_cleanup( $directory, $zip_file );
	exit;
}

/**
 * Prepare the exporter object with form values.
 *
 * @param GatsbyMarkdownExporter $exporter the initialized exporter.
 */
function gatsby_export_prepare_exporter( $exporter ) {
	if ( ! isset( $_POST['zip_exporter'] ) || ! wp_verify_nonce( $_POST['zip_exporter'], 'gatsby_export' ) ) {
		wp_die();
	}

	$args        = array(
		'public' => true,
	);
	$post_types  = get_post_types( $args );
	$post_status = get_post_stati();

	if ( isset( $_POST['post_type'] ) ) {
		$valid = true;

		foreach ( $_POST['post_type'] as $type ) {
			if ( ! in_array( $type, $post_types, true ) ) {
				$valid = false;
			}
		}
		if ( $valid ) {
			$exporter->set_post_types( $_POST['post_type'] );
		}
	}
	if ( isset( $_POST['post_status'] ) ) {
		$post_status[] = 'any';
		if ( in_array( $_POST['post_status'], $post_status, true ) ) {
			$exporter->set_post_status( $_POST['post_status'] );
		}
	}

	if ( isset( $_POST['post_date_format'] ) ) {
		$exporter->set_post_date_format( $_POST['post_date_format'] );
	}

	if ( isset( $_POST['fields_to_markdown'] ) ) {
		$markdown_fields = array_filter( preg_split( '/\r\n|\r|\n/', $_POST['fields_to_markdown'] ) );
		$exporter->set_fields_to_markdown( $markdown_fields );
	}

	if ( isset( $_POST['fields_to_exclude'] ) ) {
		$exclude_fields = array_filter( preg_split( '/\r\n|\r|\n/', $_POST['fields_to_exclude'] ) );
		$exporter->set_excluded_front_matter( $exclude_fields );
	}

	if ( isset( $_POST['remap_fields'] ) ) {
		$remap_fields = array();
		$sets         = array_filter( preg_split( '/\r\n|\r|\n/', $_POST['remap_fields'] ) );
		foreach ( $sets as $set ) {
			$remap = explode( ',', $set );
			if ( count( $remap ) === 2 ) {
				$remap_fields[ trim( $remap[0] ) ] = trim( $remap[1] );
				$exporter->set_remap_fields( $remap_fields );
			}
		}
	}

	if ( isset( $_POST['fields_to_array'] ) ) {
		$array_fields = preg_split( '/\r\n|\r|\n/', $_POST['fields_to_array'] );
		$exporter->set_fields_to_array( $array_fields );
	}

	if ( isset( $_POST['skip_copy_uploads'] ) ) {
		$exporter->set_copy_uploads( false );
	}

	if ( isset( $_POST['skip_original_images'] ) ) {
		$exporter->set_original_images( false );
	}

	if ( isset( $_POST['create_type_directory'] ) ) {
		$exporter->set_create_type_directory( true );
	}

	if ( isset( $_POST['private_fields'] ) ) {
		$private_fields = array_filter( preg_split( '/\r\n|\r|\n/', $_POST['private_fields'] ) );
		$exporter->set_included_private_post_meta( $private_fields );
	}
}

/**
 * Create the zip file of exported files.
 *
 * @param string $source source file directory.
 * @param string $destination target zip file location.
 */
function gatsby_export_create_zip( $source, $destination ) {
	$zip = new ZipArchive();
	$zip->open( $destination, ZipArchive::CREATE | ZipArchive::OVERWRITE );

	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source ), RecursiveIteratorIterator::SELF_FIRST );
	foreach ( $files as $file ) {
		if ( in_array( substr( $file, strrpos( $file, DIRECTORY_SEPARATOR ) + 1 ), array( '.', '..' ), true ) ) {
			continue;
		}

		// @TODO: skip empty directories?
		if ( is_dir( $file ) === true ) {
			$zip->addEmptyDir( substr( realpath( $file ), strlen( $source ) ) );
		} elseif ( is_file( $file ) === true ) {
			// @TODO: skip resized images
			$zip->addFile( $file, substr( realpath( $file ), strlen( $source ) ) );
		}
	}

	$zip->close();
}

/**
 * Send the zip file to the client.
 *
 * @param string $zip zip file location.
 */
function gatsby_export_send_zip( $zip ) {
	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename=' . wp_basename( $zip ) );
	header( 'Content-Length: ' . filesize( $zip ) );
	flush();
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_readfile
	readfile( $zip );
}

/**
 * Cleanup the created files.
 *
 * @param string $directory directory with raw markdown export.
 * @param string $zip_file path to zip file.
 */
function gatsby_export_cleanup( $directory, $zip_file ) {
	global $wp_filesystem;

	$wp_filesystem->delete( $directory, true );
	$wp_filesystem->delete( $zip_file );
}
