# SportPress — Qualified Average Leaders

A free WordPress/SportPress shortcode that adds a **minimum plate appearances (PA) to qualify** rule to batting average leaderboards.

Developed by [Edoy.Net](https://edoy.net) - 2026 - V1.1

## The problem

SportPress' built-in leaderboard tables can sort players by AVG, HITS, etc., but they have no way to apply a "minimum plate appearances to qualify" rule — the rule every real batting title (MLB, amateur leagues, etc.) actually uses.

Without it, a player who went 2-for-2 in a single game can outrank your real season leader, which doesn't reflect how batting titles are awarded in real baseball/softball.

## What this does

1. Calculates the league-wide minimum PA required to qualify, based on the **average** number of games played per team (not the raw total — this matters, see note below):

   ```
   MIN_PA = ( AVERAGE games played per team in the league/season ) × RATE
   ```

   `RATE` defaults to `2`, but it's a shortcode parameter (`divisor`) — use whatever your league's rule is (e.g. MLB uses roughly `3.1` PA per team game scheduled).

   > **Why average, not total:** an earlier version of this code used `SUM(all teams' games) / divisor`. That happens to produce the same result as the average-based formula *only* when a league has exactly 4 teams — for any other number of teams, it silently produces a wrong (inflated) minimum. Always use the average-per-team version below.

2. Pulls every player in that league/season, reads their real PA and AVG (already calculated by SportPress — this doesn't recompute AVG, it only filters/sorts it).

3. Outputs a simple styled table showing only qualified players, sorted by AVG.

## Usage

```
[sp_qualified_avg_leaders league="b" season="2026"]
[sp_qualified_avg_leaders league="b" season="2026" limit="10" divisor="3.1" title="BATTING AVERAGE"]
```

| Parameter | Default           | Description                                                        |
| --------- | ------------------ | -------------------------------------------------------------------|
| `league`  | *(required)*       | Slug of the `sp_league` term                                       |
| `season`  | *(required)*       | Slug of the `sp_season` term                                       |
| `limit`   | `5`                 | Number of players to show                                          |
| `divisor` | `2`                 | Multiplier applied to the average games-per-team for the PA minimum |
| `title`   | `BATTING AVERAGE`  | Header text shown above the table                                  |

## Installation

1. Install a code snippet plugin like [WPCode](https://wordpress.org/plugins/insert-headers-and-footers/) (recommended), or paste the code into your **child theme's** `functions.php`. Do **not** edit SportPress' own plugin files.
2. Copy the full contents of [`sportspress-qualified-avg-leaders.php`](https://github.com/edoynet/sportspress-qualified-avg-leaders/blob/main/sportspress-qualified-avg-leaders.php) into a new PHP snippet.
3. Activate the snippet.
4. Add the shortcode to any page/widget, using your own league/season slugs.

## ⚠️ Before you use this — verify these assumptions on YOUR site

This was built and tested against one specific SportPress + SportPress Pro installation, with the underlying data structures confirmed by **direct database inspection** (not just documentation). Plugin versions and custom configurations vary, so please verify the following on your own install before relying on this:

### 1. How `sp_team` is stored on event posts

This code assumes `sp_team` is stored as **repeated postmeta** (one row per team ID), not a serialized array.

```
wp eval 'print_r(get_post_meta(EVENT_ID, "sp_team", false));'
```

If this returns a single serialized array instead of multiple plain values, you'll need to adjust the SQL query in `spql_get_team_games_played()`.

### 2. What actually means "this game has been played"

This code assumes a game is "completed" when `sp_results[$team_id]['outcome']` is set (`win`/`loss`/`draw`) — **not** based on the `sp_status` meta field. In testing, `sp_status` held values like `ok` / `cancelled` / `future` / `postponed` / `publish` / `tbd`, none of which reliably meant "has a final score."

```
wp eval 'print_r(get_post_meta(EVENT_ID, "sp_results", true));'
wp eval 'print_r(get_post_meta(EVENT_ID, "sp_status", true));'
```

### 3. The exact meta keys for PA and AVG

This code assumes `(new SP_Player($id))->data($league_id)` returns an array keyed by `season_id`, with Plate Appearances in the `ap` key and batting average in the `avg` key.

```
wp eval '$p = new SP_Player(PLAYER_ID); print_r($p->data(LEAGUE_ID));'
```

If your performance variables have different slugs (custom columns, renamed fields, etc.), update the `$pa` / `$avg` lines inside `spql_qualified_avg_leaders_shortcode()` accordingly.

### 4. Teams and players both carry `sp_league` / `sp_season` as taxonomies

This is used directly in `tax_query` calls. Run this to confirm:

```
wp eval 'print_r(wp_get_post_terms(TEAM_OR_PLAYER_ID, "sp_league")); print_r(wp_get_post_terms(TEAM_OR_PLAYER_ID, "sp_season"));'
```

## Performance note

The first load after activating (or after the cache expires) recalculates games played by iterating through events — for leagues with thousands of games, this can take a few seconds. Results are cached via WordPress transients (1 hour for per-team game counts, 6 hours for the league-wide minimum) and automatically cleared whenever an event is saved, so subsequent loads are fast.

## Changelog

- **V1.1** — Fixed minimum-PA calculation to use the *average* games played per team instead of the raw total. The old formula only produced correct results for leagues with exactly 4 teams; any other team count silently inflated the minimum.
- **V1.0** — Initial release.

## License

MIT — use it, modify it, share it. Attribution appreciated but not required.

## Feedback

Built against one real-world league's data. If you try this on a different SportPress setup and something doesn't line up, please open an issue — that's valuable feedback for making this more broadly compatible.
