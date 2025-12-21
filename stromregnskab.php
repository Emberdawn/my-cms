<?php
/**
 * Plugin Name: Strømregnskab for Forening
 * Description: Håndterer strømregnskab for beboere med indberetninger, priser og beregninger.
 * Version: 0.1.0
 * Author: Foreningen
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package Stromregnskab
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SR_PLUGIN_VERSION', '0.1.0' );
define( 'SR_PLUGIN_SLUG', 'stromregnskab' );
define( 'SR_CAPABILITY_ADMIN', 'manage_options' );
define( 'SR_CAPABILITY_RESIDENT', 'submit_energy_reports' );

/**
 * Activate plugin and create tables.
 */
function sr_activate_plugin() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	$table_residents = $wpdb->prefix . 'sr_residents';
	$table_readings  = $wpdb->prefix . 'sr_meter_readings';
	$table_payments  = $wpdb->prefix . 'sr_payments';
	$table_prices    = $wpdb->prefix . 'sr_prices';
	$table_locks     = $wpdb->prefix . 'sr_period_locks';
	$table_logs      = $wpdb->prefix . 'sr_audit_logs';
	$table_summary   = $wpdb->prefix . 'sr_monthly_summary';

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE {$table_residents} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(190) NOT NULL,
		member_number varchar(50) NOT NULL,
		wp_user_id bigint(20) unsigned DEFAULT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY member_number (member_number),
		KEY wp_user_id (wp_user_id)
	) {$charset_collate};

	CREATE TABLE {$table_readings} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		resident_id bigint(20) unsigned NOT NULL,
		period_month tinyint(2) unsigned NOT NULL,
		period_year smallint(4) unsigned NOT NULL,
		reading_kwh decimal(12,3) NOT NULL,
		status varchar(20) NOT NULL DEFAULT 'pending',
		submitted_by bigint(20) unsigned NOT NULL,
		submitted_at datetime NOT NULL,
		verified_by bigint(20) unsigned DEFAULT NULL,
		verified_at datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY resident_id (resident_id),
		KEY status (status)
	) {$charset_collate};

	CREATE TABLE {$table_payments} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		resident_id bigint(20) unsigned NOT NULL,
		period_month tinyint(2) unsigned NOT NULL,
		period_year smallint(4) unsigned NOT NULL,
		amount decimal(12,2) NOT NULL,
		status varchar(20) NOT NULL DEFAULT 'pending',
		submitted_by bigint(20) unsigned NOT NULL,
		submitted_at datetime NOT NULL,
		verified_by bigint(20) unsigned DEFAULT NULL,
		verified_at datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY resident_id (resident_id),
		KEY status (status)
	) {$charset_collate};

	CREATE TABLE {$table_prices} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		period_month tinyint(2) unsigned NOT NULL,
		period_year smallint(4) unsigned NOT NULL,
		price_per_kwh decimal(10,4) NOT NULL,
		created_by bigint(20) unsigned NOT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY period (period_month, period_year)
	) {$charset_collate};

	CREATE TABLE {$table_locks} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		period_month tinyint(2) unsigned NOT NULL,
		period_year smallint(4) unsigned NOT NULL,
		locked_by bigint(20) unsigned NOT NULL,
		locked_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY period (period_month, period_year)
	) {$charset_collate};

	CREATE TABLE {$table_summary} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		resident_id bigint(20) unsigned NOT NULL,
		period_month tinyint(2) unsigned NOT NULL,
		period_year smallint(4) unsigned NOT NULL,
		consumption_kwh decimal(12,3) NOT NULL,
		price_per_kwh decimal(10,4) NOT NULL,
		cost decimal(12,2) NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY resident_period (resident_id, period_month, period_year),
		KEY resident_id (resident_id)
	) {$charset_collate};

	CREATE TABLE {$table_logs} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		action varchar(100) NOT NULL,
		entity_type varchar(50) NOT NULL,
		entity_id bigint(20) unsigned DEFAULT NULL,
		changed_by bigint(20) unsigned NOT NULL,
		changed_at datetime NOT NULL,
		notes text,
		PRIMARY KEY  (id),
		KEY entity_type (entity_type)
	) {$charset_collate};";

	dbDelta( $sql );

	add_role(
		'resident',
		'Beboer',
		array(
			'read'                 => true,
			SR_CAPABILITY_RESIDENT => true,
		)
	);
}
register_activation_hook( __FILE__, 'sr_activate_plugin' );

/**
 * Add plugin menu items.
 */
function sr_register_admin_menu() {
	add_menu_page(
		'Strømregnskab',
		'Strømregnskab',
		SR_CAPABILITY_ADMIN,
		SR_PLUGIN_SLUG,
		'sr_render_admin_dashboard',
		'dashicons-chart-bar',
		26
	);
	add_submenu_page( SR_PLUGIN_SLUG, 'Beboere', 'Beboere', SR_CAPABILITY_ADMIN, SR_PLUGIN_SLUG . '-residents', 'sr_render_residents_page' );
	add_submenu_page( SR_PLUGIN_SLUG, 'Målerstande', 'Målerstande', SR_CAPABILITY_ADMIN, SR_PLUGIN_SLUG . '-readings', 'sr_render_readings_page' );
	add_submenu_page( SR_PLUGIN_SLUG, 'Indbetalinger', 'Indbetalinger', SR_CAPABILITY_ADMIN, SR_PLUGIN_SLUG . '-payments', 'sr_render_payments_page' );
	add_submenu_page( SR_PLUGIN_SLUG, 'Strømpriser', 'Strømpriser', SR_CAPABILITY_ADMIN, SR_PLUGIN_SLUG . '-prices', 'sr_render_prices_page' );
	add_submenu_page( SR_PLUGIN_SLUG, 'Periodelåsning', 'Periodelåsning', SR_CAPABILITY_ADMIN, SR_PLUGIN_SLUG . '-locks', 'sr_render_locks_page' );
	add_submenu_page( SR_PLUGIN_SLUG, 'CSV-eksport', 'CSV-eksport', SR_CAPABILITY_ADMIN, SR_PLUGIN_SLUG . '-export', 'sr_render_export_page' );
}
add_action( 'admin_menu', 'sr_register_admin_menu' );

