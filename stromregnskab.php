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
	$table_bank_statements = $wpdb->prefix . 'sr_bank_statements';

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
		bank_statement_id bigint(20) unsigned DEFAULT NULL,
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
		KEY status (status),
		KEY bank_statement_id (bank_statement_id)
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
	) {$charset_collate};

	CREATE TABLE {$table_bank_statements} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		`Dato` varchar(20) NOT NULL,
		`Tekst` text NOT NULL,
		`Beløb` decimal(12,2) NOT NULL,
		`Saldo` decimal(12,2) NOT NULL,
		row_hash char(64) NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY row_hash (row_hash),
		KEY created_at (created_at)
	) {$charset_collate};";

	dbDelta( $sql );
	sr_add_foreign_keys();
	sr_add_payment_bank_statement_column();
	sr_remove_bank_statement_account_name_column();

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
 * Ensure resident-linked tables cascade on delete.
 */
function sr_add_foreign_keys() {
	global $wpdb;

	$schema = $wpdb->dbname;
	$table_residents = $wpdb->prefix . 'sr_residents';
	$table_readings  = $wpdb->prefix . 'sr_meter_readings';
	$table_payments  = $wpdb->prefix . 'sr_payments';
	$table_summary   = $wpdb->prefix . 'sr_monthly_summary';

	$foreign_keys = array(
		array(
			'table'      => $table_readings,
			'column'     => 'resident_id',
			'constraint' => 'sr_readings_resident_fk',
		),
		array(
			'table'      => $table_payments,
			'column'     => 'resident_id',
			'constraint' => 'sr_payments_resident_fk',
		),
		array(
			'table'      => $table_summary,
			'column'     => 'resident_id',
			'constraint' => 'sr_summary_resident_fk',
		),
	);

	foreach ( $foreign_keys as $foreign_key ) {
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT CONSTRAINT_NAME
					FROM information_schema.KEY_COLUMN_USAGE
					WHERE TABLE_SCHEMA = %s
					AND TABLE_NAME = %s
					AND COLUMN_NAME = %s
					AND REFERENCED_TABLE_NAME = %s",
				$schema,
				$foreign_key['table'],
				$foreign_key['column'],
				$table_residents
			)
		);

		if ( ! $existing ) {
			$wpdb->query(
				"ALTER TABLE {$foreign_key['table']}
					ADD CONSTRAINT {$foreign_key['constraint']}
					FOREIGN KEY ({$foreign_key['column']})
					REFERENCES {$table_residents}(id)
					ON DELETE CASCADE"
			);
		}
	}
}

/**
 * Remove Kontonavn column from bank statements table if it exists.
 */
function sr_remove_bank_statement_account_name_column() {
	global $wpdb;

	$table_bank_statements = $wpdb->prefix . 'sr_bank_statements';
	$column_exists         = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table_bank_statements} LIKE %s", 'Kontonavn' ) );

	if ( $column_exists ) {
		$wpdb->query( "ALTER TABLE {$table_bank_statements} DROP COLUMN `Kontonavn`" );
	}
}

/**
 * Ensure payments table includes bank statement reference.
 */
function sr_add_payment_bank_statement_column() {
	global $wpdb;

	$table_payments = $wpdb->prefix . 'sr_payments';
	$column_exists  = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table_payments} LIKE %s", 'bank_statement_id' ) );

	if ( ! $column_exists ) {
		$wpdb->query( "ALTER TABLE {$table_payments} ADD COLUMN bank_statement_id bigint(20) unsigned DEFAULT NULL, ADD KEY bank_statement_id (bank_statement_id)" );
	}
}

add_action( 'admin_init', 'sr_add_payment_bank_statement_column' );

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
	add_submenu_page( SR_PLUGIN_SLUG, 'Beboer saldo', 'Beboer saldo', SR_CAPABILITY_ADMIN, SR_PLUGIN_SLUG . '-balances', 'sr_render_balances_page' );
	add_submenu_page( SR_PLUGIN_SLUG, 'Beboer regnskab', 'Beboer regnskab', SR_CAPABILITY_ADMIN, SR_PLUGIN_SLUG . '-resident-account', 'sr_render_resident_account_page' );
	add_submenu_page( SR_PLUGIN_SLUG, 'Strømpriser', 'Strømpriser', SR_CAPABILITY_ADMIN, SR_PLUGIN_SLUG . '-prices', 'sr_render_prices_page' );
	add_submenu_page( SR_PLUGIN_SLUG, 'Periodelåsning', 'Periodelåsning', SR_CAPABILITY_ADMIN, SR_PLUGIN_SLUG . '-locks', 'sr_render_locks_page' );
	add_submenu_page( SR_PLUGIN_SLUG, 'CSV-eksport', 'CSV-eksport', SR_CAPABILITY_ADMIN, SR_PLUGIN_SLUG . '-export', 'sr_render_export_page' );
	add_submenu_page( SR_PLUGIN_SLUG, 'Bankudtog', 'Bankudtog', SR_CAPABILITY_ADMIN, SR_PLUGIN_SLUG . '-bank-statements', 'sr_render_bank_statements_page' );
	add_submenu_page( SR_PLUGIN_SLUG, 'Tilknyt betalinger', 'Tilknyt betalinger', SR_CAPABILITY_ADMIN, SR_PLUGIN_SLUG . '-bank-link-payments', 'sr_render_bank_statement_link_page' );
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
 * Get pagination page from request.
 *
 * @param string $key Query param key.
 * @return int
 */
function sr_get_paged_param( $key ) {
	$page = isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : 1;
	if ( $page < 1 ) {
		$page = 1;
	}
	return $page;
}

/**
 * Get months difference between two periods.
 *
 * @param int $start_month Start month.
 * @param int $start_year  Start year.
 * @param int $end_month   End month.
 * @param int $end_year    End year.
 * @return int
 */
function sr_get_months_diff( $start_month, $start_year, $end_month, $end_year ) {
	$start_total = ( (int) $start_year * 12 ) + ( (int) $start_month );
	$end_total   = ( (int) $end_year * 12 ) + ( (int) $end_month );
	return max( 0, $end_total - $start_total );
}

/**
 * Add months to a period.
 *
 * @param int $month  Month.
 * @param int $year   Year.
 * @param int $offset Months to add.
 * @return array{month:int,year:int}
 */
function sr_add_months_to_period( $month, $year, $offset ) {
	$total = ( (int) $year * 12 ) + ( (int) $month ) + (int) $offset;
	$year  = (int) floor( ( $total - 1 ) / 12 );
	$month = (int) ( $total - ( $year * 12 ) );
	return array(
		'month' => $month,
		'year'  => $year,
	);
}

/**
 * Get number of days in a month/year.
 *
 * @param int $month Month.
 * @param int $year  Year.
 * @return int
 */
function sr_get_days_in_month( $month, $year ) {
	return (int) cal_days_in_month( CAL_GREGORIAN, (int) $month, (int) $year );
}

/**
 * Parse bank statement date to period.
 *
 * @param string $date Bank statement date.
 * @return array{month:int,year:int}|null
 */
function sr_get_period_from_bank_statement_date( $date ) {
	$date = trim( (string) $date );
	if ( '' === $date ) {
		return null;
	}

	$formats = array(
		'd.m.Y',
		'd-m-Y',
		'd/m/Y',
		'Y-m-d',
		'Y/m/d',
	);

	foreach ( $formats as $format ) {
		$parsed = DateTime::createFromFormat( $format, $date );
		if ( $parsed instanceof DateTime ) {
			return array(
				'month' => (int) $parsed->format( 'n' ),
				'year'  => (int) $parsed->format( 'Y' ),
			);
		}
	}

	$timestamp = strtotime( $date );
	if ( false !== $timestamp ) {
		return array(
			'month' => (int) date( 'n', $timestamp ),
			'year'  => (int) date( 'Y', $timestamp ),
		);
	}

	return null;
}

/**
 * Build label for bank statement options.
 *
 * @param object $row Bank statement row.
 * @return string
 */
function sr_get_bank_statement_label( $row ) {
	$date   = isset( $row->Dato ) ? $row->Dato : '';
	$text   = isset( $row->Tekst ) ? $row->Tekst : '';
	$amount = isset( $row->Beløb ) ? $row->Beløb : 0;

	$amount_label = number_format( (float) $amount, 2, ',', '.' );
	$text = trim( (string) $text );
	$date = trim( (string) $date );

	if ( '' === $text ) {
		return sprintf( '%s (%s kr.)', $date, $amount_label );
	}

	return sprintf( '%s - %s (%s kr.)', $date, $text, $amount_label );
}

/**
 * Render pagination links for admin tables.
 *
 * @param string $base_url    Base URL for links.
 * @param int    $page        Current page.
 * @param int    $total_pages Total pages.
 * @param string $page_param  Query param key for pagination.
 */
function sr_render_pagination( $base_url, $page, $total_pages, $page_param = 'sr_page' ) {
	if ( $total_pages < 2 ) {
		return;
	}

	$first_link = add_query_arg( $page_param, 1, $base_url );
	$prev_link  = add_query_arg( $page_param, max( 1, $page - 1 ), $base_url );
	$next_link  = add_query_arg( $page_param, min( $total_pages, $page + 1 ), $base_url );
	$last_link  = add_query_arg( $page_param, $total_pages, $base_url );

	echo '<div class="tablenav"><div class="tablenav-pages">';
	echo '<span class="pagination-links">';
	echo $page > 1 ? '<a class="first-page button" href="' . esc_url( $first_link ) . '">&laquo;</a>' : '<span class="tablenav-pages-navspan button disabled">&laquo;</span>';
	echo $page > 1 ? '<a class="prev-page button" href="' . esc_url( $prev_link ) . '">&lsaquo;</a>' : '<span class="tablenav-pages-navspan button disabled">&lsaquo;</span>';
	echo '<span class="paging-input">' . esc_html( $page ) . ' / <span class="total-pages">' . esc_html( $total_pages ) . '</span></span>';
	echo $page < $total_pages ? '<a class="next-page button" href="' . esc_url( $next_link ) . '">&rsaquo;</a>' : '<span class="tablenav-pages-navspan button disabled">&rsaquo;</span>';
	echo $page < $total_pages ? '<a class="last-page button" href="' . esc_url( $last_link ) . '">&raquo;</a>' : '<span class="tablenav-pages-navspan button disabled">&raquo;</span>';
	echo '</span></div></div>';
}

