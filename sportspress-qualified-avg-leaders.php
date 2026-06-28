<?php
/**
 * ============================================================================
 * SportPress — Qualified Average Leaders Shortcode (Generic version)
 * ============================================================================
 *
 *  Developed Edoy.Net - 2026 - V1.0 
 *
 * PROBLEM THIS SOLVES:
 * SportPress' built-in "leader board" tables can sort by a single column
 * (AVG, HITS, etc.) but they CANNOT apply a "minimum plate appearances to
 * qualify" rule — the rule every real batting title in baseball/softball
 * actually uses. Without it, a player who went 2-for-2 in one game would
 * outrank your real season leader.
 *
 * WHAT THIS DOES:
 *   1. Calculates the league-wide minimum Plate Appearances (PA) required
 *      to qualify, using the classic formula:
 *
 *         MIN_PA = ( SUM of games played by every team in the league/season ) / DIVISOR
 *
 *      (DIVISOR defaults to 2, but you can configure it — MLB famously
 *       uses 3.1 PA per team game, for example. Adjust to your league's rule.)
 *
 *   2. Pulls every player in that league/season, reads their real PA and
 *      AVG (already calculated by SportPress — we don't recompute AVG,
 *      we just filter/sort it), and outputs only the ones who qualify.
 *
 * ----------------------------------------------------------------------------
 * IMPORTANT — READ BEFORE INSTALLING:
 * This was built and tested against a specific SportPress + SportPress Pro
 * installation. The data model below was confirmed by direct DB inspection,
 * but plugin versions and custom configurations vary. If something doesn't
 * work, see the "HOW TO ADAPT THIS TO YOUR SITE" section near the bottom.
 * ----------------------------------------------------------------------------
 *
 * KEY ASSUMPTIONS THIS CODE MAKES ABOUT YOUR DATA (verify these on your site!):
 *
 *   - `sp_team` on an `sp_event` post is stored as REPEATED POSTMETA
 *     (one row per team_id), NOT a serialized array. If your install stores
 *     it differently, the SQL query in lmos_get_team_games_played() below
 *     will return nothing and you'll need to adjust it.
 *
 *   - A game is considered "played" when `sp_results[$team_id]['outcome']`
 *     is set (win/loss/draw) — NOT based on the `sp_status` meta field.
 *     `sp_status` in our testing held values like ok/cancelled/future/
 *     postponed/publish/tbd, none of which reliably meant "has a final score".
 *
 *   - Player batting stats come from `(new SP_Player($id))->data($league_id)`,
 *     which returns an array keyed by season_id. Plate Appearances live in
 *     the `ap` key, batting average in `avg`. These keys come from SportPress'
 *     internal performance variable slugs — if you've renamed or added
 *     custom performance variables, your key might differ.
 *
 *   - Teams and players both carry `sp_league` and `sp_season` as normal
 *     taxonomies, so a tax_query can filter them directly.
 *
 * HOW TO VERIFY THESE ASSUMPTIONS ON YOUR OWN SITE (via WP-CLI / SSH):
 *
 *   # 1. Check how sp_team is stored on an event:
 *   wp eval 'print_r(get_post_meta(EVENT_ID, "sp_team", false));'
 *
 *   # 2. Check what a finished game's results look like:
 *   wp eval 'print_r(get_post_meta(EVENT_ID, "sp_results", true));'
 *
 *   # 3. Check a player's data array to find your PA/AVG keys:
 *   wp eval '$p = new SP_Player(PLAYER_ID); print_r($p->data(LEAGUE_ID));'
 *
 *   Replace EVENT_ID / PLAYER_ID / LEAGUE_ID with real IDs from your site.
 *
 * ----------------------------------------------------------------------------
 * USAGE:
 *   [sp_qualified_avg_leaders league="b" season="2026"]
 *   [sp_qualified_avg_leaders league="b" season="2026" limit="10" divisor="2"]
 *
 * INSTALLATION:
 *   Use a snippet plugin like WPCode (recommended) or your child theme's
 *   functions.php. Do NOT edit SportPress' own plugin files.
 * ============================================================================
 */