/**
 * Get current datetime in MySQL format.
 *
 * @return string
 */
function sr_now() {
	return current_time( 'mysql' );
}

/**
 * Check if a period is locked.
 *
 * @param int $month Month.
 * @param int $year  Year.
 * @return bool
 */
function sr_is_period_locked( $month, $year ) {
	global $wpdb;
	$table_locks = $wpdb->prefix . 'sr_period_locks';

	$locked = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table_locks} WHERE period_month = %d AND period_year = %d",
			$month,
			$year
		)
	);

	return ! empty( $locked );
}

/**
 * Get price for a period with fallback to previous month.
 *
 * @param int $month Month.
 * @param int $year  Year.
 * @return float|null
 */
function sr_get_price_for_period( $month, $year ) {
	global $wpdb;
	$table_prices = $wpdb->prefix . 'sr_prices';

	$current_month = (int) $month;
	$current_year  = (int) $year;

	for ( $i = 0; $i < 24; $i++ ) {
		$price = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT price_per_kwh FROM {$table_prices} WHERE period_month = %d AND period_year = %d",
				$current_month,
				$current_year
			)
		);
		if ( null !== $price ) {
			return (float) $price;
		}

		$current_month--;
		if ( $current_month < 1 ) {
			$current_month = 12;
			$current_year--;
		}
	}

	return null;
}

/**
 * Insert audit log.
 *
 * @param string $action Action.
 * @param string $entity_type Entity type.
 * @param int    $entity_id Entity id.
 * @param string $notes Notes.
 */
function sr_log_action( $action, $entity_type, $entity_id, $notes = '' ) {
	global $wpdb;
	$table_logs = $wpdb->prefix . 'sr_audit_logs';
	$entity_id  = $entity_id ? (int) $entity_id : 0;

	$wpdb->insert(
		$table_logs,
		array(
			'action'      => sanitize_text_field( $action ),
			'entity_type' => sanitize_text_field( $entity_type ),
			'entity_id'   => $entity_id,
			'changed_by'  => get_current_user_id(),
			'changed_at'  => sr_now(),
			'notes'       => wp_kses_post( $notes ),
		),
		array( '%s', '%s', '%d', '%d', '%s', '%s' )
	);
}

/**
 * Render admin dashboard.
 */
function sr_render_admin_dashboard() {
	global $wpdb;
	$table_readings = $wpdb->prefix . 'sr_meter_readings';
	$table_payments = $wpdb->prefix . 'sr_payments';

	$pending_readings = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_readings} WHERE status = 'pending'" );
	$pending_payments = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_payments} WHERE status = 'pending'" );
	?>
	<div class="wrap">
		<h1>Strømregnskab</h1>
		<p>Afventende målerstande: <strong><?php echo esc_html( $pending_readings ); ?></strong></p>
		<p>Afventende indbetalinger: <strong><?php echo esc_html( $pending_payments ); ?></strong></p>
	</div>
	<?php
}

/**
 * Render residents page.
 */
