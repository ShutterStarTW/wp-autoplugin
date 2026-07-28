<?php
/**
 * GitHub-backed plugin updates.
 *
 * @package WP_Autoplugin
 */

namespace WP_Autoplugin\V2\Infrastructure\Update;

/**
 * Supplies WordPress with update metadata from an immutable GitHub commit.
 */
final class GitHub_Updater {
	private const HTTP_TIMEOUT       = 10;
	private const MAX_API_BYTES      = 65536;
	private const MAX_SOURCE_BYTES   = 1048576;
	private const PLUGIN_HEADER_SIZE = 8192;

	/**
	 * Absolute path to the plugin's main file.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Plugin path relative to the plugins directory.
	 *
	 * @var string
	 */
	private string $plugin_basename;

	/**
	 * Installed plugin directory name.
	 *
	 * @var string
	 */
	private string $folder;

	/**
	 * GitHub owner and repository pair.
	 *
	 * @var string
	 */
	private string $repository;

	/**
	 * Stable plugin identity used by WordPress update checks.
	 *
	 * @var string
	 */
	private string $update_uri;

	/**
	 * Hostname-derived WordPress update filter suffix.
	 *
	 * @var string
	 */
	private string $update_host;

	/**
	 * Whether this instance has registered its hooks.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Request-local remote metadata memoization.
	 *
	 * @var array<string, string>|false|null
	 */
	private $remote_metadata = null;

	/**
	 * Configure a GitHub update channel.
	 *
	 * @param string $plugin_file Absolute path to the plugin's main file.
	 * @param string $repository  GitHub owner and repository pair.
	 * @param string $update_uri  Stable Update URI from the plugin header.
	 *
	 * @throws \InvalidArgumentException When repository or update configuration is invalid.
	 */
	public function __construct( string $plugin_file, string $repository, string $update_uri ) {
		$plugin_basename = plugin_basename( $plugin_file );
		$folder          = dirname( $plugin_basename );
		$update_host     = wp_parse_url( $update_uri, PHP_URL_HOST );

		if (
			! is_file( $plugin_file )
			|| '.' === $folder
			|| ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository )
			|| ! is_string( $update_host )
			|| '' === $update_host
			|| 'https' !== wp_parse_url( $update_uri, PHP_URL_SCHEME )
		) {
			throw new \InvalidArgumentException( 'Invalid GitHub updater configuration.' );
		}

