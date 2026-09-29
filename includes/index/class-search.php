<?php
/**
 * Find the passages of the site that answer a question.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\Index;

use ZinnDigital\ZinnChat\AiCore\Client;
use ZinnDigital\ZinnChat\Schema;
use ZinnDigital\ZinnChat\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hybrid search: meaning (vectors, when the index has them) plus words (FULLTEXT), merged.
 *
 * ⛔ Every item WITH PASSAGES is searchable, whatever its status: an item waiting to be re-read
 * (after an update, a settings change, "Re-read the whole site") keeps answering from the text it
 * had until the new text replaces it. Searching only `indexed` items made a whole site's answers
 * vanish for the minutes a re-read took (found in the e2e run, 2026-09-29).
 *
 * Two stages so a large site stays fast: every item's single vector is scanned to pick the
 * most relevant pages, then only those pages' passages (plus the best keyword passages) are
 * scored. Each source comes back with what the test console shows: URL, title, last modified,
 * snippet, score, and two warnings — `old` (not updated for over a year) and `outdated` (another
 * result covers the same subject and is more than six months newer), which is how a site owner
 * finds the stale page that is giving visitors a wrong answer.
 */
final class Search {

	private const ITEMS    = 40;
	private const PER_ITEM = 2;

	/**
	 * Search.
	 *
	 * @param string               $query Question.
	 * @param array<string, mixed> $args  `limit` (default 6), `language` (prefer this locale).
	 * @return array{sources: array<int, array<string, mixed>>, mode: string, error: string}
	 */
	public static function query( string $query, array $args = array() ): array {
		$query = trim( $query );
		$limit = max( 1, min( 20, (int) ( $args['limit'] ?? 6 ) ) );
		$out   = array(
			'sources' => array(),
			'mode'    => 'none',
			'error'   => '',
		);
		if ( '' === $query ) {
			return $out;
		}

		$vector = array();
		$model  = Index::model_key();
		if ( '' !== $model && self::has_vectors( $model ) ) {
			$result = Client::embed(
				array( $query ),
				array(
					'purpose'    => 'zinn-chat-search',
					'dimensions' => Vectors::DIMENSIONS,
					'type'       => 'query',
					'system'     => true,
				)
			);
			if ( $result->ok() && isset( $result->vectors[0] ) ) {
				$vector = Vectors::normalise( $result->vectors[0] );
			} elseif ( $result->failure ) {
				$out['error'] = $result->failure->message;
			}
		}

		$items    = $vector ? self::top_items( $vector, $model ) : array();
		$keywords = self::keyword_chunks( $query );
		$chunks   = self::chunks_for( array_keys( $items ), array_keys( $keywords ) );
		if ( ! $chunks ) {
			return $out;
		}
		$max_kw = $keywords ? max( $keywords ) : 0.0;
		$scored = array();
		foreach ( $chunks as $chunk ) {
			$kw = $max_kw > 0 && isset( $keywords[ (int) $chunk['id'] ] ) ? $keywords[ (int) $chunk['id'] ] / $max_kw : 0.0;
			if ( $vector && null !== $chunk['embedding'] && $model === $chunk['embed_model'] ) {
				$cos   = Vectors::cosine( $vector, (string) $chunk['embedding'] );
				$score = 0.85 * $cos + 0.15 * $kw;
			} else {
				// Keyword only: scale into the same range the semantic score uses, below a strong match.
				$score = $vector ? 0.6 * $kw : $kw;
			}
			$scored[] = array( $score, $chunk );
		}
		usort(
			$scored,
			static function ( array $a, array $b ): int {
				return $b[0] <=> $a[0];
			}
		);

		$per_item = array();
		$language = (string) ( $args['language'] ?? '' );
		foreach ( $scored as $pair ) {
			list( $score, $chunk ) = $pair;
			$item_id               = (int) $chunk['item_id'];
			if ( ( $per_item[ $item_id ] ?? 0 ) >= self::PER_ITEM ) {
				continue;
			}
			// On a multilingual site, prefer the visitor's language: other languages rank lower.
			if ( '' !== $language && '' !== (string) $chunk['language'] && 0 !== strpos( (string) $chunk['language'], substr( $language, 0, 2 ) ) ) {
				$score *= 0.85;
			}
			$per_item[ $item_id ] = ( $per_item[ $item_id ] ?? 0 ) + 1;
			$out['sources'][]     = self::source( $chunk, (float) $score );
			if ( count( $out['sources'] ) >= $limit ) {
				break;
			}
		}
		usort(
			$out['sources'],
			static function ( array $a, array $b ): int {
				return $b['score'] <=> $a['score'];
			}
		);
		self::flag( $out['sources'] );
		$out['mode'] = $vector ? 'semantic' : 'keyword';
		return $out;
	}