function sr_render_residents_page() {
	if ( ! current_user_can( SR_CAPABILITY_ADMIN ) ) {
		return;
	}

	global $wpdb;
	$table_residents = $wpdb->prefix . 'sr_residents';

	if ( isset( $_POST['sr_add_resident'] ) ) {
		check_admin_referer( 'sr_add_resident_action', 'sr_add_resident_nonce' );
		$name         = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$member_num   = sanitize_text_field( wp_unslash( $_POST['member_number'] ?? '' ) );
		$wp_user_id   = absint( $_POST['wp_user_id'] ?? 0 );
		$resident_id  = absint( $_POST['resident_id'] ?? 0 );

		if ( $name && $member_num && $resident_id ) {
			$wpdb->update(
				$table_residents,
				array(
					'name'          => $name,
					'member_number' => $member_num,
					'wp_user_id'    => $wp_user_id ? $wp_user_id : null,
					'updated_at'    => sr_now(),
				),
				array( 'id' => $resident_id ),
				array( '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
			sr_log_action( 'update', 'resident', $resident_id, 'Beboer opdateret' );
		} elseif ( $name && $member_num ) {
			$wpdb->insert(
				$table_residents,
				array(
					'name'          => $name,
					'member_number' => $member_num,
					'wp_user_id'    => $wp_user_id ? $wp_user_id : null,
					'created_at'    => sr_now(),
					'updated_at'    => sr_now(),
				),
				array( '%s', '%s', '%d', '%s', '%s' )
			);
			sr_log_action( 'create', 'resident', $wpdb->insert_id, 'Beboer oprettet' );
		}
	}

	$residents = $wpdb->get_results( "SELECT * FROM {$table_residents} ORDER BY name ASC" );
	$users     = get_users();
	?>
	<div class="wrap">
		<h1>Beboere</h1>
		<form method="post" id="sr-resident-form">
			<?php wp_nonce_field( 'sr_add_resident_action', 'sr_add_resident_nonce' ); ?>
			<input type="hidden" name="resident_id" id="sr_resident_id" value="">
			<table class="form-table">
				<tr>
					<th scope="row"><label for="name">Navn</label></th>
					<td><input name="name" id="name" type="text" class="regular-text" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="member_number">Medlemsnummer (DNS)</label></th>
					<td><input name="member_number" id="member_number" type="text" class="regular-text" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="wp_user_id">WordPress-bruger</label></th>
					<td>
						<select name="wp_user_id" id="wp_user_id">
							<option value="">Ingen</option>
							<?php foreach ( $users as $user ) : ?>
								<option value="<?php echo esc_attr( $user->ID ); ?>">
									<?php echo esc_html( $user->display_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Opret beboer', 'primary', 'sr_add_resident', false, array( 'id' => 'sr_resident_submit' ) ); ?>
			<button type="button" class="button" id="sr_resident_cancel" style="display:none;">Afbryd</button>
		</form>

		<h2>Eksisterende beboere</h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Navn</th>
					<th>Medlemsnummer</th>
					<th>WP-bruger</th>
					<th>Rediger</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $residents as $resident ) : ?>
				<tr>
					<td><?php echo esc_html( $resident->name ); ?></td>
					<td><?php echo esc_html( $resident->member_number ); ?></td>
					<td><?php echo esc_html( $resident->wp_user_id ); ?></td>
					<td>
						<button
							type="button"
							class="button sr-fill-resident"
							data-resident-id="<?php echo esc_attr( $resident->id ); ?>"
							data-name="<?php echo esc_attr( $resident->name ); ?>"
							data-member-number="<?php echo esc_attr( $resident->member_number ); ?>"
							data-wp-user-id="<?php echo esc_attr( $resident->wp_user_id ); ?>"
						>Rediger</button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<script>
		(function () {
			const form = document.getElementById('sr-resident-form');
			if (!form) {
				return;
			}
			const submitButton = document.getElementById('sr_resident_submit');
			const cancelButton = document.getElementById('sr_resident_cancel');
			const residentId = document.getElementById('sr_resident_id');
			const nameField = document.getElementById('name');
			const memberField = document.getElementById('member_number');
			const userField = document.getElementById('wp_user_id');
			const defaultLabel = submitButton ? submitButton.value : '';

			document.querySelectorAll('.sr-fill-resident').forEach((button) => {
				button.addEventListener('click', () => {
					nameField.value = button.dataset.name || '';
					memberField.value = button.dataset.memberNumber || '';
					userField.value = button.dataset.wpUserId || '';
					residentId.value = button.dataset.residentId || '';
					if (submitButton) {
						submitButton.value = 'Gem';
					}
					if (cancelButton) {
						cancelButton.style.display = 'inline-block';
					}
				});
			});

			if (cancelButton) {
				cancelButton.addEventListener('click', () => {
					form.reset();
					residentId.value = '';
					if (submitButton) {
						submitButton.value = defaultLabel;
					}
					cancelButton.style.display = 'none';
				});
			}
		}());
	</script>
	<?php
}

/**
 * Render meter readings page.
 */
function sr_render_readings_page() {
	if ( ! current_user_can( SR_CAPABILITY_ADMIN ) ) {
		return;
	}
	global $wpdb;
	$table_readings = $wpdb->prefix . 'sr_meter_readings';
	$table_residents = $wpdb->prefix . 'sr_residents';
	$current_month   = (int) current_time( 'n' );
	$current_year    = (int) current_time( 'Y' );

	if ( isset( $_POST['sr_add_reading'] ) ) {
		check_admin_referer( 'sr_add_reading_action', 'sr_add_reading_nonce' );
		$resident_id = absint( $_POST['resident_id'] ?? 0 );
		$month       = absint( $_POST['period_month'] ?? 0 );
		$year        = absint( $_POST['period_year'] ?? 0 );
		$reading     = (float) ( $_POST['reading_kwh'] ?? 0 );
		$reading_id  = absint( $_POST['reading_id'] ?? 0 );

		if ( $resident_id && $month && $year && $reading_id && ! sr_is_period_locked( $month, $year ) ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$table_readings} WHERE id = %d", $reading_id ) );
			if ( $existing ) {
				$wpdb->update(
					$table_readings,
					array(
						'resident_id' => $resident_id,
						'period_month'=> $month,
						'period_year' => $year,
						'reading_kwh' => $reading,
					),
					array( 'id' => $reading_id ),
					array( '%d', '%d', '%d', '%f' ),
					array( '%d' )
				);
				sr_log_action( 'update', 'reading', $reading_id, 'Admin opdatering' );
				if ( 'verified' === $existing->status ) {
					sr_generate_summary_for_reading( $resident_id, $month, $year );
				}
			}
		} elseif ( $resident_id && $month && $year && ! sr_is_period_locked( $month, $year ) ) {
			$wpdb->insert(
				$table_readings,
				array(
					'resident_id' => $resident_id,
					'period_month'=> $month,
					'period_year' => $year,
					'reading_kwh' => $reading,
					'status'      => 'verified',
					'submitted_by'=> get_current_user_id(),
					'submitted_at'=> sr_now(),
					'verified_by' => get_current_user_id(),
					'verified_at' => sr_now(),
				),
				array( '%d', '%d', '%d', '%f', '%s', '%d', '%s', '%d', '%s' )
			);
			sr_log_action( 'create', 'reading', $wpdb->insert_id, 'Admin indtastning' );
			sr_generate_summary_for_reading( $resident_id, $month, $year );
		}
	}

	if ( isset( $_GET['verify_reading'] ) ) {
		$reading_id = absint( $_GET['verify_reading'] );
		check_admin_referer( 'sr_verify_reading_' . $reading_id );
		$reading = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_readings} WHERE id = %d", $reading_id ) );
		if ( $reading && 'pending' === $reading->status && ! sr_is_period_locked( $reading->period_month, $reading->period_year ) ) {
			$wpdb->update(
				$table_readings,
				array(
					'status'      => 'verified',
					'verified_by' => get_current_user_id(),
					'verified_at' => sr_now(),
				),
				array( 'id' => $reading_id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			sr_log_action( 'verify', 'reading', $reading_id, 'Målerstand verificeret' );
			sr_generate_summary_for_reading( (int) $reading->resident_id, (int) $reading->period_month, (int) $reading->period_year );
			sr_notify_resident_verified( (int) $reading->resident_id, 'målerstand' );
		}
	}

	$residents = $wpdb->get_results( "SELECT * FROM {$table_residents} ORDER BY name ASC" );
	$readings  = $wpdb->get_results( "SELECT * FROM {$table_readings} ORDER BY submitted_at DESC" );
	?>
	<div class="wrap">
		<h1>Målerstande</h1>
		<form method="post" id="sr-reading-form" data-current-month="<?php echo esc_attr( $current_month ); ?>" data-current-year="<?php echo esc_attr( $current_year ); ?>">
			<?php wp_nonce_field( 'sr_add_reading_action', 'sr_add_reading_nonce' ); ?>
			<input type="hidden" name="reading_id" id="sr_reading_id" value="">
			<table class="form-table">
				<tr>
					<th scope="row">Beboer</th>
					<td>
						<select name="resident_id" id="sr_reading_resident" required>
							<option value="">Vælg beboer</option>
							<?php foreach ( $residents as $resident ) : ?>
								<option value="<?php echo esc_attr( $resident->id ); ?>"><?php echo esc_html( $resident->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">Periode (måned/år)</th>
					<td>
						<input type="number" name="period_month" id="sr_reading_month" min="1" max="12" required value="<?php echo esc_attr( $current_month ); ?>">
						<input type="number" name="period_year" id="sr_reading_year" min="2000" max="2100" required value="<?php echo esc_attr( $current_year ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row">Målerstand (kWh)</th>
					<td><input type="number" name="reading_kwh" id="sr_reading_value" step="0.001" required></td>
				</tr>
			</table>
			<?php submit_button( 'Indtast og verificer', 'primary', 'sr_add_reading', false, array( 'id' => 'sr_reading_submit' ) ); ?>
			<button type="button" class="button" id="sr_reading_cancel" style="display:none;">Afbryd</button>
		</form>

		<h2>Indberetninger</h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Dato</th>
					<th>Beboer</th>
					<th>Periode</th>
					<th>Målerstand</th>
					<th>Status</th>
					<th>Rediger</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $readings as $reading ) : ?>
					<?php
					$resident_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table_residents} WHERE id = %d", $reading->resident_id ) );
					?>
					<tr>
						<td><?php echo esc_html( $reading->submitted_at ); ?></td>
						<td><?php echo esc_html( $resident_name ); ?></td>
						<td><?php echo esc_html( $reading->period_month . '/' . $reading->period_year ); ?></td>
						<td><?php echo esc_html( $reading->reading_kwh ); ?></td>
						<td><?php echo esc_html( $reading->status ); ?></td>
						<td>
							<button
								type="button"
								class="button sr-fill-reading"
								data-reading-id="<?php echo esc_attr( $reading->id ); ?>"
								data-resident-id="<?php echo esc_attr( $reading->resident_id ); ?>"
								data-period-month="<?php echo esc_attr( $reading->period_month ); ?>"
								data-period-year="<?php echo esc_attr( $reading->period_year ); ?>"
								data-reading-kwh="<?php echo esc_attr( $reading->reading_kwh ); ?>"
							>Rediger</button>
							<?php if ( 'pending' === $reading->status ) : ?>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . SR_PLUGIN_SLUG . '-readings&verify_reading=' . $reading->id ), 'sr_verify_reading_' . $reading->id ) ); ?>">Verificer</a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<script>
		(function () {
			const form = document.getElementById('sr-reading-form');
			if (!form) {
				return;
			}
			const submitButton = document.getElementById('sr_reading_submit');
			const cancelButton = document.getElementById('sr_reading_cancel');
			const readingId = document.getElementById('sr_reading_id');
			const residentField = document.getElementById('sr_reading_resident');
			const monthField = document.getElementById('sr_reading_month');
			const yearField = document.getElementById('sr_reading_year');
			const readingField = document.getElementById('sr_reading_value');
			const defaultLabel = submitButton ? submitButton.value : '';
			const currentMonth = form.dataset.currentMonth || '';
			const currentYear = form.dataset.currentYear || '';

			document.querySelectorAll('.sr-fill-reading').forEach((button) => {
				button.addEventListener('click', () => {
					residentField.value = button.dataset.residentId || '';
					monthField.value = button.dataset.periodMonth || currentMonth;
					yearField.value = button.dataset.periodYear || currentYear;
					readingField.value = button.dataset.readingKwh || '';
					readingId.value = button.dataset.readingId || '';
					if (submitButton) {
						submitButton.value = 'Gem';
					}
					if (cancelButton) {
						cancelButton.style.display = 'inline-block';
					}
				});
			});

			if (cancelButton) {
				cancelButton.addEventListener('click', () => {
					form.reset();
					readingId.value = '';
					residentField.value = '';
					monthField.value = currentMonth;
					yearField.value = currentYear;
					if (submitButton) {
						submitButton.value = defaultLabel;
					}
					cancelButton.style.display = 'none';
				});
			}
		}());
	</script>
	<?php
}