/**
 * Normalize decimal input, supporting comma decimals and thousands separators.
 *
 * @param mixed $value Raw input value.
 * @return float
 */
function sr_normalize_decimal_input( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return 0.0;
	}
	if ( str_contains( $value, ',' ) ) {
		$value = str_replace( '.', '', $value );
		$value = str_replace( ',', '.', $value );
	}
	$value = str_replace( ' ', '', $value );
	return (float) $value;
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
 * Get calculated resident account rows.
 *
 * @param int $resident_id Resident ID.
 * @return array<int, array<string, mixed>>
 */
function sr_get_resident_account_rows( $resident_id ) {
	global $wpdb;
	$table_readings = $wpdb->prefix . 'sr_meter_readings';
	$table_payments = $wpdb->prefix . 'sr_payments';

	$rows               = array();
	$payments_by_period = array();
	$payment_rows       = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT period_month, period_year, SUM(amount) AS total_amount
			FROM {$table_payments}
			WHERE resident_id = %d AND status = 'verified'
			GROUP BY period_year, period_month",
			$resident_id
		)
	);
	foreach ( $payment_rows as $payment_row ) {
		$key                         = $payment_row->period_year . '-' . $payment_row->period_month;
		$payments_by_period[ $key ] = (float) $payment_row->total_amount;
	}

	$readings = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table_readings} WHERE resident_id = %d AND status = 'verified' ORDER BY period_year ASC, period_month ASC",
			$resident_id
		)
	);

	if ( count( $readings ) > 1 ) {
		for ( $i = 1; $i < count( $readings ); $i++ ) {
			$previous = $readings[ $i - 1 ];
			$current  = $readings[ $i ];

			$months_diff = sr_get_months_diff( $previous->period_month, $previous->period_year, $current->period_month, $current->period_year );
			if ( $months_diff < 1 ) {
				continue;
			}

			$total_consumption  = max( 0, (float) $current->reading_kwh - (float) $previous->reading_kwh );
			$period_consumption = $total_consumption / $months_diff;

			for ( $offset = 1; $offset <= $months_diff; $offset++ ) {
				$period = sr_add_months_to_period( $previous->period_month, $previous->period_year, $offset );
				$price  = sr_get_price_for_period( $period['month'], $period['year'] );
				$cost   = null === $price ? null : $period_consumption * (float) $price;
				$rows[] = array(
					'period_month' => $period['month'],
					'period_year'  => $period['year'],
					'consumption'  => $period_consumption,
					'price'        => $price,
					'cost'         => $cost,
					'payments'     => 0.0,
					'balance'      => null,
				);
			}
		}
	}

	$running_balance = 0.0;
	foreach ( $rows as $index => $row ) {
		$key                        = $row['period_year'] . '-' . $row['period_month'];
		$payments_total             = $payments_by_period[ $key ] ?? 0.0;
		$rows[ $index ]['payments'] = $payments_total;

		if ( null === $row['cost'] ) {
			continue;
		}

		$running_balance          += $payments_total - (float) $row['cost'];
		$rows[ $index ]['balance'] = $running_balance;
	}

	return array_reverse( $rows );
}

/**
 * Get current balance status for a resident.
 *
 * @param int $resident_id Resident ID.
 * @return float|null
 */