	/**
	 * Does the index hold any vectors of this model?
	 *
	 * @param string $model Model key.
	 * @return bool
	 */
	private static function has_vectors( string $model ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE chunk_count > 0 AND embed_model = %s LIMIT 1', $table, $model ) );
	}

	/**
	 * Stage one: the most relevant items by their single vector, scanned in batches.
	 *
	 * @param array<int, float> $vector Query vector.
	 * @param string            $model  Model key.
	 * @return array<int, float> item_id => cosine.
	 */
	private static function top_items( array $vector, string $model ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_items';
		$best  = array();
		$after = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, embedding FROM %i WHERE chunk_count > 0 AND embed_model = %s AND embedding IS NOT NULL AND id > %d ORDER BY id LIMIT 2000', $table, $model, $after ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$after                    = (int) $row['id'];
				$best[ (int) $row['id'] ] = Vectors::cosine( $vector, (string) $row['embedding'] );
			}
			if ( count( $best ) > self::ITEMS * 4 ) {
				arsort( $best );
				$best = array_slice( $best, 0, self::ITEMS, true );
			}
			$more = is_array( $rows ) && 2000 === count( $rows );
		} while ( $more );
		arsort( $best );
		return array_slice( $best, 0, self::ITEMS, true );
	}

	/**
	 * Passages matching the words of the question.
	 *
	 * @param string $query Question.
	 * @return array<int, float> chunk_id => relevance.
	 */
	private static function keyword_chunks( string $query ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_chunks';
		$words = self::words( $query );
		if ( ! $words ) {
			return array();
		}
		$out = array();
		if ( Schema::has_fulltext() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, MATCH(body) AGAINST (%s IN NATURAL LANGUAGE MODE) AS score FROM %i WHERE MATCH(body) AGAINST (%s IN NATURAL LANGUAGE MODE) ORDER BY score DESC LIMIT 40', implode( ' ', $words ), $table, implode( ' ', $words ) ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$out[ (int) $row['id'] ] = (float) $row['score'];
			}
			if ( $out ) {
				return $out;
			}
		}
		// LIKE fallback (no FULLTEXT, or words FULLTEXT ignores: short, stop words, CJK). A fixed
		// statement with six word slots; unused slots hold a pattern no passage contains.
		$slots = array();
		foreach ( array_slice( $words, 0, 6 ) as $word ) {
			$slots[] = '%' . $wpdb->esc_like( $word ) . '%';
		}
		list( $w1, $w2, $w3, $w4, $w5, $w6 ) = array_pad( $slots, 6, "\x01zinn-chat-none\x01" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, ((body LIKE %s) + (body LIKE %s) + (body LIKE %s) + (body LIKE %s) + (body LIKE %s) + (body LIKE %s)) AS score FROM %i WHERE body LIKE %s OR body LIKE %s OR body LIKE %s OR body LIKE %s OR body LIKE %s OR body LIKE %s ORDER BY score DESC LIMIT 40', $w1, $w2, $w3, $w4, $w5, $w6, $table, $w1, $w2, $w3, $w4, $w5, $w6 ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = (float) $row['score'];
		}
		return $out;
	}

	/**
	 * Significant words of a question (lower-case, no punctuation, no very short words).
	 *
	 * @param string $query Question.
	 * @return array<int, string>
	 */
	public static function words( string $query ): array {
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $query ) );
		$stop  = array( 'the', 'and', 'for', 'you', 'your', 'are', 'can', 'how', 'what', 'does', 'with', 'this', 'that', 'have', 'from', 'about', 'when', 'where', 'which', 'will', 'there', 'their', 'into', 'out', 'any', 'our', 'was', 'not', 'but' );
		$words = array();
		foreach ( (array) $parts as $part ) {
			$part = (string) $part;
			if ( mb_strlen( $part ) >= 3 && ! in_array( $part, $stop, true ) ) {
				$words[] = $part;
			}
		}
		return array_values( array_unique( $words ) );
	}

	/**
	 * Stage two: the candidate passages with their item's fields.
	 *
	 * @param array<int, int> $item_ids  Items from stage one.
	 * @param array<int, int> $chunk_ids Keyword passages.
	 * @return array<int, array<string, mixed>>
	 */
	private static function chunks_for( array $item_ids, array $chunk_ids ): array {
		global $wpdb;
		if ( ! $item_ids && ! $chunk_ids ) {
			return array();
		}
		$chunks = $wpdb->prefix . 'zinn_chat_chunks';
		$items  = $wpdb->prefix . 'zinn_chat_items';
		// An empty list becomes (0), which matches no row, so the statement never changes shape.
		$item_ids  = $item_ids ? array_map( 'intval', $item_ids ) : array( 0 );
		$chunk_ids = $chunk_ids ? array_map( 'intval', $chunk_ids ) : array( 0 );
		$in_items  = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
		$in_chunks = implode( ',', array_fill( 0, count( $chunk_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the plugin's own tables; the IN lists are %d placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.id, c.item_id, c.body, c.embedding, i.title, i.url, i.kind, i.object_type, i.object_id, i.modified_gmt, i.language, i.embed_model, i.embedding AS item_vector FROM %i c JOIN %i i ON i.id = c.item_id WHERE i.chunk_count > 0 AND ( c.item_id IN ({$in_items}) OR c.id IN ({$in_chunks}) ) LIMIT 600", array_merge( array( $chunks, $items ), $item_ids, $chunk_ids ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A result row.
	 *
	 * @param array<string, mixed> $chunk Chunk joined with its item.
	 * @param float                $score Score.
	 * @return array<string, mixed>
	 */
	private static function source( array $chunk, float $score ): array {
		$edit = '';
		if ( 'post' === $chunk['object_type'] && current_user_can( 'edit_post', (int) $chunk['object_id'] ) ) {
			$edit = (string) get_edit_post_link( (int) $chunk['object_id'], 'raw' );
		}
		return array(
			'item_id'  => (int) $chunk['item_id'],
			'chunk_id' => (int) $chunk['id'],
			'title'    => (string) $chunk['title'],
			'url'      => (string) $chunk['url'],
			'kind'     => (string) $chunk['kind'],
			'modified' => Util::iso( (string) $chunk['modified_gmt'] ),
			'text'     => (string) $chunk['body'],
			'snippet'  => mb_substr( preg_replace( '/\s+/u', ' ', (string) $chunk['body'] ), 0, 280 ),
			'score'    => round( $score, 3 ),
			'edit_url' => $edit,
			'vector'   => (string) $chunk['item_vector'],
			'flags'    => array(),
		);
	}

	/**
	 * Do two results cover the same subject?
	 *
	 * Nearly identical meaning, or close meaning AND mostly the same title words ("Returns policy"
	 * and "Returns policy (2023)"). ⭐ Measured on gemini-embedding-2 at 256 dimensions, 2026-09-29:
	 * that pair scored 0.853, the closest UNRELATED pair on the same site 0.762 — so a cosine alone
	 * at a level that catches the first also catches the second on another model; the title check
	 * is what keeps "Hello world" and "Sample page" apart.
	 *
	 * @param array<string, mixed> $a Result.
	 * @param array<string, mixed> $b Result.
	 * @return bool
	 */
	public static function same_subject( array $a, array $b ): bool {
		$cosine = Vectors::cosine_packed( (string) $a['vector'], (string) $b['vector'] );
		if ( $cosine >= 0.92 ) {
			return true;
		}
		if ( $cosine < 0.75 ) {
			return false;
		}
		$wa = self::words( (string) $a['title'] );
		$wb = self::words( (string) $b['title'] );
		if ( ! $wa || ! $wb ) {
			return false;
		}
		$shared = count( array_intersect( $wa, $wb ) );
		return $shared / count( array_unique( array_merge( $wa, $wb ) ) ) >= 0.5;
	}

	/**
	 * Flag old and outdated sources (in place), then drop the internal vector.
	 *
	 * @param array<int, array<string, mixed>> $sources Results.
	 * @return void
	 */
	public static function flag( array &$sources ): void {
		$year = time() - YEAR_IN_SECONDS;
		foreach ( $sources as $i => $source ) {
			$modified = '' !== $source['modified'] ? (int) strtotime( $source['modified'] ) : 0;
			if ( $modified && $modified < $year && 'woo_store' !== $source['kind'] ) {
				$sources[ $i ]['flags'][] = 'old';
			}
			foreach ( $sources as $j => $other ) {
				if ( $i === $j || $source['item_id'] === $other['item_id'] || '' === $source['vector'] || '' === $other['vector'] ) {
					continue;
				}
				$other_modified = '' !== $other['modified'] ? (int) strtotime( $other['modified'] ) : 0;
				if ( $modified && $other_modified - $modified > 180 * DAY_IN_SECONDS && self::same_subject( $source, $other ) ) {
					$sources[ $i ]['flags'][]     = 'outdated';
					$sources[ $i ]['newer_url']   = $other['url'];
					$sources[ $i ]['newer_title'] = $other['title'];
					break;
				}
			}
			$sources[ $i ]['flags'] = array_values( array_unique( $sources[ $i ]['flags'] ) );
		}
		foreach ( $sources as $i => $source ) {
			unset( $sources[ $i ]['vector'] );
		}
	}
}