/**
 * Render payments page.
 */
function sr_render_payments_page() {
	if ( ! current_user_can( SR_CAPABILITY_ADMIN ) ) {
		return;
	}
	global $wpdb;
	$table_payments  = $wpdb->prefix . 'sr_payments';
	$table_residents = $wpdb->prefix . 'sr_residents';
	$current_month   = (int) current_time( 'n' );
	$current_year    = (int) current_time( 'Y' );

	if ( isset( $_POST['sr_add_payment'] ) ) {
		check_admin_referer( 'sr_add_payment_action', 'sr_add_payment_nonce' );
		$resident_id = absint( $_POST['resident_id'] ?? 0 );
		$month       = absint( $_POST['period_month'] ?? 0 );
		$year        = absint( $_POST['period_year'] ?? 0 );
		$amount      = (float) ( $_POST['amount'] ?? 0 );
		$payment_id  = absint( $_POST['payment_id'] ?? 0 );

		if ( $resident_id && $month && $year && $payment_id && ! sr_is_period_locked( $month, $year ) ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table_payments} WHERE id = %d", $payment_id ) );
			if ( $existing ) {
				$wpdb->update(
					$table_payments,
					array(
						'resident_id' => $resident_id,
						'period_month'=> $month,
						'period_year' => $year,
						'amount'      => $amount,
					),
					array( 'id' => $payment_id ),
					array( '%d', '%d', '%d', '%f' ),
					array( '%d' )
				);
				sr_log_action( 'update', 'payment', $payment_id, 'Admin opdatering' );
			}
		} elseif ( $resident_id && $month && $year && ! sr_is_period_locked( $month, $year ) ) {
			$wpdb->insert(
				$table_payments,
				array(
					'resident_id' => $resident_id,
					'period_month'=> $month,
					'period_year' => $year,
					'amount'      => $amount,
					'status'      => 'verified',
					'submitted_by'=> get_current_user_id(),
					'submitted_at'=> sr_now(),
					'verified_by' => get_current_user_id(),
					'verified_at' => sr_now(),
				),
				array( '%d', '%d', '%d', '%f', '%s', '%d', '%s', '%d', '%s' )
			);
			sr_log_action( 'create', 'payment', $wpdb->insert_id, 'Admin indtastning' );
		}
	}

	if ( isset( $_GET['verify_payment'] ) ) {
		$payment_id = absint( $_GET['verify_payment'] );
		check_admin_referer( 'sr_verify_payment_' . $payment_id );
		$payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_payments} WHERE id = %d", $payment_id ) );
		if ( $payment && 'pending' === $payment->status && ! sr_is_period_locked( $payment->period_month, $payment->period_year ) ) {
			$wpdb->update(
				$table_payments,
				array(
					'status'      => 'verified',
					'verified_by' => get_current_user_id(),
					'verified_at' => sr_now(),
				),
				array( 'id' => $payment_id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			sr_log_action( 'verify', 'payment', $payment_id, 'Indbetaling verificeret' );
			sr_notify_resident_verified( (int) $payment->resident_id, 'indbetaling' );
		}
	}

	$residents = $wpdb->get_results( "SELECT * FROM {$table_residents} ORDER BY name ASC" );
	$payments  = $wpdb->get_results( "SELECT * FROM {$table_payments} ORDER BY submitted_at DESC" );
	?>
	<div class="wrap">
		<h1>Indbetalinger</h1>
		<form method="post" id="sr-payment-form" data-current-month="<?php echo esc_attr( $current_month ); ?>" data-current-year="<?php echo esc_attr( $current_year ); ?>">
			<?php wp_nonce_field( 'sr_add_payment_action', 'sr_add_payment_nonce' ); ?>
			<input type="hidden" name="payment_id" id="sr_payment_id" value="">
			<table class="form-table">
				<tr>
					<th scope="row">Beboer</th>
					<td>
						<select name="resident_id" id="sr_payment_resident" required>
							<option value="">Vælg beboer</option>
							<?php foreach ( $residents as $resident ) : ?>
								<option value="<?php echo esc_attr( $resident->id ); ?>"><?php echo esc_html( $resident->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">Periode (måned/år)</th>
					<td>
						<input type="number" name="period_month" id="sr_payment_month" min="1" max="12" required value="<?php echo esc_attr( $current_month ); ?>">
						<input type="number" name="period_year" id="sr_payment_year" min="2000" max="2100" required value="<?php echo esc_attr( $current_year ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row">Beløb</th>
					<td><input type="number" name="amount" id="sr_payment_amount" step="0.01" required></td>
				</tr>
			</table>
			<?php submit_button( 'Indtast og verificer', 'primary', 'sr_add_payment', false, array( 'id' => 'sr_payment_submit' ) ); ?>
			<button type="button" class="button" id="sr_payment_cancel" style="display:none;">Afbryd</button>
		</form>

		<h2>Indberetninger</h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Dato</th>
					<th>Beboer</th>
					<th>Periode</th>
					<th>Beløb</th>
					<th>Status</th>
					<th>Rediger</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $payments as $payment ) : ?>
					<?php
					$resident_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table_residents} WHERE id = %d", $payment->resident_id ) );
					?>
					<tr>
						<td><?php echo esc_html( $payment->submitted_at ); ?></td>
						<td><?php echo esc_html( $resident_name ); ?></td>
						<td><?php echo esc_html( $payment->period_month . '/' . $payment->period_year ); ?></td>
						<td><?php echo esc_html( $payment->amount ); ?></td>
						<td><?php echo esc_html( $payment->status ); ?></td>
						<td>
							<button
								type="button"
								class="button sr-fill-payment"
								data-payment-id="<?php echo esc_attr( $payment->id ); ?>"
								data-resident-id="<?php echo esc_attr( $payment->resident_id ); ?>"
								data-period-month="<?php echo esc_attr( $payment->period_month ); ?>"
								data-period-year="<?php echo esc_attr( $payment->period_year ); ?>"
								data-amount="<?php echo esc_attr( $payment->amount ); ?>"
							>Rediger</button>
							<?php if ( 'pending' === $payment->status ) : ?>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . SR_PLUGIN_SLUG . '-payments&verify_payment=' . $payment->id ), 'sr_verify_payment_' . $payment->id ) ); ?>">Verificer</a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<script>
		(function () {
			const form = document.getElementById('sr-payment-form');
			if (!form) {
				return;
			}
			const submitButton = document.getElementById('sr_payment_submit');
			const cancelButton = document.getElementById('sr_payment_cancel');
			const paymentId = document.getElementById('sr_payment_id');
			const residentField = document.getElementById('sr_payment_resident');
			const monthField = document.getElementById('sr_payment_month');
			const yearField = document.getElementById('sr_payment_year');
			const amountField = document.getElementById('sr_payment_amount');
			const defaultLabel = submitButton ? submitButton.value : '';
			const currentMonth = form.dataset.currentMonth || '';
			const currentYear = form.dataset.currentYear || '';

			document.querySelectorAll('.sr-fill-payment').forEach((button) => {
				button.addEventListener('click', () => {
					residentField.value = button.dataset.residentId || '';
					monthField.value = button.dataset.periodMonth || currentMonth;
					yearField.value = button.dataset.periodYear || currentYear;
					amountField.value = button.dataset.amount || '';
					paymentId.value = button.dataset.paymentId || '';
					if (submitButton) {
						submitButton.value = 'Gem';
					}
					if (cancelButton) {
						cancelButton.style.display = 'inline-block';
					}
				});
			});

			if (cancelButton) {
				cancelButton.addEventListener('click', () => {
					form.reset();
					paymentId.value = '';
					residentField.value = '';
					monthField.value = currentMonth;
					yearField.value = currentYear;
					if (submitButton) {
						submitButton.value = defaultLabel;
					}
					cancelButton.style.display = 'none';
				});
			}
		}());
	</script>
	<?php
}

