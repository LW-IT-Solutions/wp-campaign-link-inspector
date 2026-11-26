<?php
/**
 * Plugin Name: Campaign Link Inspector
 * Description: Inspect campaign links in WordPress posts and pages without visitor tracking or external requests.
 * Version: 0.2.0
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
define( 'CPLI_VERSION', '0.2.0' );
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
			<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'No scan yet', 'campaign-link-inspector' ); ?></h2>
			<p><?php esc_html_e( 'Start the scan to read the first 10 posts and pages. Each batch lists every link with a finding, a short label for the check and an edit link. Continue batch by batch until the end of the content list.', 'campaign-link-inspector' ); ?></p>
		</div>
		<section class="cpli-report" id="cpli-report" hidden aria-labelledby="cpli-report-title">
			<h2 id="cpli-report-title" class="screen-reader-text"><?php esc_html_e( 'Scan report', 'campaign-link-inspector' ); ?></h2>
			<dl class="cpli-tiles" id="cpli-tiles"></dl>
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
