<?php
/**
 * What a generation returned.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-result.php by wp/bin/build-ai-core.php.
 * Edit the package, never this copy: `--check` refuses a copy that differs.
 *
 * @package ZinnDigital\ZinnChat\AiCore
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\AiCore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A generation's result: either text (and, with a schema, decoded data) or a Failure.
 */
final class Result {

	/**
	 * The text answer.
	 *
	 * @var string
	 */
	public string $text = '';

	/**
	 * Decoded structured output when a schema was asked for, else null.
	 *
	 * @var array<mixed>|null
	 */
	public ?array $data = null;

	/**
	 * Input tokens the provider billed.
	 *
	 * @var int
	 */
	public int $input_tokens = 0;

	/**
	 * Output tokens the provider billed.
	 *
	 * @var int
	 */
	public int $output_tokens = 0;

	/**
	 * Provider id.
	 *
	 * @var string
	 */
	public string $provider = '';

	/**
	 * Model id.
	 *
	 * @var string
	 */
	public string $model = '';

	/**
	 * Set when the request failed.
	 *
	 * @var Failure|null
	 */
	public ?Failure $failure = null;

	/**
	 * Images a generation returned (image generation, 1.1.0): each `mime` + base64 `data`.
	 *
	 * @var array<int, array{mime: string, data: string}>
	 */
	public array $images = array();

	/**
	 * Vectors an embedding request returned (1.2.0), one per input text, in input order.
	 *
	 * @var array<int, array<int, float>>
	 */
	public array $vectors = array();

	/**
	 * A failed result.
	 *
	 * @param Failure $failure Why.
	 * @param string  $provider Provider id.
	 * @param string  $model   Model id.
	 * @return self
	 */
	public static function failed( Failure $failure, string $provider = '', string $model = '' ): self {
		$result           = new self();
		$result->failure  = $failure;
		$result->provider = $provider;
		$result->model    = $model;

		return $result;
	}

	/**
	 * Did it work?
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return null === $this->failure;
	}
}
