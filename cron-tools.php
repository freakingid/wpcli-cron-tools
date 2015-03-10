<?php

class Tenup_Cron_Tools extends WP_CLI_Command {

	/**
	 * Backs up all existing cron hooks to JSON.
	 * Logs all existing cron hooks to more human-readable TXT.
	 *
	 * ## OPTIONS
	 *
	 * There are no options
	 *
	 * ## EXAMPLES
	 *      wp cron_tools backup_cron
	 *
	 */
	public function backup_cron() {

		// get all existing cron events
		WP_CLI::line( 'Retrieving all current scheduled events' );
		$current_scheduled_events_array = _get_cron_array();
		if ( false === $current_scheduled_events_array ) {
			// failed
			WP_CLI::error( 'Failed to retrieve cron_array. That is weird.' );

			return;
		}

		// package as json
		$current_scheduled_events_json = json_encode( $current_scheduled_events_array );
		if ( false === $current_scheduled_events_json ) {
			// failed
			WP_CLI::error( 'Failed to encode cron array to JSON for saving.' );

			return;
		}

		// export to JSON
		WP_CLI::line( 'Exporting all currently scheduled events to backup file as JSON' );
		$date_string = date( 'c' );
		$file_name   = $date_string . '-cron-events.json';
		$log_success = file_put_contents( $file_name, $current_scheduled_events_json, FILE_APPEND | LOCK_EX );
		if ( false === $log_success ) {
			// logging to file failed, so don't go forward
			WP_CLI::error( 'Failed writing all events to backup file. Aborting' );

			return;
		} else {
			WP_CLI::success( 'Logged all events to backup file: ' . $file_name );
		}

		// log to human-readable TXT
		WP_CLI::line( 'Logging all currently scheduled events to human readable file as TEXT' );
		$file_name = $date_string . '-cron-events.log';
		$handle    = fopen( $file_name, 'w' );
		// header
		$header_line = "UniqueID\t\t\t\tRecurrence\tArgs------\tInterval--" . PHP_EOL;
		foreach ( $current_scheduled_events_array as $timestamp => $events ) {
			// output key as timestamp
			fwrite( $handle, 'Timestamp: ' . $timestamp . PHP_EOL );

			foreach ( $events as $hook_name => $summary ) {
				fwrite( $handle, '=== Hook: ' . $hook_name . PHP_EOL );
				fwrite( $handle, $header_line );

				foreach ( $summary as $unique_id => $parameters ) {
					fwrite( $handle, $unique_id );
					fwrite( $handle, "\t" );

					foreach ( $parameters as $argument => $value ) {
						$value = ( ! empty( $value ) ? $value : 'none' );
						$value = str_pad( $value, 8, "+" );
						fwrite( $handle, $value );
						fwrite( $handle, "\t" );
					}
				}
				// give us a line after this hook group
				fwrite( $handle, PHP_EOL );
			}
			// give us a line after this timestamp group
			fwrite( $handle, PHP_EOL );
		}
		fclose( $handle );
		WP_CLI::success( 'Finished logging human readable to: ' . $file_name );
	}