function sr_get_resident_balance_status( $resident_id ) {
	$rows = sr_get_resident_account_rows( $resident_id );
	foreach ( $rows as $row ) {
		if ( null !== $row['balance'] ) {
			return (float) $row['balance'];
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
	$table_residents = $wpdb->prefix . 'sr_residents';
	$per_page        = 20;
	$readings_page   = sr_get_paged_param( 'sr_readings_page' );
	$payments_page   = sr_get_paged_param( 'sr_payments_page' );

	if ( isset( $_POST['sr_verify_pending_reading'] ) ) {
		check_admin_referer( 'sr_verify_pending_reading_action', 'sr_verify_pending_reading_nonce' );
		$reading_id = absint( $_POST['reading_id'] ?? 0 );
		if ( $reading_id ) {
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
				sr_generate_summary_for_reading( $reading->resident_id, $reading->period_month, $reading->period_year );
				sr_log_action( 'verify', 'reading', $reading_id, 'Målerstand verificeret' );
				sr_notify_resident_verified( $reading->resident_id, 'målerstand' );
			}
		}
	}

	if ( isset( $_POST['sr_delete_pending_reading'] ) ) {
		check_admin_referer( 'sr_delete_pending_reading_action', 'sr_delete_pending_reading_nonce' );
		$reading_id = absint( $_POST['reading_id'] ?? 0 );
		if ( $reading_id ) {
			$reading = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_readings} WHERE id = %d", $reading_id ) );
			if ( $reading ) {
				if ( 'verified' === $reading->status ) {
					sr_delete_summary_for_reading( $reading->resident_id, $reading->period_month, $reading->period_year );
				}
				$wpdb->delete( $table_readings, array( 'id' => $reading_id ), array( '%d' ) );
				sr_log_action( 'delete', 'reading', $reading_id, 'Målerstand slettet' );
			}
		}
	}

	if ( isset( $_POST['sr_verify_pending_payment'] ) ) {
		check_admin_referer( 'sr_verify_pending_payment_action', 'sr_verify_pending_payment_nonce' );
		$payment_id = absint( $_POST['payment_id'] ?? 0 );
		if ( $payment_id ) {
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
				sr_notify_resident_verified( $payment->resident_id, 'indbetaling' );
			}
		}
	}

	if ( isset( $_POST['sr_delete_pending_payment'] ) ) {
		check_admin_referer( 'sr_delete_pending_payment_action', 'sr_delete_pending_payment_nonce' );
		$payment_id = absint( $_POST['payment_id'] ?? 0 );
		if ( $payment_id ) {
			$wpdb->delete( $table_payments, array( 'id' => $payment_id ), array( '%d' ) );
			sr_log_action( 'delete', 'payment', $payment_id, 'Indbetaling slettet' );
		}
	}

	$pending_readings = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_readings} WHERE status = 'pending'" );
	$pending_payments = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_payments} WHERE status = 'pending'" );
	$readings_pages   = (int) max( 1, ceil( $pending_readings / $per_page ) );
	$payments_pages   = (int) max( 1, ceil( $pending_payments / $per_page ) );
	$readings_offset  = ( $readings_page - 1 ) * $per_page;
	$payments_offset  = ( $payments_page - 1 ) * $per_page;

	$pending_readings_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table_readings} WHERE status = 'pending' ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
			$per_page,
			$readings_offset
		)
	);
	$pending_payments_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table_payments} WHERE status = 'pending' ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
			$per_page,
			$payments_offset
		)
	);
	?>
	<div class="wrap">
		<h1>Strømregnskab</h1>
		<p>Afventende målerstande: <strong><?php echo esc_html( $pending_readings ); ?></strong></p>
		<p>Afventende indbetalinger: <strong><?php echo esc_html( $pending_payments ); ?></strong></p>

		<h2>Afventende målerstande</h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Dato</th>
					<th>Beboer</th>
					<th>Periode</th>
					<th>Målerstand</th>
					<th>Verificer</th>
					<th>Slet</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( $pending_readings_rows ) : ?>
					<?php foreach ( $pending_readings_rows as $reading ) : ?>
						<?php
						$resident_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table_residents} WHERE id = %d", $reading->resident_id ) );
						?>
						<tr>
							<td><?php echo esc_html( $reading->submitted_at ); ?></td>
							<td><?php echo esc_html( $resident_name ); ?></td>
							<td><?php echo esc_html( $reading->period_month . '/' . $reading->period_year ); ?></td>
							<td><?php echo esc_html( $reading->reading_kwh ); ?></td>
							<td>
								<form method="post">
									<?php wp_nonce_field( 'sr_verify_pending_reading_action', 'sr_verify_pending_reading_nonce' ); ?>
									<input type="hidden" name="reading_id" value="<?php echo esc_attr( $reading->id ); ?>">
									<button type="submit" name="sr_verify_pending_reading" class="button button-primary">Verificer</button>
								</form>
							</td>
							<td>
								<form method="post">
									<?php wp_nonce_field( 'sr_delete_pending_reading_action', 'sr_delete_pending_reading_nonce' ); ?>
									<input type="hidden" name="reading_id" value="<?php echo esc_attr( $reading->id ); ?>">
									<button
										type="submit"
										name="sr_delete_pending_reading"
										class="button button-link-delete sr-delete-row"
										data-summary="<?php echo esc_attr( 'Målerstand: ' . $resident_name . ', ' . $reading->period_month . '/' . $reading->period_year . ' (' . $reading->reading_kwh . ' kWh)' ); ?>"
									>Slet</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="6">Ingen afventende målerstande.</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php sr_render_pagination( admin_url( 'admin.php?page=' . SR_PLUGIN_SLUG ), $readings_page, $readings_pages, 'sr_readings_page' ); ?>

		<h2>Afventende indbetalinger</h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Dato</th>
					<th>Beboer</th>
					<th>Periode</th>
					<th>Beløb</th>
					<th>Verificer</th>
					<th>Slet</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( $pending_payments_rows ) : ?>
					<?php foreach ( $pending_payments_rows as $payment ) : ?>
						<?php
						$resident_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table_residents} WHERE id = %d", $payment->resident_id ) );
						?>
						<tr>
							<td><?php echo esc_html( $payment->submitted_at ); ?></td>
							<td><?php echo esc_html( $resident_name ); ?></td>
							<td><?php echo esc_html( $payment->period_month . '/' . $payment->period_year ); ?></td>
							<td><?php echo esc_html( $payment->amount ); ?></td>
							<td>
								<form method="post">
									<?php wp_nonce_field( 'sr_verify_pending_payment_action', 'sr_verify_pending_payment_nonce' ); ?>
									<input type="hidden" name="payment_id" value="<?php echo esc_attr( $payment->id ); ?>">
									<button type="submit" name="sr_verify_pending_payment" class="button button-primary">Verificer</button>
								</form>
							</td>
							<td>
								<form method="post">
									<?php wp_nonce_field( 'sr_delete_pending_payment_action', 'sr_delete_pending_payment_nonce' ); ?>
									<input type="hidden" name="payment_id" value="<?php echo esc_attr( $payment->id ); ?>">
									<button
										type="submit"
										name="sr_delete_pending_payment"
										class="button button-link-delete sr-delete-row"
										data-summary="<?php echo esc_attr( 'Indbetaling: ' . $resident_name . ', ' . $payment->period_month . '/' . $payment->period_year . ' (' . $payment->amount . ' kr.)' ); ?>"
									>Slet</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="6">Ingen afventende indbetalinger.</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php sr_render_pagination( admin_url( 'admin.php?page=' . SR_PLUGIN_SLUG ), $payments_page, $payments_pages, 'sr_payments_page' ); ?>
	</div>
	<script>
		(function () {
			document.querySelectorAll('.sr-delete-row').forEach((button) => {
				button.addEventListener('click', (event) => {
					const summary = button.dataset.summary || '';
					const message = summary
						? `Er du sikker på, at du vil slette denne række?\n${summary}`
						: 'Er du sikker på, at du vil slette denne række?';
					if (!window.confirm(message)) {
						event.preventDefault();
					}
				});
			});
		}());
	</script>
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
	$per_page        = 20;
	$current_page    = sr_get_paged_param( 'sr_page' );
	$total_items     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_residents}" );
	$total_pages     = (int) max( 1, ceil( $total_items / $per_page ) );
	$offset          = ( $current_page - 1 ) * $per_page;
	$duplicate_owner = null;

	if ( isset( $_POST['sr_add_resident'] ) ) {
		check_admin_referer( 'sr_add_resident_action', 'sr_add_resident_nonce' );
		$name         = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$member_num   = sanitize_text_field( wp_unslash( $_POST['member_number'] ?? '' ) );
		$wp_user_id   = absint( $_POST['wp_user_id'] ?? 0 );
		$resident_id  = absint( $_POST['resident_id'] ?? 0 );

		if ( $name && $member_num ) {
			$existing_member = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, name FROM {$table_residents} WHERE member_number = %s",
					$member_num
				)
			);

			if ( $existing_member && ( ! $resident_id || (int) $existing_member->id !== $resident_id ) ) {
				$duplicate_owner = $existing_member;
			} elseif ( $resident_id ) {
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
			} else {
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
	}

	if ( isset( $_POST['sr_delete_resident'] ) ) {
		check_admin_referer( 'sr_delete_resident_action', 'sr_delete_resident_nonce' );
		$resident_id = absint( $_POST['resident_id'] ?? 0 );
		if ( $resident_id ) {
			$wpdb->delete( $wpdb->prefix . 'sr_meter_readings', array( 'resident_id' => $resident_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'sr_payments', array( 'resident_id' => $resident_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'sr_monthly_summary', array( 'resident_id' => $resident_id ), array( '%d' ) );
			$wpdb->delete( $table_residents, array( 'id' => $resident_id ), array( '%d' ) );
			sr_log_action( 'delete', 'resident', $resident_id, 'Beboer slettet' );
		}
	}

	$residents = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_residents} ORDER BY name ASC LIMIT %d OFFSET %d", $per_page, $offset ) );
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
					<th>Slet</th>
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
					<td>
						<form method="post">
							<?php wp_nonce_field( 'sr_delete_resident_action', 'sr_delete_resident_nonce' ); ?>
							<input type="hidden" name="resident_id" value="<?php echo esc_attr( $resident->id ); ?>">
							<button
								type="submit"
								name="sr_delete_resident"
								class="button button-link-delete sr-delete-row"
								data-summary="<?php echo esc_attr( 'Beboer: ' . $resident->name . ' (Medlemsnr: ' . $resident->member_number . ')' ); ?>"
							>Slet</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php sr_render_pagination( admin_url( 'admin.php?page=' . SR_PLUGIN_SLUG . '-residents' ), $current_page, $total_pages ); ?>
	</div>
	<?php if ( $duplicate_owner ) : ?>
		<script>
			window.addEventListener('load', () => {
				window.alert(
					'Medlemsnummeret <?php echo esc_js( $member_num ); ?> tilhører allerede <?php echo esc_js( $duplicate_owner->name ); ?>. Ingen ændringer blev gemt.'
				);
			});
		</script>
	<?php endif; ?>
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
	<script>
		(function () {
			document.querySelectorAll('.sr-delete-row').forEach((button) => {
				button.addEventListener('click', (event) => {
					const summary = button.dataset.summary || '';
					const message = summary
						? `Er du sikker på, at du vil slette denne række?\n${summary}`
						: 'Er du sikker på, at du vil slette denne række?';
					if (!window.confirm(message)) {
						event.preventDefault();
					}
				});
			});
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
	$per_page        = 20;
	$current_page    = sr_get_paged_param( 'sr_page' );
	$total_items     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_readings}" );
	$total_pages     = (int) max( 1, ceil( $total_items / $per_page ) );
	$offset          = ( $current_page - 1 ) * $per_page;
	$current_month   = (int) current_time( 'n' );
	$current_year    = (int) current_time( 'Y' );

	if ( isset( $_POST['sr_add_reading'] ) ) {
		check_admin_referer( 'sr_add_reading_action', 'sr_add_reading_nonce' );
		$resident_id = absint( $_POST['resident_id'] ?? 0 );
		$month       = absint( $_POST['period_month'] ?? 0 );
		$year        = absint( $_POST['period_year'] ?? 0 );
		$reading     = (float) ( $_POST['reading_kwh'] ?? 0 );
		$reading_id  = absint( $_POST['reading_id'] ?? 0 );
		$is_verified = ! empty( $_POST['reading_verified'] );

		if ( $resident_id && $month && $year && $reading_id && ! sr_is_period_locked( $month, $year ) ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$table_readings} WHERE id = %d", $reading_id ) );
			if ( $existing ) {
				$new_status = $is_verified ? 'verified' : 'pending';
				$wpdb->update(
					$table_readings,
					array(
						'resident_id' => $resident_id,
						'period_month'=> $month,
						'period_year' => $year,
						'reading_kwh' => $reading,
						'status'      => $new_status,
					),
					array( 'id' => $reading_id ),
					array( '%d', '%d', '%d', '%f', '%s' ),
					array( '%d' )
				);
				if ( $is_verified ) {
					$wpdb->update(
						$table_readings,
						array(
							'verified_by' => get_current_user_id(),
							'verified_at' => sr_now(),
						),
						array( 'id' => $reading_id ),
						array( '%d', '%s' ),
						array( '%d' )
					);
				} else {
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$table_readings} SET verified_by = NULL, verified_at = NULL WHERE id = %d",
							$reading_id
						)
					);
				}
				sr_log_action( 'update', 'reading', $reading_id, 'Admin opdatering' );
				if ( $is_verified ) {
					sr_generate_summary_for_reading( $resident_id, $month, $year );
				}
				if ( 'pending' === $existing->status && $is_verified ) {
					sr_log_action( 'verify', 'reading', $reading_id, 'Målerstand verificeret' );
					sr_notify_resident_verified( $resident_id, 'målerstand' );
				} elseif ( 'verified' === $existing->status && ! $is_verified ) {
					sr_log_action( 'unverify', 'reading', $reading_id, 'Målerstand markeret som ikke verificeret' );
					sr_delete_summary_for_reading( $resident_id, $month, $year );
				}
			}
		} elseif ( $resident_id && $month && $year && ! sr_is_period_locked( $month, $year ) ) {
			$status = $is_verified ? 'verified' : 'pending';
			$insert_data = array(
				'resident_id' => $resident_id,
				'period_month'=> $month,
				'period_year' => $year,
				'reading_kwh' => $reading,
				'status'      => $status,
				'submitted_by'=> get_current_user_id(),
				'submitted_at'=> sr_now(),
			);
			$insert_format = array( '%d', '%d', '%d', '%f', '%s', '%d', '%s' );
			if ( $is_verified ) {
				$insert_data['verified_by'] = get_current_user_id();
				$insert_data['verified_at'] = sr_now();
				$insert_format[] = '%d';
				$insert_format[] = '%s';
			}
			$wpdb->insert(
				$table_readings,
				$insert_data,
				$insert_format
			);
			sr_log_action( 'create', 'reading', $wpdb->insert_id, 'Admin indtastning' );
			if ( $is_verified ) {
				sr_generate_summary_for_reading( $resident_id, $month, $year );
				sr_log_action( 'verify', 'reading', $wpdb->insert_id, 'Målerstand verificeret' );
				sr_notify_resident_verified( $resident_id, 'målerstand' );
			}
		}
	}

	if ( isset( $_POST['sr_delete_reading'] ) ) {
		check_admin_referer( 'sr_delete_reading_action', 'sr_delete_reading_nonce' );
		$reading_id = absint( $_POST['reading_id'] ?? 0 );
		if ( $reading_id ) {
			$reading = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_readings} WHERE id = %d", $reading_id ) );
			if ( $reading ) {
				if ( 'verified' === $reading->status ) {
					sr_delete_summary_for_reading( $reading->resident_id, $reading->period_month, $reading->period_year );
				}
				$wpdb->delete( $table_readings, array( 'id' => $reading_id ), array( '%d' ) );
				sr_log_action( 'delete', 'reading', $reading_id, 'Målerstand slettet' );
			}
		}
	}

	$residents = $wpdb->get_results( "SELECT * FROM {$table_residents} ORDER BY name ASC" );
	$readings  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_readings} ORDER BY submitted_at DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
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
				<tr>
					<th scope="row">Verificeret</th>
					<td>
						<label>
							<input type="checkbox" name="reading_verified" id="sr_reading_verified" value="1">
							Marker som verificeret
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Indtast', 'primary', 'sr_add_reading', false, array( 'id' => 'sr_reading_submit' ) ); ?>
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
					<th>Slet</th>
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
								data-status="<?php echo esc_attr( $reading->status ); ?>"
							>Rediger</button>
						</td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'sr_delete_reading_action', 'sr_delete_reading_nonce' ); ?>
								<input type="hidden" name="reading_id" value="<?php echo esc_attr( $reading->id ); ?>">
								<button
									type="submit"
									name="sr_delete_reading"
									class="button button-link-delete sr-delete-row"
									data-summary="<?php echo esc_attr( 'Målerstand: ' . $resident_name . ', ' . $reading->period_month . '/' . $reading->period_year . ' (' . $reading->reading_kwh . ' kWh)' ); ?>"
								>Slet</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php sr_render_pagination( admin_url( 'admin.php?page=' . SR_PLUGIN_SLUG . '-readings' ), $current_page, $total_pages ); ?>
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
			const verifiedField = document.getElementById('sr_reading_verified');
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
					if (verifiedField) {
						verifiedField.checked = button.dataset.status === 'verified';
					}
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
					if (verifiedField) {
						verifiedField.checked = false;
					}
					if (submitButton) {
						submitButton.value = defaultLabel;
					}
					cancelButton.style.display = 'none';
				});
			}
		}());
	</script>
	<script>
		(function () {
			document.querySelectorAll('.sr-delete-row').forEach((button) => {
				button.addEventListener('click', (event) => {
					const summary = button.dataset.summary || '';
					const message = summary
						? `Er du sikker på, at du vil slette denne række?\n${summary}`
						: 'Er du sikker på, at du vil slette denne række?';
					if (!window.confirm(message)) {
						event.preventDefault();
					}
				});
			});
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
	$table_bank_statements = $wpdb->prefix . 'sr_bank_statements';
	$per_page        = 20;
	$current_page    = sr_get_paged_param( 'sr_page' );
	$total_items     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_payments}" );
	$total_pages     = (int) max( 1, ceil( $total_items / $per_page ) );
	$offset          = ( $current_page - 1 ) * $per_page;
	$current_month   = (int) current_time( 'n' );
	$current_year    = (int) current_time( 'Y' );
	$message         = '';

	if ( isset( $_POST['sr_add_payment'] ) ) {
		check_admin_referer( 'sr_add_payment_action', 'sr_add_payment_nonce' );
		$resident_id = absint( $_POST['resident_id'] ?? 0 );
		$month       = absint( $_POST['period_month'] ?? 0 );
		$year        = absint( $_POST['period_year'] ?? 0 );
		$amount      = sr_normalize_decimal_input( $_POST['amount'] ?? 0 );
		$bank_statement_id = absint( $_POST['bank_statement_id'] ?? 0 );
		$payment_id  = absint( $_POST['payment_id'] ?? 0 );
		$is_verified = ! empty( $_POST['payment_verified'] );
		$bank_statement_id = $bank_statement_id ? $bank_statement_id : null;
		$skip_payment_update = false;
		$bank_statement_amount = null;

		if ( $bank_statement_id ) {
			$bank_statement = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table_bank_statements} WHERE id = %d",
					$bank_statement_id
				)
			);
			if ( ! $bank_statement ) {
				$message = '<div class="notice notice-error"><p>Det valgte bankudtog findes ikke længere.</p></div>';
				$bank_statement_id = null;
			}

			if ( $bank_statement_id ) {
				$bank_statement_amount = sr_normalize_decimal_input( $bank_statement->Beløb ?? 0 );
				$period = sr_get_period_from_bank_statement_date( $bank_statement->Dato );
				if ( ! $period ) {
					$message = '<div class="notice notice-error"><p>Kunne ikke aflæse datoen fra bankudtoget.</p></div>';
					$skip_payment_update = true;
				} elseif ( sr_is_period_locked( $period['month'], $period['year'] ) ) {
					$message = '<div class="notice notice-error"><p>Perioden er låst og kan ikke bruges til indbetaling.</p></div>';
					$skip_payment_update = true;
				} else {
					$month = $period['month'];
					$year  = $period['year'];
				}
			}

			if ( $bank_statement_id && ! $skip_payment_update ) {
				$linked_payment = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$table_payments} WHERE bank_statement_id = %d AND id != %d",
						$bank_statement_id,
						$payment_id
					)
				);
				if ( $linked_payment ) {
					$message = '<div class="notice notice-error"><p>Bankudtoget er allerede tilknyttet en anden indbetaling.</p></div>';
					$bank_statement_id = null;
				}
			}
		}

		if ( null !== $bank_statement_amount ) {
			$amount = $bank_statement_amount;
		}

		if ( $resident_id && $month && $year && $payment_id && ! $skip_payment_update && ! sr_is_period_locked( $month, $year ) ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table_payments} WHERE id = %d", $payment_id ) );
			if ( $existing ) {
				$new_status = $is_verified ? 'verified' : 'pending';
				$wpdb->update(
					$table_payments,
					array(
						'resident_id' => $resident_id,
						'bank_statement_id' => $bank_statement_id,
						'period_month'=> $month,
						'period_year' => $year,
						'amount'      => $amount,
						'status'      => $new_status,
					),
					array( 'id' => $payment_id ),
					array( '%d', '%d', '%d', '%d', '%f', '%s' ),
					array( '%d' )
				);
				if ( $is_verified ) {
					$wpdb->update(
						$table_payments,
						array(
							'verified_by' => get_current_user_id(),
							'verified_at' => sr_now(),
						),
						array( 'id' => $payment_id ),
						array( '%d', '%s' ),
						array( '%d' )
					);
				} else {
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$table_payments} SET verified_by = NULL, verified_at = NULL WHERE id = %d",
							$payment_id
						)
					);
				}
				sr_log_action( 'update', 'payment', $payment_id, 'Admin opdatering' );
				if ( 'pending' === $existing->status && $is_verified ) {
					sr_log_action( 'verify', 'payment', $payment_id, 'Indbetaling verificeret' );
					sr_notify_resident_verified( $resident_id, 'indbetaling' );
				} elseif ( 'verified' === $existing->status && ! $is_verified ) {
					sr_log_action( 'unverify', 'payment', $payment_id, 'Indbetaling markeret som ikke verificeret' );
				}
			}
		} elseif ( $resident_id && $month && $year && ! $skip_payment_update && ! sr_is_period_locked( $month, $year ) ) {
			$status = $is_verified ? 'verified' : 'pending';
			$insert_data = array(
				'resident_id' => $resident_id,
				'bank_statement_id' => $bank_statement_id,
				'period_month'=> $month,
				'period_year' => $year,
				'amount'      => $amount,
				'status'      => $status,
				'submitted_by'=> get_current_user_id(),
				'submitted_at'=> sr_now(),
			);
			$insert_format = array( '%d', '%d', '%d', '%d', '%f', '%s', '%d', '%s' );
			if ( $is_verified ) {
				$insert_data['verified_by'] = get_current_user_id();
				$insert_data['verified_at'] = sr_now();
				$insert_format[] = '%d';
				$insert_format[] = '%s';
			}
			$wpdb->insert(
				$table_payments,
				$insert_data,
				$insert_format
			);
			sr_log_action( 'create', 'payment', $wpdb->insert_id, 'Admin indtastning' );
			if ( $is_verified ) {
				sr_log_action( 'verify', 'payment', $wpdb->insert_id, 'Indbetaling verificeret' );
				sr_notify_resident_verified( $resident_id, 'indbetaling' );
			}
		}
	}

	if ( isset( $_POST['sr_update_payment_bank_statement'] ) ) {
		check_admin_referer( 'sr_update_payment_bank_statement_action', 'sr_update_payment_bank_statement_nonce' );
		$payment_id        = absint( $_POST['payment_id'] ?? 0 );
		$bank_statement_id = absint( $_POST['bank_statement_id'] ?? 0 );
		$bank_statement_id = $bank_statement_id ? $bank_statement_id : null;
		$period_month      = null;
		$period_year       = null;
		$bank_statement_amount = null;

		if ( $payment_id ) {
			if ( $bank_statement_id ) {
				$bank_statement = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$table_bank_statements} WHERE id = %d",
						$bank_statement_id
					)
				);
				if ( ! $bank_statement ) {
					$message = '<div class="notice notice-error"><p>Det valgte bankudtog findes ikke længere.</p></div>';
					$bank_statement_id = null;
				}

				if ( $bank_statement_id ) {
					$bank_statement_amount = sr_normalize_decimal_input( $bank_statement->Beløb ?? 0 );
					$period = sr_get_period_from_bank_statement_date( $bank_statement->Dato );
					if ( ! $period ) {
						$message = '<div class="notice notice-error"><p>Kunne ikke aflæse datoen fra bankudtoget.</p></div>';
					} elseif ( sr_is_period_locked( $period['month'], $period['year'] ) ) {
						$message = '<div class="notice notice-error"><p>Perioden er låst og kan ikke bruges til indbetaling.</p></div>';
					} else {
						$period_month = $period['month'];
						$period_year  = $period['year'];
					}
				}

				if ( $bank_statement_id && '' === $message ) {
					$linked_payment = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM {$table_payments} WHERE bank_statement_id = %d AND id != %d",
							$bank_statement_id,
							$payment_id
						)
					);
					if ( $linked_payment ) {
						$message = '<div class="notice notice-error"><p>Bankudtoget er allerede tilknyttet en anden indbetaling.</p></div>';
						$bank_statement_id = null;
					}
				}
			}

			if ( '' === $message ) {
				$update_data = array( 'bank_statement_id' => $bank_statement_id );
				$update_format = array( '%d' );
				if ( null !== $period_month && null !== $period_year ) {
					$update_data['period_month'] = $period_month;
					$update_data['period_year']  = $period_year;
					$update_format[] = '%d';
					$update_format[] = '%d';
				}
				if ( null !== $bank_statement_amount ) {
					$update_data['amount'] = $bank_statement_amount;
					$update_format[] = '%f';
				}
				$wpdb->update(
					$table_payments,
					$update_data,
					array( 'id' => $payment_id ),
					$update_format,
					array( '%d' )
				);
				sr_log_action( 'update', 'payment', $payment_id, 'Bankudtog tilknytning opdateret' );
				$message = '<div class="notice notice-success"><p>Bankudtogstilknytningen er opdateret.</p></div>';
			}
		}
	}

	if ( isset( $_POST['sr_delete_payment'] ) ) {
		check_admin_referer( 'sr_delete_payment_action', 'sr_delete_payment_nonce' );
		$payment_id = absint( $_POST['payment_id'] ?? 0 );
		if ( $payment_id ) {
			$wpdb->delete( $table_payments, array( 'id' => $payment_id ), array( '%d' ) );
			sr_log_action( 'delete', 'payment', $payment_id, 'Indbetaling slettet' );
		}
	}

	$residents = $wpdb->get_results( "SELECT * FROM {$table_residents} ORDER BY name ASC" );
	$payments  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_payments} ORDER BY submitted_at DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
	$unlinked_bank_statements = $wpdb->get_results(
		"SELECT b.*
			FROM {$table_bank_statements} b
			LEFT JOIN {$table_payments} p ON b.id = p.bank_statement_id
			WHERE p.id IS NULL
			ORDER BY COALESCE(
				STR_TO_DATE(b.`Dato`, '%d-%m-%Y'),
				STR_TO_DATE(b.`Dato`, '%d/%m/%Y'),
				STR_TO_DATE(b.`Dato`, '%d.%m.%Y'),
				STR_TO_DATE(b.`Dato`, '%Y-%m-%d'),
				b.created_at
			) DESC, b.id DESC"
	);

	$linked_bank_statement_ids = array();
	foreach ( $payments as $payment ) {
		if ( ! empty( $payment->bank_statement_id ) ) {
			$linked_bank_statement_ids[] = (int) $payment->bank_statement_id;
		}
	}
	$linked_bank_statement_ids = array_values( array_unique( $linked_bank_statement_ids ) );

	$linked_bank_statements = array();
	if ( ! empty( $linked_bank_statement_ids ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $linked_bank_statement_ids ), '%d' ) );
		$linked_bank_statements = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_bank_statements} WHERE id IN ({$placeholders})",
				$linked_bank_statement_ids
			)
		);
	}

	$linked_bank_statements_by_id = array();
	foreach ( $linked_bank_statements as $statement ) {
		$linked_bank_statements_by_id[ (int) $statement->id ] = $statement;
	}

	$unlinked_bank_statement_ids = array();
	foreach ( $unlinked_bank_statements as $statement ) {
		$unlinked_bank_statement_ids[] = (int) $statement->id;
	}
	?>
	<div class="wrap">
		<h1>Indbetalinger</h1>
		<?php echo wp_kses_post( $message ); ?>
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
					<th scope="row">Bankudtog</th>
					<td>
						<select name="bank_statement_id" id="sr_payment_bank_statement">
							<option value="">Vælg bankudtog</option>
							<?php foreach ( $unlinked_bank_statements as $statement ) : ?>
								<option value="<?php echo esc_attr( $statement->id ); ?>" data-amount="<?php echo esc_attr( $statement->Beløb ); ?>">
									<?php echo esc_html( sr_get_bank_statement_label( $statement ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">Viser kun utilknyttede bankudtog.</p>
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
				<tr>
					<th scope="row">Verificeret</th>
					<td>
						<label>
							<input type="checkbox" name="payment_verified" id="sr_payment_verified" value="1">
							Marker som verificeret
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Indtast', 'primary', 'sr_add_payment', false, array( 'id' => 'sr_payment_submit' ) ); ?>
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
					<th>Bankudtog</th>
					<th>Rediger</th>
					<th>Slet</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $payments as $payment ) : ?>
					<?php
					$resident_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table_residents} WHERE id = %d", $payment->resident_id ) );
					$bank_statement = null;
					$bank_statement_label = '';
					if ( ! empty( $payment->bank_statement_id ) ) {
						$bank_statement = $linked_bank_statements_by_id[ (int) $payment->bank_statement_id ] ?? null;
						if ( $bank_statement ) {
							$bank_statement_label = sr_get_bank_statement_label( $bank_statement );
						}
					}
					?>
					<tr>
						<td><?php echo esc_html( $payment->submitted_at ); ?></td>
						<td><?php echo esc_html( $resident_name ); ?></td>
						<td><?php echo esc_html( $payment->period_month . '/' . $payment->period_year ); ?></td>
						<td><?php echo esc_html( $payment->amount ); ?></td>
						<td><?php echo esc_html( $payment->status ); ?></td>
						<td>
							<form method="post" class="sr-inline-form">
								<?php wp_nonce_field( 'sr_update_payment_bank_statement_action', 'sr_update_payment_bank_statement_nonce' ); ?>
								<input type="hidden" name="payment_id" value="<?php echo esc_attr( $payment->id ); ?>">
								<select name="bank_statement_id">
									<option value="">Ingen</option>
									<?php foreach ( $unlinked_bank_statements as $statement ) : ?>
										<option value="<?php echo esc_attr( $statement->id ); ?>" <?php selected( (int) $payment->bank_statement_id, (int) $statement->id ); ?>>
											<?php echo esc_html( sr_get_bank_statement_label( $statement ) ); ?>
										</option>
									<?php endforeach; ?>
									<?php if ( $bank_statement && ! in_array( (int) $bank_statement->id, $unlinked_bank_statement_ids, true ) ) : ?>
										<option value="<?php echo esc_attr( $bank_statement->id ); ?>" selected>
											<?php echo esc_html( $bank_statement_label ); ?>
										</option>
									<?php endif; ?>
								</select>
								<button type="submit" name="sr_update_payment_bank_statement" class="button">Gem</button>
							</form>
						</td>
						<td>
							<button
								type="button"
								class="button sr-fill-payment"
								data-payment-id="<?php echo esc_attr( $payment->id ); ?>"
								data-resident-id="<?php echo esc_attr( $payment->resident_id ); ?>"
								data-period-month="<?php echo esc_attr( $payment->period_month ); ?>"
								data-period-year="<?php echo esc_attr( $payment->period_year ); ?>"
								data-amount="<?php echo esc_attr( $payment->amount ); ?>"
								data-status="<?php echo esc_attr( $payment->status ); ?>"
								data-bank-statement-id="<?php echo esc_attr( (int) $payment->bank_statement_id ); ?>"
								data-bank-statement-label="<?php echo esc_attr( $bank_statement_label ); ?>"
								data-bank-statement-amount="<?php echo esc_attr( $bank_statement ? $bank_statement->Beløb : '' ); ?>"
							>Rediger</button>
						</td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'sr_delete_payment_action', 'sr_delete_payment_nonce' ); ?>
								<input type="hidden" name="payment_id" value="<?php echo esc_attr( $payment->id ); ?>">
								<button
									type="submit"
									name="sr_delete_payment"
									class="button button-link-delete sr-delete-row"
									data-summary="<?php echo esc_attr( 'Indbetaling: ' . $resident_name . ', ' . $payment->period_month . '/' . $payment->period_year . ' (' . $payment->amount . ' kr.)' ); ?>"
								>Slet</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php sr_render_pagination( admin_url( 'admin.php?page=' . SR_PLUGIN_SLUG . '-payments' ), $current_page, $total_pages ); ?>
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
			const verifiedField = document.getElementById('sr_payment_verified');
			const bankStatementField = document.getElementById('sr_payment_bank_statement');
			const defaultLabel = submitButton ? submitButton.value : '';
			const currentMonth = form.dataset.currentMonth || '';
			const currentYear = form.dataset.currentYear || '';
			const updateAmountFromStatement = (statementField) => {
				if (!statementField || !amountField) {
					return;
				}
				const selectedOption = statementField.options[statementField.selectedIndex];
				if (!selectedOption) {
					return;
				}
				const optionAmount = selectedOption.dataset.amount;
				if (optionAmount) {
					amountField.value = optionAmount;
				}
			};

			document.querySelectorAll('.sr-fill-payment').forEach((button) => {
				button.addEventListener('click', () => {
					residentField.value = button.dataset.residentId || '';
					monthField.value = button.dataset.periodMonth || currentMonth;
					yearField.value = button.dataset.periodYear || currentYear;
					amountField.value = button.dataset.amount || '';
					paymentId.value = button.dataset.paymentId || '';
					if (verifiedField) {
						verifiedField.checked = button.dataset.status === 'verified';
					}
					if (bankStatementField) {
						const bankStatementId = button.dataset.bankStatementId || '';
						const bankStatementLabel = button.dataset.bankStatementLabel || '';
						const bankStatementAmount = button.dataset.bankStatementAmount || '';
						if (bankStatementId) {
							let option = bankStatementField.querySelector(`option[value="${bankStatementId}"]`);
							if (!option && bankStatementLabel) {
								option = document.createElement('option');
								option.value = bankStatementId;
								option.textContent = bankStatementLabel;
								if (bankStatementAmount) {
									option.dataset.amount = bankStatementAmount;
								}
								bankStatementField.appendChild(option);
							}
							bankStatementField.value = bankStatementId;
						} else {
							bankStatementField.value = '';
						}
					}
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
					if (verifiedField) {
						verifiedField.checked = false;
					}
					if (bankStatementField) {
						bankStatementField.value = '';
					}
					if (submitButton) {
						submitButton.value = defaultLabel;
					}
					cancelButton.style.display = 'none';
				});
			}

			if (bankStatementField) {
				bankStatementField.addEventListener('change', () => {
					updateAmountFromStatement(bankStatementField);
				});
			}
		}());
	</script>
	<script>
		(function () {
			document.querySelectorAll('.sr-delete-row').forEach((button) => {
				button.addEventListener('click', (event) => {
					const summary = button.dataset.summary || '';
					const message = summary
						? `Er du sikker på, at du vil slette denne række?\n${summary}`
						: 'Er du sikker på, at du vil slette denne række?';
					if (!window.confirm(message)) {
						event.preventDefault();
					}
				});
			});
		}());
	</script>
	<?php
}

