<?php
/**
 * Plugin Name: Campaign Link Inspector
 * Description: Inspect campaign links in WordPress posts and pages without visitor tracking or external requests.
 * Version: 0.3.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: LW IT Solutions
 * Author URI: https://www.lukaswojcik.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: campaign-link-inspector
 *
 * @package CampaignLinkInspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
define( 'CPLI_VERSION', '0.3.0' );
require_once __DIR__ . '/includes/class-cpli-inspector.php';

add_action( 'admin_menu', 'cpli_admin_menu' );
add_action( 'admin_enqueue_scripts', 'cpli_admin_assets' );
add_action( 'wp_ajax_cpli_scan', 'cpli_scan' );

/** Register a scoped tools screen. */
function cpli_admin_menu() {
	add_management_page( __( 'Campaign Link Inspector', 'campaign-link-inspector' ), __( 'Campaign Links', 'campaign-link-inspector' ), 'manage_options', 'campaign-link-inspector', 'cpli_admin_page' );
}

/** Load local assets only on our screen.
 * @param string $hook Current screen.
 */
function cpli_admin_assets( $hook ) {
	if ( 'tools_page_campaign-link-inspector' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'cpli-admin', plugins_url( 'assets/admin.css', __FILE__ ), array( 'dashicons' ), CPLI_VERSION );
	wp_enqueue_script( 'cpli-admin', plugins_url( 'assets/admin.js', __FILE__ ), array(), CPLI_VERSION, true );
	wp_localize_script(
		'cpli-admin',
		'CPLI',
		array(
			'url'    => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'cpli_scan' ),
			'labels' => CPLI_Inspector::labels(),
			'kinds'  => CPLI_Inspector::kinds(),
			'text'   => array(
				'loading'     => __( 'Reading the next batch…', 'campaign-link-inspector' ),
				'error'       => __( 'The scan failed. Please retry or reload the page.', 'campaign-link-inspector' ),
				'empty'       => __( 'No findings in this batch. This is not proof that every link is correct.', 'campaign-link-inspector' ),
				'done'        => __( 'End of the content list.', 'campaign-link-inspector' ),
				'more'        => __( 'More content is available. Continue with the next batch.', 'campaign-link-inspector' ),
				'edit'        => __( 'Edit content', 'campaign-link-inspector' ),
				'posts'       => __( 'Posts/pages inspected', 'campaign-link-inspector' ),
				'links'       => __( 'Links inspected', 'campaign-link-inspector' ),
				'limits'      => __( 'Incomplete content scans', 'campaign-link-inspector' ),
				'page'        => __( 'Batch', 'campaign-link-inspector' ),
				/* translators: 1: number of the first content item in the batch, 2: number of the last one. */
				'range'       => __( 'Items %1$d–%2$d', 'campaign-link-inspector' ),
				'findings'    => __( 'Links with findings', 'campaign-link-inspector' ),
				'all'         => __( 'Show all findings', 'campaign-link-inspector' ),
				/* translators: %s: short name of a check. */
				'filtered'    => __( 'Showing only: %s.', 'campaign-link-inspector' ),
				/* translators: %d: position of the anchor in the content item. */
				'position'    => __( 'Link #%d', 'campaign-link-inspector' ),
				'whole'       => __( 'Whole item', 'campaign-link-inspector' ),
				'colContent'  => __( 'Content', 'campaign-link-inspector' ),
				'colPosition' => __( 'Link position', 'campaign-link-inspector' ),
				'colUrl'      => __( 'URL (query values omitted)', 'campaign-link-inspector' ),
				'colFinding'  => __( 'Finding', 'campaign-link-inspector' ),
				'kindCheck'   => __( 'Likely broken', 'campaign-link-inspector' ),
				'kindReview'  => __( 'Naming to review', 'campaign-link-inspector' ),
				'kindHint'    => __( 'Recommendation or scope', 'campaign-link-inspector' ),
				'kindClean'   => __( 'Without findings', 'campaign-link-inspector' ),
				/* translators: 1: number of links, 2: share in percent, 3: group name such as "Likely broken". */
				'ringPart'    => __( '%3$s: %1$d (%2$d %%)', 'campaign-link-inspector' ),
				/* translators: %d: share of links without findings in percent. */
				'ringCenter'  => __( '%d %% without findings', 'campaign-link-inspector' ),
				'ringClean'   => __( 'clean', 'campaign-link-inspector' ),
				'ringFilter'  => __( 'Select a colored part to show only its rows.', 'campaign-link-inspector' ),
				/* translators: %d: number of matching rows. */
				'matches'     => __( 'Matching rows: %d', 'campaign-link-inspector' ),
				'noMatch'     => __( 'No row matches the filter.', 'campaign-link-inspector' ),
				'fieldOk'     => __( 'set', 'campaign-link-inspector' ),
				'fieldEmpty'  => __( 'empty', 'campaign-link-inspector' ),
				'fieldMiss'   => __( 'missing', 'campaign-link-inspector' ),
				'fieldIssue'  => __( 'to review', 'campaign-link-inspector' ),
				'fieldsLabel' => __( 'UTM fields of this link', 'campaign-link-inspector' ),
				/* translators: 1: batch number, 2: content items, 3: links, 4: links with findings. */
				'histBar'     => __( 'Batch %1$d (items: %2$d, links: %3$d, with findings: %4$d). Select to read this batch again.', 'campaign-link-inspector' ),
				/* translators: 1: number of batches, 2: content items, 3: links, 4: links with findings. */
				'histSum'     => __( 'Batches read on this page: %1$d (items: %2$d, links: %3$d, with findings: %4$d).', 'campaign-link-inspector' ),
				'histEnd'     => __( 'End of the content list reached.', 'campaign-link-inspector' ),
				'clean'       => __( 'No findings in this batch.', 'campaign-link-inspector' ),
			),
		)
	);
}