/**
 * Render prices page.
 */
function sr_render_prices_page() {
	if ( ! current_user_can( SR_CAPABILITY_ADMIN ) ) {
		return;
	}
	global $wpdb;
	$table_prices = $wpdb->prefix . 'sr_prices';
	$current_month = (int) current_time( 'n' );
	$current_year  = (int) current_time( 'Y' );

	if ( isset( $_POST['sr_save_price'] ) ) {
		check_admin_referer( 'sr_save_price_action', 'sr_save_price_nonce' );
		$month = absint( $_POST['period_month'] ?? 0 );
		$year  = absint( $_POST['period_year'] ?? 0 );
		$price = (float) ( $_POST['price_per_kwh'] ?? 0 );

		if ( $month && $year && ! sr_is_period_locked( $month, $year ) ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table_prices} WHERE period_month = %d AND period_year = %d",
					$month,
					$year
				)
			);

			if ( $existing ) {
				$wpdb->update(
					$table_prices,
					array(
						'price_per_kwh' => $price,
						'updated_at'    => sr_now(),
					),
					array( 'id' => $existing ),
					array( '%f', '%s' ),
					array( '%d' )
				);
				sr_log_action( 'update', 'price', $existing, 'Opdateret pris' );
			} else {
				$wpdb->insert(
					$table_prices,
					array(
						'period_month' => $month,
						'period_year'  => $year,
						'price_per_kwh'=> $price,
						'created_by'   => get_current_user_id(),
						'created_at'   => sr_now(),
						'updated_at'   => sr_now(),
					),
					array( '%d', '%d', '%f', '%d', '%s', '%s' )
				);
				sr_log_action( 'create', 'price', $wpdb->insert_id, 'Ny pris' );
			}
		}
	}

	$prices = $wpdb->get_results( "SELECT * FROM {$table_prices} ORDER BY period_year DESC, period_month DESC LIMIT 12" );
	?>
	<div class="wrap">
		<h1>Strømpriser</h1>
		<form method="post">
			<?php wp_nonce_field( 'sr_save_price_action', 'sr_save_price_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row">Periode (måned/år)</th>
					<td>
						<input type="number" name="period_month" min="1" max="12" required value="<?php echo esc_attr( $current_month ); ?>">
						<input type="number" name="period_year" min="2000" max="2100" required value="<?php echo esc_attr( $current_year ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row">Pris pr. kWh</th>
					<td><input type="number" name="price_per_kwh" step="0.0001" required></td>
				</tr>
			</table>
			<?php submit_button( 'Gem pris', 'primary', 'sr_save_price' ); ?>
		</form>

		<h2>Seneste priser</h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Periode</th>
					<th>Pris pr. kWh</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $prices as $price ) : ?>
					<tr>
						<td><?php echo esc_html( $price->period_month . '/' . $price->period_year ); ?></td>
						<td><?php echo esc_html( $price->price_per_kwh ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * Render period locks page.
 */
function sr_render_locks_page() {
	if ( ! current_user_can( SR_CAPABILITY_ADMIN ) ) {
		return;
	}
	global $wpdb;
	$table_locks = $wpdb->prefix . 'sr_period_locks';
	$current_month = (int) current_time( 'n' );
	$current_year  = (int) current_time( 'Y' );

	if ( isset( $_POST['sr_lock_period'] ) ) {
		check_admin_referer( 'sr_lock_period_action', 'sr_lock_period_nonce' );
		$month = absint( $_POST['period_month'] ?? 0 );
		$year  = absint( $_POST['period_year'] ?? 0 );

		if ( $month && $year && ! sr_is_period_locked( $month, $year ) ) {
			$wpdb->insert(
				$table_locks,
				array(
					'period_month' => $month,
					'period_year'  => $year,
					'locked_by'    => get_current_user_id(),
					'locked_at'    => sr_now(),
				),
				array( '%d', '%d', '%d', '%s' )
			);
			sr_log_action( 'lock', 'period', $wpdb->insert_id, 'Periode låst' );
		}
	}

	$locks = $wpdb->get_results( "SELECT * FROM {$table_locks} ORDER BY period_year DESC, period_month DESC" );
	?>
	<div class="wrap">
		<h1>Periodelåsning</h1>
		<form method="post">
			<?php wp_nonce_field( 'sr_lock_period_action', 'sr_lock_period_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row">Periode (måned/år)</th>
					<td>
						<input type="number" name="period_month" min="1" max="12" required value="<?php echo esc_attr( $current_month ); ?>">
						<input type="number" name="period_year" min="2000" max="2100" required value="<?php echo esc_attr( $current_year ); ?>">
					</td>
				</tr>
			</table>
			<?php submit_button( 'Lås periode', 'primary', 'sr_lock_period' ); ?>
		</form>

		<h2>Låste perioder</h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Periode</th>
					<th>Låst af</th>
					<th>Dato</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $locks as $lock ) : ?>
					<tr>
						<td><?php echo esc_html( $lock->period_month . '/' . $lock->period_year ); ?></td>
						<td><?php echo esc_html( $lock->locked_by ); ?></td>
						<td><?php echo esc_html( $lock->locked_at ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * Render CSV export page.
 */
function sr_render_export_page() {
	if ( ! current_user_can( SR_CAPABILITY_ADMIN ) ) {
		return;
	}

	if ( isset( $_GET['sr_export'] ) ) {
		sr_export_csv();
	}
	?>
	<div class="wrap">
		<h1>CSV-eksport</h1>
		<p>Download CSV for alle beboere eller en specifik beboer.</p>
		<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . SR_PLUGIN_SLUG . '-export&sr_export=all' ) ); ?>">Eksportér alle</a>
	</div>
	<?php
}

/**
 * Export CSV.
 */
function sr_export_csv() {
	if ( ! current_user_can( SR_CAPABILITY_ADMIN ) ) {
		return;
	}
	global $wpdb;
	$table_residents = $wpdb->prefix . 'sr_residents';
	$table_summary   = $wpdb->prefix . 'sr_monthly_summary';
	$table_payments  = $wpdb->prefix . 'sr_payments';

	$csv_rows = array();
	$csv_rows[] = array( 'Beboer', 'Måned/År', 'Forbrug (kWh)', 'Pris pr. kWh', 'Udgift', 'Indbetalinger', 'Månedlig saldoændring', 'Løbende saldo' );

	$residents = $wpdb->get_results( "SELECT * FROM {$table_residents}" );
	foreach ( $residents as $resident ) {
		$summary_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_summary} WHERE resident_id = %d ORDER BY period_year ASC, period_month ASC",
				$resident->id
			)
		);

		$running_balance = 0.0;
		foreach ( $summary_rows as $summary ) {
			$payments_total = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT SUM(amount) FROM {$table_payments} WHERE resident_id = %d AND period_month = %d AND period_year = %d AND status = 'verified'",
					$resident->id,
					$summary->period_month,
					$summary->period_year
				)
			);
			$monthly_delta   = $payments_total - (float) $summary->cost;
			$running_balance += $monthly_delta;
			$csv_rows[] = array(
				$resident->name,
				$summary->period_month . '/' . $summary->period_year,
				$summary->consumption_kwh,
				$summary->price_per_kwh,
				$summary->cost,
				$payments_total,
				$monthly_delta,
				$running_balance,
			);
		}
	}

	header( 'Content-Type: text/csv' );
	header( 'Content-Disposition: attachment; filename="stromregnskab.csv"' );

	$output = fopen( 'php://output', 'w' );
	foreach ( $csv_rows as $row ) {
		fputcsv( $output, $row, ';' );
	}
	fclose( $output );
	exit;
}