/**
 * Render resident balances page.
 */
function sr_render_balances_page() {
	if ( ! current_user_can( SR_CAPABILITY_ADMIN ) ) {
		return;
	}

	global $wpdb;
	$table_residents = $wpdb->prefix . 'sr_residents';
	$table_payments  = $wpdb->prefix . 'sr_payments';
	$table_summary   = $wpdb->prefix . 'sr_monthly_summary';
	$per_page        = 20;
	$current_page    = sr_get_paged_param( 'sr_page' );
	$total_items     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_residents}" );
	$total_pages     = (int) max( 1, ceil( $total_items / $per_page ) );
	$offset          = ( $current_page - 1 ) * $per_page;

	$balances = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT r.id,
				r.name,
				r.member_number,
				COALESCE(pay.total_paid, 0) AS total_paid,
				COALESCE(
					(
						SELECT reading_kwh
						FROM {$wpdb->prefix}sr_meter_readings
						WHERE resident_id = r.id AND status = %s
						ORDER BY period_year DESC, period_month DESC
						LIMIT 1
					) - (
						SELECT reading_kwh
						FROM {$wpdb->prefix}sr_meter_readings
						WHERE resident_id = r.id AND status = %s
						ORDER BY period_year ASC, period_month ASC
						LIMIT 1
					),
					0
				) AS total_kwh
			FROM {$table_residents} r
			LEFT JOIN (
				SELECT resident_id, SUM(amount) AS total_paid
				FROM {$table_payments}
				WHERE status = %s
				GROUP BY resident_id
			) pay ON r.id = pay.resident_id
			ORDER BY r.name ASC
			LIMIT %d OFFSET %d",
			'verified',
			'verified',
			'verified',
			$per_page,
			$offset
		)
	);
	?>
	<div class="wrap">
		<h1>Beboer saldo</h1>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Beboer navn</th>
					<th>Medlemsnummer</th>
					<th>Totalt indbetalt</th>
					<th>Total kilowatt</th>
					<th>Saldo status</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $balances as $balance ) : ?>
					<?php
					$balance_status = sr_get_resident_balance_status( $balance->id );
					$balance_class  = '';
					if ( null !== $balance_status ) {
						$balance_class = $balance_status < 0 ? 'sr-negative' : 'sr-positive';
					}
					?>
					<tr>
						<td><?php echo esc_html( $balance->name ); ?></td>
						<td><?php echo esc_html( $balance->member_number ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $balance->total_paid, 2 ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $balance->total_kwh, 3 ) ); ?></td>
						<td class="<?php echo esc_attr( $balance_class ); ?>">
							<?php if ( null === $balance_status ) : ?>
								Ikke beregnet
							<?php else : ?>
								<?php echo esc_html( number_format_i18n( $balance_status, 2 ) ); ?> kr.
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php sr_render_pagination( admin_url( 'admin.php?page=' . SR_PLUGIN_SLUG . '-balances' ), $current_page, $total_pages ); ?>
	</div>
	<?php
}