/** Render the admin-only read-only workflow. */
function cpli_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$cpli_facts = array(
		'lock'     => __( 'Read-only: links are never changed', 'campaign-link-inspector' ),
		'shield'   => __( 'No external requests or tracking', 'campaign-link-inspector' ),
		'database' => __( 'Results are not saved', 'campaign-link-inspector' ),
	);
	?>
	<div class="wrap cpli-wrap">
		<h1><?php esc_html_e( 'Campaign Link Inspector', 'campaign-link-inspector' ); ?></h1>
		<p class="cpli-lead"><?php esc_html_e( 'Find questionable campaign links in stored posts and pages. Findings include the content item and link position; this plugin never changes links.', 'campaign-link-inspector' ); ?></p>
		<ul class="cpli-facts">
			<?php foreach ( $cpli_facts as $cpli_icon => $cpli_fact ) : ?>
				<li><span class="dashicons dashicons-<?php echo esc_attr( $cpli_icon ); ?>" aria-hidden="true"></span><?php echo esc_html( $cpli_fact ); ?></li>
			<?php endforeach; ?>
		</ul>
		<details class="cpli-scope">
			<summary><?php esc_html_e( 'Scope, limits and privacy', 'campaign-link-inspector' ); ?></summary>
			<p><?php esc_html_e( 'Runs on this WordPress installation. No visitor tracking, external URL requests, account or API key. Results are not saved by the plugin.', 'campaign-link-inspector' ); ?></p>
			<p><?php esc_html_e( 'Scope: saved anchor links in posts and pages, including drafts and private content that this administrator can edit. Dynamic blocks, shortcodes, menus, reusable block references and page-builder metadata are not rendered or inspected. Content changes during a scan can change the batch order.', 'campaign-link-inspector' ); ?></p>
			<p><?php esc_html_e( 'Each batch inspects up to 10 content items. Per item: at most 2 MB of content, 5,000 anchor tags and 100 reported findings. Any incomplete scan is explicitly reported. Query values, credentials and fragments are omitted from report URLs; paths and titles can still contain sensitive information.', 'campaign-link-inspector' ); ?></p>
		</details>
		<div class="cpli-actions">
			<button type="button" class="button button-primary" id="cpli-start"><span class="dashicons dashicons-search" aria-hidden="true"></span><?php esc_html_e( 'Start / restart scan', 'campaign-link-inspector' ); ?></button>
			<button type="button" class="button" id="cpli-next" disabled><?php esc_html_e( 'Next batch', 'campaign-link-inspector' ); ?><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></button>
			<button type="button" class="button" id="cpli-export" disabled><span class="dashicons dashicons-download" aria-hidden="true"></span><?php esc_html_e( 'Export this batch as CSV', 'campaign-link-inspector' ); ?></button>
			<span class="spinner" id="cpli-spinner"></span>
		</div>
		<div id="cpli-status" class="cpli-status" role="status" aria-live="polite"></div>
		<div class="cpli-empty" id="cpli-empty">
			<svg class="cpli-illu" viewBox="0 0 160 110" width="160" height="110" aria-hidden="true" focusable="false">
				<rect class="cpli-illu-page" x="22" y="8" width="84" height="94" rx="6"/>
				<rect class="cpli-illu-line" x="34" y="24" width="54" height="6" rx="3"/>
				<rect class="cpli-illu-line" x="34" y="38" width="40" height="6" rx="3"/>
				<rect class="cpli-illu-link" x="34" y="52" width="58" height="6" rx="3"/>
				<rect class="cpli-illu-line" x="34" y="66" width="48" height="6" rx="3"/>
				<rect class="cpli-illu-link" x="34" y="80" width="30" height="6" rx="3"/>
				<circle class="cpli-illu-lens" cx="104" cy="58" r="22"/>
				<path class="cpli-illu-handle" d="M120 74 L140 94"/>
				<path class="cpli-illu-check" d="M94 58 l7 7 l13 -14"/>
			</svg>
			<h2><?php esc_html_e( 'No scan yet', 'campaign-link-inspector' ); ?></h2>
			<p><?php esc_html_e( 'Start the scan to read the first 10 posts and pages. Each batch lists every link with a finding, a short label for the check and an edit link. Continue batch by batch until the end of the content list.', 'campaign-link-inspector' ); ?></p>
		</div>
		<section class="cpli-report" id="cpli-report" hidden aria-labelledby="cpli-report-title">
			<h2 id="cpli-report-title" class="screen-reader-text"><?php esc_html_e( 'Scan report', 'campaign-link-inspector' ); ?></h2>
			<dl class="cpli-tiles" id="cpli-tiles"></dl>
			<div class="cpli-success" id="cpli-success" hidden>
				<svg viewBox="0 0 52 52" width="44" height="44" aria-hidden="true" focusable="false"><circle cx="26" cy="26" r="23"/><path d="M15 27 l8 8 l15 -16"/></svg>
				<p id="cpli-success-text"></p>
			</div>
			<div class="cpli-overview">
			<div class="cpli-ring-card" id="cpli-ring-card" hidden>
				<h3><?php esc_html_e( 'Links in this batch', 'campaign-link-inspector' ); ?></h3>
				<p class="description" id="cpli-ring-help"></p>
				<div class="cpli-ring-body">
					<div class="cpli-ring" id="cpli-ring"></div>
					<ul class="cpli-ring-legend" id="cpli-ring-legend"></ul>
				</div>
			</div>
			<div class="cpli-chart" id="cpli-chart" hidden>
				<h3><?php esc_html_e( 'Findings by check', 'campaign-link-inspector' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Select a check to show only its rows.', 'campaign-link-inspector' ); ?></p>
				<ul class="cpli-bars" id="cpli-bars"></ul>
				<ul class="cpli-legend">
					<li class="is-check"><?php esc_html_e( 'Likely broken', 'campaign-link-inspector' ); ?></li>
					<li class="is-review"><?php esc_html_e( 'Naming to review', 'campaign-link-inspector' ); ?></li>
					<li class="is-hint"><?php esc_html_e( 'Recommendation or scope', 'campaign-link-inspector' ); ?></li>
				</ul>
				<p class="cpli-filter-line" id="cpli-filter-line" hidden><span id="cpli-filter-text"></span> <button type="button" class="button-link" id="cpli-filter-reset"><?php esc_html_e( 'Show all findings', 'campaign-link-inspector' ); ?></button></p>
			</div>
			</div>
			<div class="cpli-history" id="cpli-history" hidden>
				<h3><?php esc_html_e( 'Scan progress', 'campaign-link-inspector' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Each column is one batch read on this page, colored by findings. Nothing is saved; reloading the page starts over.', 'campaign-link-inspector' ); ?></p>
				<ol class="cpli-history-bars" id="cpli-history-bars"></ol>
				<p class="cpli-history-sum" id="cpli-history-sum"></p>
			</div>
			<div class="cpli-tools" id="cpli-tools" hidden>
				<label class="cpli-search">
					<span class="screen-reader-text"><?php esc_html_e( 'Filter the findings by title or address', 'campaign-link-inspector' ); ?></span>
					<span class="dashicons dashicons-filter" aria-hidden="true"></span>
					<input type="search" id="cpli-search" placeholder="<?php esc_attr_e( 'Filter by title or address', 'campaign-link-inspector' ); ?>" aria-describedby="cpli-search-hint" autocomplete="off">
					<kbd aria-hidden="true">/</kbd>
				</label>
				<span class="screen-reader-text" id="cpli-search-hint"><?php esc_html_e( 'Press the slash key to jump to this field.', 'campaign-link-inspector' ); ?></span>
				<div class="cpli-kinds" id="cpli-kinds" role="group" aria-label="<?php esc_attr_e( 'Show findings of one group', 'campaign-link-inspector' ); ?>"></div>
				<p class="cpli-matches" id="cpli-matches" role="status" aria-live="polite"></p>
			</div>
			<div class="cpli-table" id="cpli-table" hidden>
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Content', 'campaign-link-inspector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Link position', 'campaign-link-inspector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'URL (query values omitted)', 'campaign-link-inspector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Finding', 'campaign-link-inspector' ); ?></th>
					</tr></thead>
					<tbody id="cpli-results"></tbody>
				</table>
			</div>
		</section>
		<p class="cpli-note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php esc_html_e( 'Interpretation: missing campaign fields are recommendations, internal UTM links need review, and naming differences may be intentional. This tool does not prove attribution, Google Analytics delivery or legal compliance.', 'campaign-link-inspector' ); ?></p>
	</div>
	<?php
}