		$this->plugin_file     = wp_normalize_path( $plugin_file );
		$this->plugin_basename = $plugin_basename;
		$this->folder          = $folder;
		$this->repository      = $repository;
		$this->update_uri      = $update_uri;
		$this->update_host     = strtolower( $update_host );
	}

	/**
	 * Register update hooks without performing network requests.
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		add_filter( 'update_plugins_' . $this->update_host, [ $this, 'filter_update' ], 10, 3 );
		add_filter( 'plugins_api', [ $this, 'filter_plugin_information' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'normalize_package_source' ], 10, 4 );
		$this->registered = true;
	}

	/**
	 * Return an update payload for this plugin's Update URI.
	 *
	 * @param array<string, mixed>|false $update      Existing update response.
	 * @param array<string, mixed>       $plugin_data Installed plugin headers.
	 * @param string                     $plugin_file Installed plugin basename.
	 * @return array<string, mixed>|false
	 */
	public function filter_update( $update, array $plugin_data, string $plugin_file ) {
		if (
			$this->plugin_basename !== $plugin_file
			|| $this->update_uri !== ( $plugin_data['UpdateURI'] ?? '' )
		) {
			return $update;
		}

		$metadata = $this->get_remote_metadata();
		if ( false === $metadata ) {
			return false;
		}

		return array_filter(
			[
				'id'           => $this->update_uri,
				'slug'         => $this->folder,
				'version'      => $metadata['version'],
				'url'          => $metadata['url'],
				'package'      => $metadata['package'],
				'requires'     => $metadata['requires'],
				'tested'       => $metadata['tested'],
				'requires_php' => $metadata['requires_php'],
			],
			static fn( $value ): bool => '' !== $value
		);
	}

	/**
	 * Supply the native plugin-details modal for this non-WordPress.org plugin.
	 *
	 * @param mixed  $result Existing API result.
	 * @param string $action Requested API action.
	 * @param object $args   API request arguments.
	 * @return mixed
	 */
	public function filter_plugin_information( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== $this->folder ) {
			return $result;
		}

		$local    = $this->get_local_plugin_data();
		$metadata = $this->get_remote_metadata();
		$remote   = is_array( $metadata ) ? $metadata : [];
		$sections = [
			'description' => $remote['description'] ?? wpautop( esc_html( (string) ( $local['Description'] ?? '' ) ) ),
		];
		if ( ! empty( $remote['changelog'] ) ) {
			$sections['changelog'] = $remote['changelog'];
		}

		return (object) array_filter(
			[
				'name'          => (string) ( $local['Name'] ?? 'WP-Autoplugin' ),
				'slug'          => $this->folder,
				'version'       => (string) ( $remote['version'] ?? $local['Version'] ?? '' ),
				'author'        => (string) ( $local['AuthorName'] ?? $local['Author'] ?? '' ),
				'homepage'      => (string) ( $local['PluginURI'] ?? $this->repository_url() ),
				'requires'      => (string) ( $remote['requires'] ?? $local['RequiresWP'] ?? '' ),
				'tested'        => (string) ( $remote['tested'] ?? '' ),
				'requires_php'  => (string) ( $remote['requires_php'] ?? $local['RequiresPHP'] ?? '' ),
				'last_updated'  => (string) ( $remote['last_updated'] ?? '' ),
				'download_link' => (string) ( $remote['package'] ?? '' ),
				'sections'      => $sections,
			],
			static fn( $value ): bool => '' !== $value
		);
	}

	/**
	 * Rename GitHub's commit folder before WordPress selects the install destination.
	 *
	 * @param string|\WP_Error    $source        Extracted package source.
	 * @param string              $remote_source Package extraction root.
	 * @param \WP_Upgrader        $upgrader      Active upgrader.
	 * @param array<string,mixed> $hook_extra Upgrade context.
	 * @return string|\WP_Error
	 */
	public function normalize_package_source( $source, string $remote_source, $upgrader, array $hook_extra ) {
		unset( $upgrader );

		if ( ! is_string( $source ) || ! $this->is_our_plugin_update( $hook_extra ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem ) ) {
			return new \WP_Error(
				'wp_autoplugin_update_filesystem_unavailable',
				__( 'WP-Autoplugin could not access the filesystem to prepare its update.', 'wp-autoplugin' )
			);
		}

		$source           = trailingslashit( $source );
		$source_path      = untrailingslashit( $source );
		$remote_path      = untrailingslashit( $remote_source );
		$main_file        = $source . basename( $this->plugin_basename );
		$destination      = trailingslashit( $remote_source ) . $this->folder;
		$destination_path = untrailingslashit( $destination );

		if ( ! $wp_filesystem->exists( $main_file ) ) {
			return new \WP_Error(
				'wp_autoplugin_update_invalid_package',
				__( 'The WP-Autoplugin update package does not contain the expected plugin file.', 'wp-autoplugin' )
			);
		}

		if ( $this->folder === basename( $source_path ) ) {
			return $source;
		}

		if (
			$source_path === $remote_path
			|| $wp_filesystem->exists( $destination_path )
			|| ! $wp_filesystem->move( $source_path, $destination_path )
		) {
			return new \WP_Error(
				'wp_autoplugin_update_source_move_failed',
				__( 'WordPress could not prepare the WP-Autoplugin update package.', 'wp-autoplugin' )
			);
		}

		return trailingslashit( $destination_path );
	}

	/**
	 * Fetch the latest stable release and pin its package to the inspected commit.
	 *
	 * @return array<string, string>|false
	 */
	private function get_remote_metadata() {
		if ( null !== $this->remote_metadata ) {
			return $this->remote_metadata;
		}

		$api_root     = 'https://api.github.com/repos/' . $this->repository . '/';
		$release_body = $this->remote_get(
			$api_root . 'releases/latest',
			'application/vnd.github+json',
			self::MAX_API_BYTES,
			[ 'X-GitHub-Api-Version' => '2022-11-28' ]
		);
		$release      = false !== $release_body ? json_decode( $release_body, true ) : null;
		$tag          = is_array( $release ) ? (string) ( $release['tag_name'] ?? '' ) : '';
		$tag_version  = ltrim( $tag, 'vV' );
		if (
			! is_array( $release )
			|| ! empty( $release['draft'] )
			|| ! empty( $release['prerelease'] )
			|| ! preg_match( '/^[vV]?[0-9][0-9A-Za-z._+-]{0,63}$/', $tag )
			|| ! $this->is_valid_version( $tag_version )
		) {
			$this->remote_metadata = false;
			return false;
		}

		$commit_body = $this->remote_get(
			$api_root . 'commits/' . rawurlencode( $tag ),
			'application/vnd.github+json',
			self::MAX_API_BYTES,
			[ 'X-GitHub-Api-Version' => '2022-11-28' ]
		);
		$commit      = false !== $commit_body ? json_decode( $commit_body, true ) : null;
		$sha         = is_array( $commit ) ? strtolower( (string) ( $commit['sha'] ?? '' ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $sha ) ) {
			$this->remote_metadata = false;
			return false;
		}

		$raw_root      = 'https://raw.githubusercontent.com/' . $this->repository . '/' . $sha . '/';
		$plugin_source = $this->remote_get(
			$raw_root . rawurlencode( basename( $this->plugin_basename ) ),
			'text/plain',
			self::MAX_SOURCE_BYTES
		);
		if ( false === $plugin_source ) {
			$this->remote_metadata = false;
			return false;
		}

		$plugin_headers = $this->parse_headers(
			$plugin_source,
			[
				'version'      => 'Version',
				'requires'     => 'Requires at least',
				'requires_php' => 'Requires PHP',
			],
			self::PLUGIN_HEADER_SIZE
		);
		$version        = $plugin_headers['version'] ?? '';
		if ( ! $this->is_valid_version( $version ) || $tag_version !== $version ) {
			$this->remote_metadata = false;
			return false;
		}

		$readme         = $this->remote_get( $raw_root . 'readme.txt', 'text/plain', self::MAX_SOURCE_BYTES );
		$readme_headers = false !== $readme
			? $this->parse_headers(
				$readme,
				[
					'requires'     => 'Requires at least',
					'tested'       => 'Tested up to',
					'requires_php' => 'Requires PHP',
				],
				self::PLUGIN_HEADER_SIZE
			)
			: [];

		$this->remote_metadata = [
			'version'      => $version,
			'requires'     => $this->valid_requirement( $plugin_headers['requires'] ?? $readme_headers['requires'] ?? '' ),
			'tested'       => $this->valid_requirement( $readme_headers['tested'] ?? '' ),
			'requires_php' => $this->valid_requirement( $plugin_headers['requires_php'] ?? $readme_headers['requires_php'] ?? '' ),
			'package'      => 'https://github.com/' . $this->repository . '/archive/' . $sha . '.zip',
			'url'          => $this->repository_url() . '/releases/tag/' . rawurlencode( $tag ),
			'last_updated' => $this->release_date( (string) ( $release['published_at'] ?? '' ) ),
			'description'  => false !== $readme ? $this->render_readme_section( $readme, 'Description' ) : '',
			'changelog'    => false !== $readme ? $this->render_readme_section( $readme, 'Changelog' ) : '',
		];

		return $this->remote_metadata;
	}

	/**
	 * Perform a bounded, SSL-verified request to a fixed GitHub URL.
	 *
	 * @param string                $url     GitHub URL.
	 * @param string                $accept  Accept header value.
	 * @param int                   $limit   Maximum response bytes.
	 * @param array<string, string> $headers Extra request headers.
	 * @return string|false
	 */
	private function remote_get( string $url, string $accept, int $limit, array $headers = [] ) {
		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => self::HTTP_TIMEOUT,
				'redirection'         => 3,
				'limit_response_size' => $limit,
				'headers'             => array_merge(
					[
						'Accept'     => $accept,
						'User-Agent' => 'WP-Autoplugin/' . ( defined( 'WP_AUTOPLUGIN_VERSION' ) ? WP_AUTOPLUGIN_VERSION : 'unknown' ) . '; ' . home_url( '/' ),
					],
					$headers
				),
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		return is_string( $body ) && '' !== $body ? $body : false;
	}

	/**
	 * Extract bounded plugin or readme header values.
	 *
	 * @param string                $source Source file contents.
	 * @param array<string, string> $fields Output key to header label map.
	 * @param int                   $limit  Maximum bytes to inspect.
	 * @return array<string, string>
	 */
	private function parse_headers( string $source, array $fields, int $limit ): array {
		$source  = substr( $source, 0, $limit );
		$headers = [];
		foreach ( $fields as $key => $label ) {
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':\s*(.+)$/mi', $source, $matches ) ) {
				$headers[ $key ] = sanitize_text_field( trim( $matches[1] ) );
			}
		}
		return $headers;
	}

	/**
	 * Determine whether a remote version header is safe for version_compare().
	 *
	 * @param string $version Version header value.
	 */
	private function is_valid_version( string $version ): bool {
		return 1 === preg_match( '/^[0-9][0-9A-Za-z._+-]{0,63}$/', $version );
	}

	/**
	 * Return a validated requirement or omit it from update metadata.
	 *
	 * @param string $version Requirement header value.
	 */
	private function valid_requirement( string $version ): string {
		return $this->is_valid_version( $version ) ? $version : '';
	}

	/**
	 * Normalize a GitHub release date for the WordPress details modal.
	 *
	 * @param string $published_at ISO 8601 release timestamp.
	 */
	private function release_date( string $published_at ): string {
		$timestamp = strtotime( $published_at );
		return false !== $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : '';
	}

	/**
	 * Render a WordPress readme section as a small, sanitized HTML subset.
	 *
	 * @param string $readme Readme contents.
	 * @param string $section Section heading.
	 */
	private function render_readme_section( string $readme, string $section ): string {
		if ( ! preg_match( '/^==\s*' . preg_quote( $section, '/' ) . '\s*==\s*(.+?)(?=^\s*==\s*[^=]+==|\z)/ims', $readme, $matches ) ) {
			return '';
		}

		$blocks = preg_split( '/\n\s*\n/', trim( str_replace( [ "\r\n", "\r" ], "\n", $matches[1] ) ) );
		$html   = [];
		foreach ( is_array( $blocks ) ? $blocks : [] as $block ) {
			$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $block ) ), static fn( string $line ): bool => '' !== $line ) );
			if ( [] === $lines ) {
				continue;
			}

			if ( 1 === count( $lines ) && preg_match( '/^=+\s*(.+?)\s*=+$/', $lines[0], $heading ) ) {
				$html[] = '<h4>' . $this->render_inline_markup( $heading[1] ) . '</h4>';
				continue;
			}

			$list_items = [];
			foreach ( $lines as $line ) {
				if ( ! preg_match( '/^[*-]\s+(.+)$/', $line, $item ) ) {
					$list_items = [];
					break;
				}
				$list_items[] = '<li>' . $this->render_inline_markup( $item[1] ) . '</li>';
			}
			if ( [] !== $list_items ) {
				$html[] = '<ul>' . implode( '', $list_items ) . '</ul>';
				continue;
			}

			$html[] = '<p>' . $this->render_inline_markup( implode( ' ', $lines ) ) . '</p>';
		}

		return wp_kses_post( implode( "\n", $html ) );
	}

	/**
	 * Render the small inline-markup subset used by WordPress readmes.
	 *
	 * @param string $text Readme text.
	 */
	private function render_inline_markup( string $text ): string {
		$text = esc_html( $text );
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text ) ?? $text;
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)]+)\)/',
			static function ( array $matches ): string {
				$url = esc_url( html_entity_decode( $matches[2] ) );
				return '' !== $url
					? '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $matches[1] . '</a>'
					: $matches[1];
			},
			$text
		) ?? $text;
		return wp_kses_post( $text );
	}

	/**
	 * Load unformatted local plugin headers.
	 *
	 * @return array<string, mixed>
	 */
	private function get_local_plugin_data(): array {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return get_plugin_data( $this->plugin_file, false, false );
	}

	/**
	 * Determine whether an upgrader operation targets this plugin.
	 *
	 * @param array<string, mixed> $hook_extra Upgrade context.
	 */
	private function is_our_plugin_update( array $hook_extra ): bool {
		return 'plugin' === ( $hook_extra['type'] ?? '' )
			&& 'update' === ( $hook_extra['action'] ?? '' )
			&& $this->plugin_basename === ( $hook_extra['plugin'] ?? '' );
	}

	/**
	 * Return the public GitHub repository URL.
	 */
	private function repository_url(): string {
		return 'https://github.com/' . $this->repository;
	}
}
