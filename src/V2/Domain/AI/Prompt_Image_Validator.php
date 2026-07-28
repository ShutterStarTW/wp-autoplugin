<?php

namespace WP_Autoplugin\V2\Domain\AI;

/** Validates bounded prompt-image uploads before durable persistence. */
final class Prompt_Image_Validator {
	public const MAX_IMAGES      = 6;
	public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
	public const MAX_TOTAL_BYTES = 20 * 1024 * 1024;
	public const MAX_DIMENSION   = 8000;
	private const ALLOWED_MIMES  = [ 'image/jpeg', 'image/png', 'image/webp' ];

	/**
	 * Normalize PHP's single/multiple upload shapes and validate every image.
	 *
	 * @param array<string, mixed> $files Raw REST file parameters.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function uploads( array $files ) {
		$raw = $files['prompt_images'] ?? [];
		if ( ! is_array( $raw ) || empty( $raw['name'] ) ) {
			return [];
		}

		$names = is_array( $raw['name'] ) ? $raw['name'] : [ $raw['name'] ];
		if ( count( $names ) > self::MAX_IMAGES ) {
			return new \WP_Error( 'wp_autoplugin_prompt_images_count', __( 'Attach no more than six images to one message.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}

		$images = [];
		$total  = 0;
		foreach ( array_keys( $names ) as $index ) {
			$file = [];
			foreach ( [ 'name', 'type', 'tmp_name', 'error', 'size' ] as $key ) {
				$value        = $raw[ $key ] ?? null;
				$file[ $key ] = is_array( $value ) ? ( $value[ $index ] ?? null ) : $value;
			}
			$validated = $this->file( $file );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
			$total += (int) $validated['byte_size'];
			if ( $total > self::MAX_TOTAL_BYTES ) {
				return new \WP_Error( 'wp_autoplugin_prompt_images_total', __( 'Prompt images may use at most 20 MiB in total.', 'wp-autoplugin' ), [ 'status' => 400 ] );
			}
			$images[] = $validated;
		}

		return $images;
	}

	/** @param array<string, mixed> $file @return array<string, mixed>|\WP_Error */
	private function file( array $file ) {
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new \WP_Error( 'wp_autoplugin_prompt_image_upload', __( 'One of the prompt images could not be uploaded.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp || ( ! is_uploaded_file( $tmp ) && ! is_readable( $tmp ) ) ) {
			return new \WP_Error( 'wp_autoplugin_prompt_image_unreadable', __( 'One of the prompt images is unreadable.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}
		$bytes = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Validated temporary upload.
		if ( false === $bytes || '' === $bytes ) {
			return new \WP_Error( 'wp_autoplugin_prompt_image_empty', __( 'Prompt images cannot be empty.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}
		$size = strlen( $bytes );
		if ( $size > self::MAX_IMAGE_BYTES ) {
			return new \WP_Error( 'wp_autoplugin_prompt_image_large', __( 'Each prompt image must be 5 MiB or smaller.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}

		$info = @getimagesizefromstring( $bytes );
		$mime = is_array( $info ) ? sanitize_mime_type( (string) ( $info['mime'] ?? '' ) ) : '';
		if ( ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
			return new \WP_Error( 'wp_autoplugin_prompt_image_type', __( 'Prompt images must be JPEG, PNG, or WebP files.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}
		$claimed = sanitize_mime_type( (string) ( $file['type'] ?? '' ) );
		if ( $claimed !== $mime ) {
			return new \WP_Error( 'wp_autoplugin_prompt_image_mismatch', __( 'A prompt image does not match its declared file type.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}
		$width  = (int) ( $info[0] ?? 0 );
		$height = (int) ( $info[1] ?? 0 );
		if ( $width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION ) {
			return new \WP_Error( 'wp_autoplugin_prompt_image_dimensions', __( 'Prompt images must be no larger than 8000 by 8000 pixels.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}

		return [
			'filename'  => sanitize_file_name( (string) ( $file['name'] ?? '' ) ) ?: 'image',
			'mime_type' => $mime,
			'byte_size' => $size,
			'width'     => $width,
			'height'    => $height,
			'sha256'    => hash( 'sha256', $bytes ),
			'content'   => $bytes,
		];
	}
}