/** Return one bounded batch. Nonces never substitute for permissions. */
function cpli_scan() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Administrator permission required.', 'campaign-link-inspector' ) ), 403 );
	}
	check_ajax_referer( 'cpli_scan', 'nonce' );
	$page = isset( $_POST['batch'] ) && is_scalar( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 1;
	if ( $page < 1 || $page > 1000000 ) {
		wp_send_json_error( array( 'message' => __( 'Invalid batch number.', 'campaign-link-inspector' ) ), 400 );
	}
	$query = new WP_Query(
		array(
			'post_type'              => array( 'post', 'page' ),
			'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page'         => 11,
			'offset'                 => ( $page - 1 ) * 10,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'lang'                   => '',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);
	$rows   = array();
	$links  = 0;
	$count  = 0;
	$limits = 0;
	foreach ( array_slice( $query->posts, 0, 10 ) as $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			continue;
		}
		++$count;
		$result = CPLI_Inspector::content( $post->post_content, home_url( '/' ) );
		$links += $result['links'];
		$limits += $result['limited'] ? 1 : 0;
		foreach ( $result['rows'] as $row ) {
			$row['post_id'] = $post->ID;
			$row['title']   = $post->post_title;
			$row['edit']    = get_edit_post_link( $post->ID, 'raw' );
			$rows[]        = $row;
		}
	}
	wp_send_json_success(
		array(
			'batch'   => $page,
			'posts'   => $count,
			'links'   => $links,
			'limited' => $limits,
			'more'    => count( $query->posts ) > 10,
			'rows'    => $rows,
		)
	);
}
