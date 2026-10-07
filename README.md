# Community Idea Voting

A standalone WordPress plugin for anonymous pairwise idea voting, moderated community submissions, ranked results, and historical result snapshots. It does not require or integrate with `wordpress-admin-ui`.

## Requirements and installation

- WordPress 6.0 or newer
- PHP 7.4 or newer
- MySQL/MariaDB with InnoDB support

Copy this repository into `wp-content/plugins/community-idea-voting`, activate **Community Idea Voting** in WordPress, and create an open survey from **Community voting** in the dashboard. Add at least two approved ideas before opening the public voting page. The plugin creates versioned, prefixed tables on activation and upgrades them when the plugin loads. Deactivation and uninstall do not delete poll data.

The standalone URLs use WordPress pretty permalinks:

- `/community-ideas/{survey-id}/` — voting page for an open survey
- `/community-ideas/{survey-id}/results/` — separate results page

If rewrite URLs are unavailable on a host, embed `[civ_voting survey="1"]` and `[civ_results survey="1"]` in normal WordPress pages instead. The Gutenberg **Community idea voting** block accepts a survey ID and renders the same voting experience.

## Anonymous voting and privacy

The plugin issues a random, first-party, HTTP-only visitor cookie to bind a matchup to the browser. The database stores only an HMAC-derived session reference, not the raw cookie or IP address. The cookie expires after one year. Votes, submissions, moderation history, and imported results remain until the site administrator removes the plugin data; deactivation and uninstall do not remove it. A visitor may vote once or skip once for each server-issued matchup; a retry of the identical response is idempotent and does not add another record. Skipped matchups do not affect scores. Anonymous voting cannot establish that a person has only one browser or make a poll representative.

Administrators should review the site's privacy policy and explain the cookie and anonymous vote records to visitors. Idea submissions are stored as pending and are not included in voting or results until an administrator approves them. Administrators can close a survey and deactivate or restore approved ideas. Historical moderation actions are retained in the audit log.

## Results and scoring

Live scores use a regularized Bradley–Terry-style pairwise model. Each idea's displayed score estimates its probability of beating a randomly selected active idea, averaged over the other active ideas. A neutral zero-rating prior leaves ideas with no comparisons tied at 50%; regularization limits extreme scores from sparse data. Results display per-idea comparison counts and flag ideas with fewer than five comparisons. The score is descriptive of this survey's recorded choices, not a respondent-level or representative estimate.

Results are fetched on a separate view rather than shown on the voting cards. Imported result snapshots are displayed in their own historical section and never mixed into live scores. Skips are not included in the pairwise model.

## Historical CSV import

The admin import accepts UTF-8 CSV up to 5 MB and 5,000 rows, with these exact column names (extra columns are ignored):

```csv
survey_title,question,idea,idea_status,score,comparison_count,source,source_timestamp
Let's Roll Ideas & Feedback,What should we improve?,Example idea,approved,0.72,14,legacy survey export,2025-02-10 12:30:00
```

- `survey_title`, `question`, and `idea` are required text.
- `idea_status` must be `approved`, `active`, `pending`, or `rejected`. Approved/active ideas are retained as approved seed ideas in the imported (closed) survey; pending/rejected rows remain non-votable.
- `score` is an optional probability from `0` to `1`. Convert source scores to this scale only after validating the source's meaning.
- `comparison_count` is an optional non-negative integer.
- `source` identifies the export/source system.
- `source_timestamp` is optional and should use UTC `YYYY-MM-DD` or `YYYY-MM-DD HH:MM:SS` (convert timestamps from another timezone before import).

Rows with the same survey title and question are grouped into a closed, read-only historical survey. Each row is retained with its approval state, import time, and source provenance. Unapproved historical rows are hidden from public results; approved aggregate snapshots are shown separately from live rankings. No pairwise votes are fabricated from aggregates. This import path handles idea-level aggregate snapshots, not raw pairwise records. The current hosted survey's export fields have not been supplied or validated against this canonical format; map and transform the actual export before importing it. If the source export contains raw comparison events and they are semantically validated, add a dedicated migration for them rather than interpreting them as aggregate rows.

The import form can also copy approved/active ideas into a selected open survey. Existing active or pending ideas with the same text are not duplicated; pending ideas are not auto-approved.

## Administration and export

The dashboard supports creating/editing/closing surveys, adding approved seed ideas, approving/rejecting pending suggestions, and deactivating/restoring ideas. **Export all survey data** downloads a CSV containing surveys, ideas, raw matchup responses, historical snapshots, and moderation audit events. It omits visitor cookies, session hashes, and IP addresses; text values that could be interpreted as spreadsheet formulas are prefixed with an apostrophe for safety.

The public REST namespace is `community-idea-voting/v1`; its public endpoints only expose open matchups, recorded responses, idea submission, and results for a specified survey. Write requests verify same-origin browser headers. Administrative operations require the `manage_options` capability and WordPress nonces.

## Verification

- Run the standalone ranking fixtures with `php tests/test-scoring.php`.
- Run PHP syntax checks on plugin PHP files with `php -l`.
- Run JavaScript syntax checks with `node --check assets/voting.js` and `node --check assets/block-editor.js`.
- On a WordPress test site, smoke-test plugin activation/schema creation and upgrades, public REST voting and skip flows, session mismatch and replay rejection, pending moderation gating, CSV import/export, rewrite URLs, shortcode/block rendering, mobile layout, and keyboard operation.

The ranking fixtures exercise sparse-data neutral priors, ordering, comparison counts, and low-data flags. The WordPress integration checklist remains necessary for database transactions, REST behavior, editor rendering, and host-specific rewrite setup.