if ( ! function_exists( 'spql_get_team_games_played' ) ) {

	/**
	 * Counts completed games for a team within a league/season.
	 * "Completed" = has an 'outcome' key set in sp_results for that team.
	 */
	function spql_get_team_games_played( $team_id, $league_id, $season_id ) {

		$cache_key = "spql_jj_{$team_id}_{$league_id}_{$season_id}";
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		// ADAPT HERE if your sp_team is stored differently (see notes above).
		$event_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = 'sp_team'
				 AND pm.meta_value = %s
				 AND p.post_type = 'sp_event'
				 AND p.post_status = 'publish'",
				$team_id
			)
		);

		if ( empty( $event_ids ) ) {
			set_transient( $cache_key, 0, HOUR_IN_SECONDS );
			return 0;
		}

		$valid_event_ids = array();
		foreach ( $event_ids as $event_id ) {
			$leagues = wp_get_post_terms( $event_id, 'sp_league', array( 'fields' => 'ids' ) );
			$seasons = wp_get_post_terms( $event_id, 'sp_season', array( 'fields' => 'ids' ) );

			if ( in_array( (int) $league_id, $leagues, true ) && in_array( (int) $season_id, $seasons, true ) ) {
				$valid_event_ids[] = $event_id;
			}
		}

		// ADAPT HERE if your "game played" indicator isn't sp_results[$team_id]['outcome'].
		$games_played = 0;
		foreach ( $valid_event_ids as $event_id ) {
			$results = get_post_meta( $event_id, 'sp_results', true );
			if ( is_array( $results ) && isset( $results[ $team_id ]['outcome'] ) && ! empty( $results[ $team_id ]['outcome'] ) ) {
				$games_played++;
			}
		}

		set_transient( $cache_key, $games_played, HOUR_IN_SECONDS );

		return $games_played;
	}
}

if ( ! function_exists( 'spql_get_min_pa_for_league' ) ) {

	/**
	 * MIN_PA = SUM(games played by every team in the league/season) / divisor
	 */
	function spql_get_min_pa_for_league( $league_id, $season_id, $divisor = 2 ) {

		$cache_key = "spql_min_pa_{$league_id}_{$season_id}_{$divisor}";
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$teams = get_posts(
			array(
				'post_type'      => 'sp_team',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					'relation' => 'AND',
					array(
						'taxonomy' => 'sp_league',
						'field'    => 'term_id',
						'terms'    => $league_id,
					),
					array(
						'taxonomy' => 'sp_season',
						'field'    => 'term_id',
						'terms'    => $season_id,
					),
				),
			)
		);

		$total_jj  = 0;
		$num_teams = count( $teams );
		foreach ( $teams as $team_id ) {
			$total_jj += spql_get_team_games_played( $team_id, $league_id, $season_id );
		}

		// MIN_PA = (average games played per team) x divisor
		// Using the AVERAGE (not the raw total) is what makes this work
		// correctly regardless of how many teams a league/season has.
		// A naive "total / divisor" coincidentally matches this for
		// 4-team leagues but breaks for any other team count.
		$num_teams = max( 1, $num_teams );
		$min_pa    = (int) floor( ( $total_jj / $num_teams ) * max( 0.01, (float) $divisor ) );

		set_transient( $cache_key, $min_pa, 6 * HOUR_IN_SECONDS );

		return $min_pa;
	}
}

