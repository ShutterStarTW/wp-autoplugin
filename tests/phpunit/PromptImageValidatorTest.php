<?php

use WP_Autoplugin\V2\Domain\AI\Prompt_Image_Validator;

/** Focused validation coverage for private prompt-image uploads. */
final class PromptImageValidatorTest extends WP_UnitTestCase {
	/** @var array<int, string> */
	private array $temporary_files = [];

	public function tear_down(): void {
		foreach ( $this->temporary_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only temporary upload fixture.
			}
		}
		parent::tear_down();
	}

	public function test_accepts_a_verified_png_and_returns_only_validated_metadata_and_content(): void {
		$result = ( new Prompt_Image_Validator() )->uploads( $this->upload( $this->png(), 'image/png', 'A screenshot.png' ) );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertCount( 1, $result );
		$this->assertSame( 'A-screenshot.png', $result[0]['filename'] );
		$this->assertSame( 'image/png', $result[0]['mime_type'] );
		$this->assertSame( 1, $result[0]['width'] );
		$this->assertSame( 1, $result[0]['height'] );
		$this->assertSame( hash( 'sha256', $this->png() ), $result[0]['sha256'] );
		$this->assertSame( $this->png(), $result[0]['content'] );
	}

	public function test_accepts_verified_jpeg_png_and_webp_signatures(): void {
		$result = ( new Prompt_Image_Validator() )->uploads(
			$this->uploads(
				[
					[ $this->jpeg(), 'image/jpeg', 'pixel.jpg' ],
					[ $this->png(), 'image/png', 'pixel.png' ],
					[ $this->webp(), 'image/webp', 'pixel.webp' ],
				]
			)
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( [ 'image/jpeg', 'image/png', 'image/webp' ], array_column( $result, 'mime_type' ) );
	}

	public function test_rejects_malformed_images_mime_spoofing_and_svg(): void {
		$malformed = ( new Prompt_Image_Validator() )->uploads( $this->upload( 'not an image', 'image/png', 'fake.png' ) );
		$spoofed   = ( new Prompt_Image_Validator() )->uploads( $this->upload( $this->png(), 'image/jpeg', 'fake.jpg' ) );
		$svg       = ( new Prompt_Image_Validator() )->uploads( $this->upload( '<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml', 'shape.svg' ) );

		$this->assertWPError( $malformed );
		$this->assertSame( 'wp_autoplugin_prompt_image_type', $malformed->get_error_code() );
		$this->assertWPError( $spoofed );
		$this->assertSame( 'wp_autoplugin_prompt_image_mismatch', $spoofed->get_error_code() );
		$this->assertWPError( $svg );
		$this->assertSame( 'wp_autoplugin_prompt_image_type', $svg->get_error_code() );
	}

	public function test_rejects_per_file_count_and_dimension_limits(): void {
		$large = ( new Prompt_Image_Validator() )->uploads(
			$this->upload( $this->png() . str_repeat( 'x', Prompt_Image_Validator::MAX_IMAGE_BYTES ), 'image/png', 'large.png' )
		);
		$oversized_dimensions = $this->png();
		$oversized_dimensions = substr_replace( $oversized_dimensions, pack( 'N', Prompt_Image_Validator::MAX_DIMENSION + 1 ), 16, 4 );
		$dimensions = ( new Prompt_Image_Validator() )->uploads( $this->upload( $oversized_dimensions, 'image/png', 'wide.png' ) );
		$count      = ( new Prompt_Image_Validator() )->uploads(
			[
				'prompt_images' => [
					'name'     => array_fill( 0, Prompt_Image_Validator::MAX_IMAGES + 1, 'image.png' ),
					'type'     => [],
					'tmp_name' => [],
					'error'    => [],
					'size'     => [],
				],
			]
		);
		$total = ( new Prompt_Image_Validator() )->uploads(
			$this->uploads(
				array_fill( 0, 5, [ $this->png() . str_repeat( 'x', 4 * 1024 * 1024 ), 'image/png', 'large-but-valid.png' ] )
			)
		);

		$this->assertWPError( $large );
		$this->assertSame( 'wp_autoplugin_prompt_image_large', $large->get_error_code() );
		$this->assertWPError( $dimensions );
		$this->assertSame( 'wp_autoplugin_prompt_image_dimensions', $dimensions->get_error_code() );
		$this->assertWPError( $count );
		$this->assertSame( 'wp_autoplugin_prompt_images_count', $count->get_error_code() );
		$this->assertWPError( $total );
		$this->assertSame( 'wp_autoplugin_prompt_images_total', $total->get_error_code() );
	}

	/** @return array<string, array<string, mixed>> */
	private function upload( string $bytes, string $mime, string $name ): array {
		return $this->uploads( [ [ $bytes, $mime, $name ] ] );
	}

	/** @param array<int, array{0:string,1:string,2:string}> $fixtures */
	private function uploads( array $fixtures ): array {
		$upload = [ 'prompt_images' => [ 'name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => [] ] ];
		foreach ( $fixtures as [ $bytes, $mime, $name ] ) {
			$file = wp_tempnam( $name );
			file_put_contents( $file, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only temporary upload fixture.
			$this->temporary_files[] = $file;
			$upload['prompt_images']['name'][] = $name;
			$upload['prompt_images']['type'][] = $mime;
			$upload['prompt_images']['tmp_name'][] = $file;
			$upload['prompt_images']['error'][] = UPLOAD_ERR_OK;
			$upload['prompt_images']['size'][] = strlen( $bytes );
		}
		return $upload;
	}

	private function png(): string {
		return (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WlT4iQAAAAASUVORK5CYII=', true );
	}

	private function webp(): string {
		return (string) base64_decode( 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v89WAAAAA==', true );
	}

	private function jpeg(): string {
		return (string) base64_decode( '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==', true );
	}
}