/**
 * Generate summary for a verified reading.
 *
 * @param int $resident_id Resident ID.
 * @param int $month Month.
 * @param int $year Year.
 */
function sr_generate_summary_for_reading( $resident_id, $month, $year ) {
	global $wpdb;
	$table_readings = $wpdb->prefix . 'sr_meter_readings';
	$table_summary  = $wpdb->prefix . 'sr_monthly_summary';

	$current = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table_readings} WHERE resident_id = %d AND period_month = %d AND period_year = %d AND status = 'verified'",
			$resident_id,
			$month,
			$year
		)
	);

	if ( ! $current ) {
		return;
	}

	$previous = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table_readings} WHERE resident_id = %d AND status = 'verified' AND (period_year < %d OR (period_year = %d AND period_month < %d)) ORDER BY period_year DESC, period_month DESC LIMIT 1",
			$resident_id,
			$year,
			$year,
			$month
		)
	);

	if ( ! $previous ) {
		return;
	}

	$consumption = max( 0, (float) $current->reading_kwh - (float) $previous->reading_kwh );
	$price       = sr_get_price_for_period( $month, $year );

	if ( null === $price ) {
		return;
	}

	$cost = $consumption * (float) $price;

	$existing = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table_summary} WHERE resident_id = %d AND period_month = %d AND period_year = %d",
			$resident_id,
			$month,
			$year
		)
	);
	if ( $existing ) {
		return;
	}

	$wpdb->insert(
		$table_summary,
		array(
			'resident_id'     => $resident_id,
			'period_month'    => $month,
			'period_year'     => $year,
			'consumption_kwh' => $consumption,
			'price_per_kwh'   => $price,
			'cost'            => $cost,
			'created_at'      => sr_now(),
		),
		array( '%d', '%d', '%d', '%f', '%f', '%f', '%s' )
	);
	sr_log_action( 'create', 'summary', $wpdb->insert_id, 'Beregning oprettet' );
}