/**
 * Render resident account page.
 */
function sr_render_resident_account_page() {
	if ( ! current_user_can( SR_CAPABILITY_ADMIN ) ) {
		return;
	}

	global $wpdb;
	$table_residents = $wpdb->prefix . 'sr_residents';

	$residents = $wpdb->get_results( "SELECT id, name, member_number FROM {$table_residents} ORDER BY member_number ASC" );

	$selected_member_number = '';
	$selected_from_select   = '';
	$selected_from_manual   = '';

	if ( isset( $_GET['member_number_select'] ) ) {
		$selected_from_select = sanitize_text_field( wp_unslash( $_GET['member_number_select'] ) );
	}
	if ( isset( $_GET['member_number_manual'] ) ) {
		$selected_from_manual = sanitize_text_field( wp_unslash( $_GET['member_number_manual'] ) );
	}

	if ( '' !== $selected_from_manual ) {
		$selected_member_number = $selected_from_manual;
	} else {
		$selected_member_number = $selected_from_select;
	}

	$resident = null;
	if ( '' !== $selected_member_number ) {
		$resident = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_residents} WHERE member_number = %s",
				$selected_member_number
			)
		);
	}

	$rows         = array();
	$resident_page = 1;
	$per_page      = 20;
	if ( $resident ) {
		$resident_page = sr_get_paged_param( 'sr_resident_account_page' );
		$rows          = sr_get_resident_account_rows( $resident->id );
	}

	$total_pages = 1;
	$paged_rows  = $rows;
	if ( ! empty( $rows ) ) {
		$total_pages  = (int) max( 1, ceil( count( $rows ) / $per_page ) );
		$resident_page = min( $resident_page, $total_pages );
		$offset       = ( $resident_page - 1 ) * $per_page;
		$paged_rows   = array_slice( $rows, $offset, $per_page );
	}
	?>
	<div class="wrap">
		<h1>Beboer regnskab</h1>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( SR_PLUGIN_SLUG . '-resident-account' ); ?>">
			<table class="form-table">
				<tr>
					<th scope="row">Vælg beboer (medlemsnummer)</th>
					<td>
						<select name="member_number_select">
							<option value="">Vælg beboer</option>
							<?php foreach ( $residents as $resident_option ) : ?>
								<option value="<?php echo esc_attr( $resident_option->member_number ); ?>" <?php selected( $selected_member_number, $resident_option->member_number ); ?>>
									<?php echo esc_html( $resident_option->member_number . ' - ' . $resident_option->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">Indtast medlemsnummer</th>
					<td>
						<input type="text" name="member_number_manual" value="<?php echo esc_attr( $selected_from_manual ); ?>" placeholder="Fx 12345">
					</td>
				</tr>
			</table>
			<?php submit_button( 'Vis regnskab', 'primary' ); ?>
		</form>

		<?php if ( '' !== $selected_member_number && ! $resident ) : ?>
			<p>Ingen beboer fundet for medlemsnummeret.</p>
		<?php endif; ?>

		<?php if ( $resident ) : ?>
			<h2>Regnskab for <?php echo esc_html( $resident->name ); ?> (<?php echo esc_html( $resident->member_number ); ?>)</h2>
			<?php if ( empty( $rows ) ) : ?>
				<p>Der kræves mindst to verificerede målerstande for at beregne perioder.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Periode</th>
							<th>Forbrug (kWh)</th>
							<th>Pris pr. kWh</th>
							<th>Beløb</th>
							<th>Indbetalinger</th>
							<th>Saldo status</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $paged_rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['period_month'] . '/' . $row['period_year'] ); ?></td>
								<td><?php echo esc_html( number_format( (float) $row['consumption'], 3, ',', '.' ) ); ?></td>
								<td>
									<?php if ( null === $row['price'] ) : ?>
										Ikke angivet
									<?php else : ?>
										<?php echo esc_html( number_format( (float) $row['price'], 4, ',', '.' ) ); ?>
									<?php endif; ?>
								</td>
								<?php
								$cost_class = '';
								if ( null !== $row['cost'] ) {
									$cost_class = $row['cost'] < 0 ? 'sr-negative' : 'sr-positive';
								}
								?>
								<td class="<?php echo esc_attr( $cost_class ); ?>">
									<?php if ( null === $row['cost'] ) : ?>
										Ikke beregnet
									<?php else : ?>
										<?php echo esc_html( number_format( (float) $row['cost'], 2, ',', '.' ) ); ?> kr.
									<?php endif; ?>
								</td>
								<?php
								$payments_class = $row['payments'] < 0 ? 'sr-negative' : 'sr-positive';
								?>
								<td class="<?php echo esc_attr( $payments_class ); ?>"><?php echo esc_html( number_format( (float) $row['payments'], 2, ',', '.' ) ); ?> kr.</td>
								<?php
								$balance_class = '';
								if ( null !== $row['balance'] ) {
									$balance_class = $row['balance'] < 0 ? 'sr-negative' : 'sr-positive';
								}
								?>
								<td class="<?php echo esc_attr( $balance_class ); ?>">
									<?php if ( null === $row['balance'] ) : ?>
										Ikke beregnet
									<?php else : ?>
										<?php echo esc_html( number_format( (float) $row['balance'], 2, ',', '.' ) ); ?> kr.
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php
				$pagination_base_url = admin_url( 'admin.php?page=' . SR_PLUGIN_SLUG . '-resident-account' );
				if ( '' !== $selected_from_select ) {
					$pagination_base_url = add_query_arg( 'member_number_select', $selected_from_select, $pagination_base_url );
				}
				if ( '' !== $selected_from_manual ) {
					$pagination_base_url = add_query_arg( 'member_number_manual', $selected_from_manual, $pagination_base_url );
				}
				sr_render_pagination( $pagination_base_url, $resident_page, $total_pages, 'sr_resident_account_page' );
				?>
			<?php endif; ?>
		<?php endif; ?>
	</div>
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
	$per_page     = 20;
	$current_page = sr_get_paged_param( 'sr_page' );
	$total_items  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_prices}" );
	$total_pages  = (int) max( 1, ceil( $total_items / $per_page ) );
	$offset       = ( $current_page - 1 ) * $per_page;
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

	if ( isset( $_POST['sr_delete_price'] ) ) {
		check_admin_referer( 'sr_delete_price_action', 'sr_delete_price_nonce' );
		$price_id = absint( $_POST['price_id'] ?? 0 );
		if ( $price_id ) {
			$wpdb->delete( $table_prices, array( 'id' => $price_id ), array( '%d' ) );
			sr_log_action( 'delete', 'price', $price_id, 'Pris slettet' );
		}
	}

	$prices = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_prices} ORDER BY period_year DESC, period_month DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
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
					<th>Slet</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $prices as $price ) : ?>
					<tr>
						<td><?php echo esc_html( $price->period_month . '/' . $price->period_year ); ?></td>
						<td><?php echo esc_html( $price->price_per_kwh ); ?></td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'sr_delete_price_action', 'sr_delete_price_nonce' ); ?>
								<input type="hidden" name="price_id" value="<?php echo esc_attr( $price->id ); ?>">
								<button
									type="submit"
									name="sr_delete_price"
									class="button button-link-delete sr-delete-row"
									data-summary="<?php echo esc_attr( 'Pris: ' . $price->period_month . '/' . $price->period_year . ' (' . $price->price_per_kwh . ' kr./kWh)' ); ?>"
								>Slet</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php sr_render_pagination( admin_url( 'admin.php?page=' . SR_PLUGIN_SLUG . '-prices' ), $current_page, $total_pages ); ?>
	</div>
	<script>
		(function () {
			document.querySelectorAll('.sr-delete-row').forEach((button) => {
				button.addEventListener('click', (event) => {
					const summary = button.dataset.summary || '';
					const message = summary
						? `Er du sikker på, at du vil slette denne række?\n${summary}`
						: 'Er du sikker på, at du vil slette denne række?';
					if (!window.confirm(message)) {
						event.preventDefault();
					}
				});
			});
		}());
	</script>
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
	$per_page     = 20;
	$current_page = sr_get_paged_param( 'sr_page' );
	$total_items  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_locks}" );
	$total_pages  = (int) max( 1, ceil( $total_items / $per_page ) );
	$offset       = ( $current_page - 1 ) * $per_page;
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

	if ( isset( $_POST['sr_delete_lock'] ) ) {
		check_admin_referer( 'sr_delete_lock_action', 'sr_delete_lock_nonce' );
		$lock_id = absint( $_POST['lock_id'] ?? 0 );
		if ( $lock_id ) {
			$wpdb->delete( $table_locks, array( 'id' => $lock_id ), array( '%d' ) );
			sr_log_action( 'delete', 'period_lock', $lock_id, 'Periodelåsning slettet' );
		}
	}

	$locks = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_locks} ORDER BY period_year DESC, period_month DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
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
					<th>Slet</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $locks as $lock ) : ?>
					<tr>
						<td><?php echo esc_html( $lock->period_month . '/' . $lock->period_year ); ?></td>
						<td><?php echo esc_html( $lock->locked_by ); ?></td>
						<td><?php echo esc_html( $lock->locked_at ); ?></td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'sr_delete_lock_action', 'sr_delete_lock_nonce' ); ?>
								<input type="hidden" name="lock_id" value="<?php echo esc_attr( $lock->id ); ?>">
								<button
									type="submit"
									name="sr_delete_lock"
									class="button button-link-delete sr-delete-row"
									data-summary="<?php echo esc_attr( 'Lås: ' . $lock->period_month . '/' . $lock->period_year . ' (låst af ' . $lock->locked_by . ')' ); ?>"
								>Slet</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php sr_render_pagination( admin_url( 'admin.php?page=' . SR_PLUGIN_SLUG . '-locks' ), $current_page, $total_pages ); ?>
	</div>
	<script>
		(function () {
			document.querySelectorAll('.sr-delete-row').forEach((button) => {
				button.addEventListener('click', (event) => {
					const summary = button.dataset.summary || '';
					const message = summary
						? `Er du sikker på, at du vil slette denne række?\n${summary}`
						: 'Er du sikker på, at du vil slette denne række?';
					if (!window.confirm(message)) {
						event.preventDefault();
					}
				});
			});
		}());
	</script>
	<?php
}