	/**
	 * Restores all cron hooks from JSON file.
	 * Re-schedules all publish_future_posts hooks from the Post date info itself, rather than cron entries.
	 * Publishes any posts that have missed their schedule for any reason.
	 *
	 * ## OPTIONS
	 *
	 * --file=<filename>    filename of JSON file to restore cron from (required)
	 *
	 * ## EXAMPLES
	 *      wp cron_tools restore_cron [filename].json
	 *
	 * @synopsis --file=<filename>
	 */
	public function restore_cron( $args = array(), $assoc_args = array() ) {

		// get filename
		$import_filename = $assoc_args['file'];
		WP_CLI::line( 'Looks like you want to restore cron entries from the file: (' . $import_filename . ')' );
		WP_CLI::confirm( 'Are you sure you want to proceed?' );

		// does file exists?
		if ( ! file_exists( $import_filename ) ) {
			WP_CLI::error( 'Sorry, the file: (' . $import_filename . ') does not exist.' );

			return;
		}

		// read file in
		WP_CLI::line( 'Reading in file.' );
		$file_input = file_get_contents( $import_filename );
		if ( false === $file_input ) {
			WP_CLI::error( 'Sorry, unable to read the file: (' . $import_filename . ')' );

			return;
		}

		// convert to json -- get arrays
		WP_CLI::line( 'Decoding JSON to objects.' );
		$imported_json = json_decode( $file_input, true );
		if ( false === $imported_json ) {
			WP_CLI::error( 'Sorry, something went wrong encoding the file to JSON.' );

			return;
		}

		// step through json and remove events
		WP_CLI::line( 'Cleaning all cron entries in file from database' );
		foreach ( $imported_json as $timestamp => $event ) {

			foreach ( $event as $hook => $unique_array ) {
				// note unique_array seems odd but whatever

				foreach ( $unique_array as $unique_id => $params_array ) {
					/*
					 * $params_array ( 'schedule', 'args', 'interval' ) // keys
					 */
					wp_unschedule_event( $timestamp, $hook, $params_array['args'] );
				}
			}
		}
		WP_CLI::success( 'All cron entries unscheduled' );

		// step through json again and re-add events that are not future posts
		WP_CLI::line( 'Preparing to re-add all cron entries in file to database' );
		WP_CLI::line( count( $imported_json ) . ' cron entries to process from file.' );
		foreach ( $imported_json as $timestamp => $event ) {

			foreach ( $event as $hook => $unique_array ) {
				// note unique_array seems odd but whatever
				if ( 'publish_future_post' === $hook ) {
					// we don't want to reschedule that this way
					continue;
				}

				foreach ( $unique_array as $unique_id => $params_array ) {
					// $params_array ( 'schedule', 'args', 'interval' ) // keys
					/*
					 * Ensure we schedule the first run to be AFTER current time.
					 * We'd prefer this to be in blocks of (interval) size, added on
					 * to the last timestamp we knew of for the event.
					 */
					$start_time = $timestamp;
					if ( $start_time <= $timestamp ) {
						if ( ! isset( $params_array['interval'] ) ) {
							// it's a one-off
							$start_time = time() + 60 * 60; // give us an hour from now
						} else {
							// it's repeating, so it has an interval to work with
							$difference      = $timestamp - $start_time;
							$interval_blocks = $difference / $params_array['interval'] + 1;
							$start_time += $interval_blocks * $params_array['interval'];
						}
					}

					if ( ! isset( $params_array['interval'] ) ) {
						// it's a one-off
						$result = wp_schedule_single_event( $start_time, $hook, $params_array['args'] );
					} else {
						// it's repeating
						$result = wp_schedule_event( $start_time, $params_array['schedule'], $hook, $params_array['args'] );
					}
					if ( false === $result ) {
						WP_CLI::error( 'Unable to schedule the following:' );
						WP_CLI::line( 'timestamp: ' . $start_time . '; schedule: ' . $params_array['schedule'] . '; hook: ' . $hook );
						WP_CLI::line( 'continuing...' );
					}
				}
			}
		}
		WP_CLI::success( 'All reoccurring cron entries rescheduled' );

		// at this point, we should have all events rescheduled except for publish_future_post
		// now we need to get scheduled post information
		$this->reschedule_future_posts();
	}

	/**
	 * Reschedule all future posts for cron handling.
	 * Publish any posts that have missed their schedule for any reason.
	 *
	 * ## OPTIONS
	 *
	 * No options
	 *
	 * ## EXAMPLES
	 *      wp cron_tools reschedule_future_posts
	 *
	 */
	public function reschedule_future_posts() {
		global $wpdb;

		WP_CLI::line( 'Preparing to add proper cron entries for all posts listed as "future".' );

		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND ( post_status = 'future' )" );
		WP_CLI::line( 'Scheduling ' . $total . ' future posts.' );

		$n = 50; // 50 at a time
		$total = ceil( $total / $n );
		WP_CLI::line( 'Handling ' . $total . ' groups of ' . $n . ' posts.' );

		for ( $i = 0; $i < $total; $i ++ ) {
			WP_CLI::line( 'Group: ' . $i . ' of: ' . $total );
			$rows = $wpdb->get_results( $sql =
				"SELECT ID, post_date_gmt FROM {$wpdb->posts}
				WHERE post_status = 'future'
				ORDER BY ID DESC LIMIT " . $i * $n . ", $n"
			);
			foreach ( $rows as $row ) {
				$this->reschedule_post( $row );
			}
		}
	}

	private function reschedule_post( $post ) {
		error_log( print_r( $post, true ) );
		$timestamp = strtotime( $post->post_date_gmt );
		if ( $timestamp <= time() ) {
			// we need to go ahead and publish this post
			WP_CLI::success( 'Publishing post: ' . $post->ID );
			wp_publish_post( $post->ID );
		} else {
			// we need to schedule publication of this post
			$result = wp_schedule_single_event( $timestamp, 'publish_future_post', array( $post->ID ) );
			if ( false === $result ) {
				WP_CLI::error( 'Unable to schedule the following for future publishing:' );
				WP_CLI::line( 'ID: ' . $post->ID . '; post_name: ' . $post->post_name . '; post_date_gmt: ' . $post->post_date_gmt );
			} else {
				WP_CLI::success( 'Scheduled post ' . $post->ID . ' for future publishing.' );
			}
		}
	}
}

WP_CLI::add_command( 'cron_tools', 'Tenup_Cron_Tools' );