/**
 * Notify admin about a new submission.
 *
 * @param string $type Submission type.
 * @param int    $resident_id Resident ID.
 */
function sr_notify_admin_submission( $type, $resident_id ) {
	$admin_email = get_option( 'admin_email' );
	$subject     = 'Ny indberetning: ' . $type;
	$message     = 'Der er en ny indberetning fra beboer ID ' . $resident_id . '.';
	wp_mail( $admin_email, $subject, $message );
}

/**
 * Notify resident when verified.
 *
 * @param int    $resident_id Resident ID.
 * @param string $type Type.
 */
function sr_notify_resident_verified( $resident_id, $type ) {
	global $wpdb;
	$table_residents = $wpdb->prefix . 'sr_residents';
	$resident        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_residents} WHERE id = %d", $resident_id ) );
	if ( ! $resident || ! $resident->wp_user_id ) {
		return;
	}
	$user = get_user_by( 'id', $resident->wp_user_id );
	if ( ! $user ) {
		return;
	}
	$subject = 'Din indberetning er verificeret';
	$message = 'Din ' . $type . ' er verificeret af admin.';
	wp_mail( $user->user_email, $subject, $message );
}

/**
 * Shortcode for resident dashboard.
 *
 * @return string
 */
function sr_resident_dashboard_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '<p>Log ind for at se dine data.</p>';
	}

	if ( ! current_user_can( SR_CAPABILITY_RESIDENT ) && ! current_user_can( SR_CAPABILITY_ADMIN ) ) {
		return '<p>Du har ikke adgang til dette område.</p>';
	}

	global $wpdb;
	$table_residents = $wpdb->prefix . 'sr_residents';
	$table_readings  = $wpdb->prefix . 'sr_meter_readings';
	$table_payments  = $wpdb->prefix . 'sr_payments';
	$table_summary   = $wpdb->prefix . 'sr_monthly_summary';
	$current_month   = (int) current_time( 'n' );
	$current_year    = (int) current_time( 'Y' );

	$current_user_id = get_current_user_id();
	$resident        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_residents} WHERE wp_user_id = %d", $current_user_id ) );

	if ( ! $resident ) {
		return '<p>Ingen beboerdata knyttet til din bruger.</p>';
	}

	$message = '';
	if ( isset( $_POST['sr_submit_reading'] ) ) {
		check_admin_referer( 'sr_submit_reading_action', 'sr_submit_reading_nonce' );
		$month   = absint( $_POST['period_month'] ?? 0 );
		$year    = absint( $_POST['period_year'] ?? 0 );
		$reading = (float) ( $_POST['reading_kwh'] ?? 0 );

		if ( $month && $year && ! sr_is_period_locked( $month, $year ) ) {
			$wpdb->insert(
				$table_readings,
				array(
					'resident_id' => $resident->id,
					'period_month'=> $month,
					'period_year' => $year,
					'reading_kwh' => $reading,
					'status'      => 'pending',
					'submitted_by'=> $current_user_id,
					'submitted_at'=> sr_now(),
				),
				array( '%d', '%d', '%d', '%f', '%s', '%d', '%s' )
			);
			sr_log_action( 'submit', 'reading', $wpdb->insert_id, 'Beboer indberetning' );
			sr_notify_admin_submission( 'målerstand', $resident->id );
			$message = '<p>Tak! Din målerstand er modtaget.</p>';
		}
	}

	if ( isset( $_POST['sr_submit_payment'] ) ) {
		check_admin_referer( 'sr_submit_payment_action', 'sr_submit_payment_nonce' );
		$month  = absint( $_POST['period_month'] ?? 0 );
		$year   = absint( $_POST['period_year'] ?? 0 );
		$amount = (float) ( $_POST['amount'] ?? 0 );

		if ( $month && $year && ! sr_is_period_locked( $month, $year ) ) {
			$wpdb->insert(
				$table_payments,
				array(
					'resident_id' => $resident->id,
					'period_month'=> $month,
					'period_year' => $year,
					'amount'      => $amount,
					'status'      => 'pending',
					'submitted_by'=> $current_user_id,
					'submitted_at'=> sr_now(),
				),
				array( '%d', '%d', '%d', '%f', '%s', '%d', '%s' )
			);
			sr_log_action( 'submit', 'payment', $wpdb->insert_id, 'Beboer indbetaling' );
			sr_notify_admin_submission( 'indbetaling', $resident->id );
			$message = '<p>Tak! Din indbetaling er modtaget.</p>';
		}
	}

	$readings = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_readings} WHERE resident_id = %d ORDER BY period_year DESC, period_month DESC", $resident->id ) );
	$payments = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_payments} WHERE resident_id = %d ORDER BY period_year DESC, period_month DESC", $resident->id ) );
	$summary  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_summary} WHERE resident_id = %d ORDER BY period_year DESC, period_month DESC", $resident->id ) );

	$balance = 0.0;
	foreach ( array_reverse( $summary ) as $item ) {
		$payments_total = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(amount) FROM {$table_payments} WHERE resident_id = %d AND period_month = %d AND period_year = %d AND status = 'verified'",
				$resident->id,
				$item->period_month,
				$item->period_year
			)
		);
		$balance += $payments_total - (float) $item->cost;
	}

	ob_start();
	?>
	<div class="sr-dashboard">
		<h2>Din oversigt</h2>
		<?php echo wp_kses_post( $message ); ?>
		<p>Aktuel saldo: <strong class="<?php echo esc_attr( $balance < 0 ? 'sr-negative' : 'sr-positive' ); ?>"><?php echo esc_html( number_format( $balance, 2, ',', '.' ) ); ?> kr.</strong></p>

		<h3>Indberet målerstand</h3>
		<form method="post">
			<?php wp_nonce_field( 'sr_submit_reading_action', 'sr_submit_reading_nonce' ); ?>
			<input type="number" name="period_month" min="1" max="12" required placeholder="Måned" value="<?php echo esc_attr( $current_month ); ?>">
			<input type="number" name="period_year" min="2000" max="2100" required placeholder="År" value="<?php echo esc_attr( $current_year ); ?>">
			<input type="number" name="reading_kwh" step="0.001" required placeholder="kWh">
			<button type="submit" name="sr_submit_reading">Send</button>
		</form>

		<h3>Indberet indbetaling</h3>
		<form method="post">
			<?php wp_nonce_field( 'sr_submit_payment_action', 'sr_submit_payment_nonce' ); ?>
			<input type="number" name="period_month" min="1" max="12" required placeholder="Måned" value="<?php echo esc_attr( $current_month ); ?>">
			<input type="number" name="period_year" min="2000" max="2100" required placeholder="År" value="<?php echo esc_attr( $current_year ); ?>">
			<input type="number" name="amount" step="0.01" required placeholder="Beløb">
			<button type="submit" name="sr_submit_payment">Send</button>
		</form>

		<h3>Målerstande</h3>
		<ul>
			<?php foreach ( $readings as $reading ) : ?>
				<li><?php echo esc_html( $reading->period_month . '/' . $reading->period_year . ' - ' . $reading->reading_kwh . ' kWh (' . $reading->status . ')' ); ?></li>
			<?php endforeach; ?>
		</ul>

		<h3>Indbetalinger</h3>
		<ul>
			<?php foreach ( $payments as $payment ) : ?>
				<li><?php echo esc_html( $payment->period_month . '/' . $payment->period_year . ' - ' . $payment->amount . ' kr. (' . $payment->status . ')' ); ?></li>
			<?php endforeach; ?>
		</ul>

		<h3>Beregninger</h3>
		<table>
			<thead>
				<tr>
					<th>Periode</th>
					<th>Forbrug (kWh)</th>
					<th>Pris</th>
					<th>Udgift</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $summary as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->period_month . '/' . $row->period_year ); ?></td>
						<td><?php echo esc_html( $row->consumption_kwh ); ?></td>
						<td><?php echo esc_html( $row->price_per_kwh ); ?></td>
						<td class="<?php echo esc_attr( $row->cost > 0 ? 'sr-negative' : 'sr-positive' ); ?>">
							<?php echo esc_html( number_format( $row->cost, 2, ',', '.' ) ); ?> kr.
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'strom_regnskab_dashboard', 'sr_resident_dashboard_shortcode' );

/**
 * Enqueue frontend styles for negative values.
 */
function sr_enqueue_styles() {
	wp_add_inline_style(
		'wp-block-library',
		'.sr-negative{color:#b00020;font-weight:600}.sr-positive{color:#1a7f37;font-weight:600}.sr-dashboard table{width:100%;border-collapse:collapse}.sr-dashboard table td,.sr-dashboard table th{border-bottom:1px solid #ddd;padding:6px 8px;text-align:left}'
	);
}
add_action( 'wp_enqueue_scripts', 'sr_enqueue_styles' );