/**
 * Render bank statements upload page.
 */
function sr_render_bank_statements_page() {
	if ( ! current_user_can( SR_CAPABILITY_ADMIN ) ) {
		return;
	}

	global $wpdb;
	$table_bank_statements = $wpdb->prefix . 'sr_bank_statements';
	$per_page              = 20;
	$current_page          = sr_get_paged_param( 'sr_page' );
	$message               = '';
	$popup_message         = '';

	if ( isset( $_POST['sr_upload_bank_csv'] ) ) {
		check_admin_referer( 'sr_upload_bank_csv_action', 'sr_upload_bank_csv_nonce' );
		$file = $_FILES['sr_bank_csv'] ?? null;

		if ( ! $file || ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$message = '<div class="notice notice-error"><p>Kunne ikke finde filen. Prøv venligst igen.</p></div>';
		} elseif ( ! empty( $file['error'] ) ) {
			$message = '<div class="notice notice-error"><p>Der opstod en fejl under upload af filen.</p></div>';
		} else {
			$added   = 0;
			$skipped = 0;
			$read_rows = 0;
			$contents = file_get_contents( $file['tmp_name'] );

			if ( false === $contents ) {
				$message = '<div class="notice notice-error"><p>Kunne ikke åbne CSV-filen.</p></div>';
			} else {
				if ( false !== strpos( $contents, '\\n' ) ) {
					$contents = str_replace( '\\n', "\n", $contents );
				}
				$contents = str_replace( array( "\r\n", "\r" ), "\n", $contents );
				$lines    = array_filter( array_map( 'trim', explode( "\n", $contents ) ), 'strlen' );

				foreach ( $lines as $line ) {
					$trimmed_line = trim( $line );

					if ( '' === $trimmed_line ) {
						continue;
					}

					if ( preg_match( '/^sep\\s*=/i', $trimmed_line ) ) {
						continue;
					}

					$row = str_getcsv( $line, ';' );

					if ( empty( array_filter( $row, 'strlen' ) ) ) {
						continue;
					}

					$header_check = array_map( 'trim', $row );
					$header_first = $header_check[0] ?? '';
					$header_first = preg_replace( '/^\xEF\xBB\xBF/', '', $header_first );
					if ( '' !== $header_first && 'dato' === strtolower( $header_first ) ) {
						continue;
					}

					if ( count( $row ) < 4 ) {
						continue;
					}

					$read_rows++;
					$date           = trim( (string) $row[0] );
					$text           = trim( (string) $row[1] );
					$amount         = sr_normalize_decimal_input( $row[2] );
					$balance        = sr_normalize_decimal_input( $row[3] );

					$hash_source = implode(
						'|',
						array(
							$date,
							$text,
							(string) $amount,
							(string) $balance,
						)
					);
					$row_hash = hash( 'sha256', $hash_source );

					$existing = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM {$table_bank_statements} WHERE row_hash = %s",
							$row_hash
						)
					);

					if ( $existing ) {
						$skipped++;
						continue;
					}

					$inserted = $wpdb->insert(
						$table_bank_statements,
						array(
							'Dato'        => $date,
							'Tekst'       => $text,
							'Beløb'       => $amount,
							'Saldo'       => $balance,
							'row_hash'    => $row_hash,
							'created_at'  => sr_now(),
						),
						array( '%s', '%s', '%f', '%f', '%s', '%s' )
					);

					if ( false !== $inserted ) {
						$added++;
					}
				}

				$message = '<div class="notice notice-success"><p>' .
					sprintf(
						'Indlæsning fuldført. Læste %d rækker, tilføjede %d rækker, sprang %d rækker over (duplikater).',
						$read_rows,
						$added,
						$skipped
					) .
					'</p></div>';
				$popup_message = sprintf(
					"Indlæsning fuldført.\nLæste rækker: %d\nIndsatte rækker: %d\nSkippede rækker (duplikater): %d",
					$read_rows,
					$added,
					$skipped
				);
			}
		}
	}

	$total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_bank_statements}" );
	$total_pages = (int) max( 1, ceil( $total_items / $per_page ) );
	$offset      = ( $current_page - 1 ) * $per_page;
	$rows        = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table_bank_statements} ORDER BY COALESCE(
				STR_TO_DATE(`Dato`, '%%d-%%m-%%Y'),
				STR_TO_DATE(`Dato`, '%%d/%%m/%%Y'),
				STR_TO_DATE(`Dato`, '%%d.%%m.%%Y'),
				STR_TO_DATE(`Dato`, '%%Y-%%m-%%d'),
				created_at
			) DESC, id DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		)
	);
	?>
	<div class="wrap">
		<h1>Bankudtog</h1>
		<?php echo wp_kses_post( $message ); ?>
		<?php if ( '' !== $popup_message ) : ?>
			<script>
				(function() {
					window.alert(<?php echo wp_json_encode( $popup_message ); ?>);
				}());
			</script>
		<?php endif; ?>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'sr_upload_bank_csv_action', 'sr_upload_bank_csv_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row">CSV-fil</th>
					<td>
						<input type="file" name="sr_bank_csv" accept=".csv,text/csv" required>
						<p class="description">CSV-format: Dato;Tekst;Beløb;Saldo</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Upload CSV', 'primary', 'sr_upload_bank_csv' ); ?>
		</form>

		<h2>Importerede banklinjer</h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Dato</th>
					<th>Tekst</th>
					<th>Beløb</th>
					<th>Saldo</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr>
						<td colspan="4">Ingen banklinjer fundet.</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->Dato ); ?></td>
							<td><?php echo esc_html( $row->Tekst ); ?></td>
							<td><?php echo esc_html( number_format( (float) $row->Beløb, 2, ',', '.' ) ); ?></td>
							<td><?php echo esc_html( number_format( (float) $row->Saldo, 2, ',', '.' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php sr_render_pagination( admin_url( 'admin.php?page=' . SR_PLUGIN_SLUG . '-bank-statements' ), $current_page, $total_pages ); ?>
	</div>
	<?php
}

/**
 * Render bank statement linking page.
 */
function sr_render_bank_statement_link_page() {
	if ( ! current_user_can( SR_CAPABILITY_ADMIN ) ) {
		return;
	}

	global $wpdb;
	$table_bank_statements = $wpdb->prefix . 'sr_bank_statements';
	$table_payments        = $wpdb->prefix . 'sr_payments';
	$table_residents       = $wpdb->prefix . 'sr_residents';
	$per_page              = 20;
	$current_page          = sr_get_paged_param( 'sr_page' );
	$message               = '';

	if ( isset( $_POST['sr_link_bank_payment'] ) ) {
		check_admin_referer( 'sr_link_bank_payment_action', 'sr_link_bank_payment_nonce' );
		$resident_ids      = array_map( 'absint', $_POST['resident_id'] ?? array() );
		$resident_name_ids = array_map( 'absint', $_POST['resident_id_name'] ?? array() );
		$link_requests     = array();

		foreach ( $resident_ids as $bank_statement_id => $resident_id ) {
			$bank_statement_id = absint( $bank_statement_id );
			if ( ! $resident_id ) {
				$resident_id = absint( $resident_name_ids[ $bank_statement_id ] ?? 0 );
			}
			if ( $bank_statement_id && $resident_id ) {
				$link_requests[ $bank_statement_id ] = $resident_id;
			}
		}

		if ( empty( $link_requests ) ) {
			$message = '<div class="notice notice-error"><p>Vælg mindst én beboer for at tilknytte bankudtog.</p></div>';
		} else {
			$linked_count = 0;
			$errors       = array();

			foreach ( $link_requests as $bank_statement_id => $resident_id ) {
				$bank_statement = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$table_bank_statements} WHERE id = %d",
						$bank_statement_id
					)
				);
				if ( ! $bank_statement ) {
					$errors[] = 'Et valgt bankudtog findes ikke længere.';
					continue;
				}

				$existing_payment = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$table_payments} WHERE bank_statement_id = %d",
						$bank_statement_id
					)
				);
				if ( $existing_payment ) {
					$errors[] = 'Et valgt bankudtog er allerede tilknyttet.';
					continue;
				}

				$period = sr_get_period_from_bank_statement_date( $bank_statement->Dato );
				if ( ! $period ) {
					$errors[] = 'Kunne ikke aflæse datoen fra et bankudtog.';
					continue;
				}
				if ( sr_is_period_locked( $period['month'], $period['year'] ) ) {
					$errors[] = 'En valgt periode er låst og kan ikke bruges til indbetaling.';
					continue;
				}

				$status   = 'verified';
				$inserted = $wpdb->insert(
					$table_payments,
					array(
						'resident_id'       => $resident_id,
						'bank_statement_id' => $bank_statement_id,
						'period_month'      => $period['month'],
						'period_year'       => $period['year'],
						'amount'            => (float) $bank_statement->Beløb,
						'status'            => $status,
						'submitted_by'      => get_current_user_id(),
						'submitted_at'      => sr_now(),
						'verified_by'       => get_current_user_id(),
						'verified_at'       => sr_now(),
					),
					array( '%d', '%d', '%d', '%d', '%f', '%s', '%d', '%s', '%d', '%s' )
				);
				if ( false !== $inserted ) {
					sr_log_action( 'create', 'payment', $wpdb->insert_id, 'Bankudtog tilknytning' );
					sr_log_action( 'verify', 'payment', $wpdb->insert_id, 'Indbetaling verificeret' );
					$linked_count++;
				} else {
					$errors[] = 'Kunne ikke oprette en indbetaling. Prøv igen.';
				}
			}

			$notices = array();
			if ( $linked_count > 0 ) {
				$notices[] = '<div class="notice notice-success"><p>' . esc_html( sprintf( '%d indbetalinger blev oprettet og tilknyttet.', $linked_count ) ) . '</p></div>';
			}
			if ( ! empty( $errors ) ) {
				$errors_list = '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $errors ) ) . '</li></ul>';
				$notices[]   = '<div class="notice notice-error"><p>Der opstod problemer under tilknytning:</p>' . $errors_list . '</div>';
			}
			$message = implode( '', $notices );
		}
	}

	$hide_negative = ! isset( $_GET['sr_hide_negative'] ) || '1' === $_GET['sr_hide_negative'];
	$where_clauses = array( 'p.id IS NULL' );
	if ( $hide_negative ) {
		$where_clauses[] = 'b.`Beløb` >= 0';
	}
	$where_sql   = 'WHERE ' . implode( ' AND ', $where_clauses );
	$total_items = (int) $wpdb->get_var(
		"SELECT COUNT(*)
			FROM {$table_bank_statements} b
			LEFT JOIN {$table_payments} p ON b.id = p.bank_statement_id
			{$where_sql}"
	);
	$total_pages = (int) max( 1, ceil( $total_items / $per_page ) );
	$offset      = ( $current_page - 1 ) * $per_page;
	$rows        = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT b.*, p.id AS payment_id, p.resident_id AS linked_resident_id
				FROM {$table_bank_statements} b
				LEFT JOIN {$table_payments} p ON b.id = p.bank_statement_id
				{$where_sql}
				ORDER BY COALESCE(
					STR_TO_DATE(b.`Dato`, '%%d-%%m-%%Y'),
					STR_TO_DATE(b.`Dato`, '%%d/%%m/%%Y'),
					STR_TO_DATE(b.`Dato`, '%%d.%%m.%%Y'),
					STR_TO_DATE(b.`Dato`, '%%Y-%%m-%%d'),
					b.created_at
				) DESC, b.id DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		)
	);

	$residents = $wpdb->get_results( "SELECT id, member_number, name FROM {$table_residents} ORDER BY member_number ASC" );
	$resident_member_numbers = array();
	foreach ( $residents as $resident ) {
		$resident_member_numbers[ (int) $resident->id ] = $resident->member_number;
	}
	?>
	<div class="wrap">
		<h1>Tilknyt betalinger</h1>
		<?php echo wp_kses_post( $message ); ?>
		<div style="display: flex; align-items: center; gap: 8px; margin: 12px 0;">
			<form method="get" style="margin: 0;">
				<input type="hidden" name="page" value="<?php echo esc_attr( SR_PLUGIN_SLUG . '-bank-link-payments' ); ?>">
				<input type="hidden" name="sr_hide_negative" value="0">
				<label>
					<input type="checkbox" name="sr_hide_negative" value="1" <?php checked( $hide_negative ); ?>>
					Skjul negative posteringer
				</label>
				<button type="submit" class="button">Opdater</button>
			</form>
			<button type="submit" name="sr_link_bank_payment" class="button button-primary" form="sr-link-payments-form">Tilknyt</button>
		</div>
		<form method="post" id="sr-link-payments-form">
			<?php wp_nonce_field( 'sr_link_bank_payment_action', 'sr_link_bank_payment_nonce' ); ?>
			<table class="widefat striped">
			<thead>
				<tr>
					<th>Dato</th>
					<th>Tekst</th>
					<th>Beløb</th>
					<th>dns</th>
					<th>Handling</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr>
						<td colspan="5">Ingen banklinjer fundet.</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr class="sr-link-payment-row">
							<td><?php echo esc_html( $row->Dato ); ?></td>
							<td><?php echo esc_html( $row->Tekst ); ?></td>
							<td><?php echo esc_html( number_format( (float) $row->Beløb, 2, ',', '.' ) ); ?></td>
							<td>
								<?php if ( $row->payment_id ) : ?>
									<?php echo esc_html( $resident_member_numbers[ (int) $row->linked_resident_id ] ?? 'Ukendt' ); ?>
								<?php else : ?>
									<select name="resident_id[<?php echo esc_attr( $row->id ); ?>]" class="sr-link-payment-member">
										<option value="">Vælg beboer</option>
										<?php foreach ( $residents as $resident ) : ?>
											<option value="<?php echo esc_attr( $resident->id ); ?>"><?php echo esc_html( $resident->member_number ); ?></option>
										<?php endforeach; ?>
									</select>
									<select name="resident_id_name[<?php echo esc_attr( $row->id ); ?>]" class="sr-link-payment-name">
										<option value="">Vælg navn</option>
										<?php foreach ( $residents as $resident ) : ?>
											<option value="<?php echo esc_attr( $resident->id ); ?>"><?php echo esc_html( $resident->name ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $row->payment_id ) : ?>
									<span>Allerede tilknyttet</span>
								<?php else : ?>
									<span>Vælg beboer</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
			</table>
		</form>
		<?php
		$pagination_base = add_query_arg(
			'sr_hide_negative',
			$hide_negative ? '1' : '0',
			admin_url( 'admin.php?page=' . SR_PLUGIN_SLUG . '-bank-link-payments' )
		);
		sr_render_pagination( $pagination_base, $current_page, $total_pages );
		?>
	</div>
	<script>
		document.querySelectorAll('.sr-link-payment-row').forEach((row) => {
			const memberSelect = row.querySelector('.sr-link-payment-member');
			const nameSelect = row.querySelector('.sr-link-payment-name');

			if (!memberSelect || !nameSelect) {
				return;
			}

			const syncSelects = (source, target) => {
				const nextValue = source.value || '';
				target.value = nextValue;
			};

			memberSelect.addEventListener('change', () => {
				syncSelects(memberSelect, nameSelect);
			});

			nameSelect.addEventListener('change', () => {
				syncSelects(nameSelect, memberSelect);
			});
		});
	</script>
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
 * Delete summary for a reading when it is unverified.
 *
 * @param int $resident_id Resident ID.
 * @param int $month Month.
 * @param int $year Year.
 */
function sr_delete_summary_for_reading( $resident_id, $month, $year ) {
	global $wpdb;
	$table_summary = $wpdb->prefix . 'sr_monthly_summary';

	$deleted = $wpdb->delete(
		$table_summary,
		array(
			'resident_id'  => $resident_id,
			'period_month' => $month,
			'period_year'  => $year,
		),
		array( '%d', '%d', '%d' )
	);

	if ( $deleted ) {
		sr_log_action( 'delete', 'summary', null, 'Beregning slettet' );
	}
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
		$amount = sr_normalize_decimal_input( $_POST['amount'] ?? 0 );

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

/**
 * Enqueue admin styles for negative values.
 *
 * @param string $hook Admin page hook.
 */
function sr_enqueue_admin_styles( $hook ) {
	if ( false === strpos( $hook, SR_PLUGIN_SLUG ) ) {
		return;
	}

	wp_add_inline_style(
		'common',
		'.sr-negative{color:#b00020;font-weight:600}.sr-positive{color:#1a7f37;font-weight:600}'
	);
}
add_action( 'admin_enqueue_scripts', 'sr_enqueue_admin_styles' );