if ( ! function_exists( 'spql_qualified_avg_leaders_shortcode' ) ) {

	function spql_qualified_avg_leaders_shortcode( $atts ) {

		$atts = shortcode_atts(
			array(
				'league'  => '',
				'season'  => '',
				'limit'   => 5,
				'divisor' => 2, // change to 3.1 for an MLB-style qualifying rule, etc.
				'title'   => 'BATTING AVERAGE',
			),
			$atts,
			'sp_qualified_avg_leaders'
		);

		$league_term = get_term_by( 'slug', sanitize_title( $atts['league'] ), 'sp_league' );
		$season_term = get_term_by( 'slug', sanitize_title( $atts['season'] ), 'sp_season' );

		if ( ! $league_term || ! $season_term ) {
			return '<p>SportPress Qualified Leaders: invalid league or season slug.</p>';
		}

		$league_id = $league_term->term_id;
		$season_id = $season_term->term_id;
		$limit     = (int) $atts['limit'];
		$divisor   = (float) $atts['divisor'];

		$min_pa = spql_get_min_pa_for_league( $league_id, $season_id, $divisor );

		$player_ids = get_posts(
			array(
				'post_type'      => 'sp_player',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					'relation' => 'AND',
					array(
						'taxonomy' => 'sp_league',
						'field'    => 'term_id',
						'terms'    => $league_id,
					),
					array(
						'taxonomy' => 'sp_season',
						'field'    => 'term_id',
						'terms'    => $season_id,
					),
				),
			)
		);

		$qualified = array();

		foreach ( $player_ids as $player_id ) {

			if ( ! class_exists( 'SP_Player' ) ) {
				continue;
			}

			$player     = new SP_Player( $player_id );
			$data       = $player->data( $league_id );
			$season_row = isset( $data[ $season_id ] ) ? $data[ $season_id ] : null;

			if ( ! $season_row ) {
				continue;
			}

			// ADAPT HERE if your PA/AVG keys are different (see notes above).
			$pa  = isset( $season_row['ap'] ) ? (int) $season_row['ap'] : 0;
			$avg = isset( $season_row['avg'] ) ? (float) $season_row['avg'] : 0;

			if ( $pa >= $min_pa && $pa > 0 ) {
				$qualified[] = array(
					'name'  => get_the_title( $player_id ),
					'avg'   => $avg,
					'pa'    => $pa,
					'photo' => get_the_post_thumbnail_url( $player_id, 'thumbnail' ),
					'link'  => get_permalink( $player_id ),
				);
			}
		}

		usort(
			$qualified,
			function ( $a, $b ) {
				return $b['avg'] <=> $a['avg'];
			}
		);

		$top = array_slice( $qualified, 0, $limit );

		if ( empty( $top ) ) {
			return '<p>No players met the minimum of ' . esc_html( $min_pa ) . ' PA in this league/season.</p>';
		}

		ob_start();
		?>
		<div class="sp-qualified-leaders">
			<table class="sp-data-table widefat" style="border-collapse:collapse;width:100%;">
				<thead>
					<tr>
						<th colspan="3" style="text-align:center;padding:10px 12px;">
							<?php echo esc_html( $atts['title'] ); ?>
						</th>
					</tr>
					<tr>
						<th colspan="2" style="text-align:left;padding:6px 12px;">Player</th>
						<th style="text-align:right;padding:6px 12px;">AVG</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $top as $i => $row ) : ?>
						<tr style="background:<?php echo $i % 2 === 0 ? '#f2f2f2' : '#fff'; ?>;">
							<td style="padding:4px 6px;">
								<?php if ( $row['photo'] ) : ?>
									<img src="<?php echo esc_url( $row['photo'] ); ?>" style="width:30px;height:30px;object-fit:cover;display:block;" alt="<?php echo esc_attr( $row['name'] ); ?>">
								<?php endif; ?>
							</td>
							<td style="padding:4px 8px;">
								<a href="<?php echo esc_url( $row['link'] ); ?>" style="font-weight:600;text-decoration:none;">
									<?php echo esc_html( strtoupper( $row['name'] ) ); ?>
								</a>
							</td>
							<td style="text-align:right;padding:4px 12px;font-weight:700;">
								<?php echo esc_html( number_format( $row['avg'], 3 ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p style="font-size:11px;color:#888;margin-top:4px;">
				*Minimum <?php echo esc_html( $min_pa ); ?> plate appearances (PA) to qualify.
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	add_shortcode( 'sp_qualified_avg_leaders', 'spql_qualified_avg_leaders_shortcode' );
}

/**
 * Clears the cached game counts / minimum PA. Runs automatically whenever
 * an event is saved/updated, so results refresh after each game day.
 */
if ( ! function_exists( 'spql_clear_leaders_cache' ) ) {

	function spql_clear_leaders_cache() {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_spql_jj_%'
			 OR option_name LIKE '_transient_spql_min_pa_%'
			 OR option_name LIKE '_transient_timeout_spql_jj_%'
			 OR option_name LIKE '_transient_timeout_spql_min_pa_%'"
		);
	}

	add_action( 'save_post_sp_event', 'spql_clear_leaders_cache' );
}
