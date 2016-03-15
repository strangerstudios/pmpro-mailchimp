<?php

	if(!function_exists("current_user_can") || (!current_user_can("manage_options") && !current_user_can("pmpro_memberslistcsv")))
	{
		die(__("You do not have permissions to perform this action.", "pmpro"));
	}

	if (!defined('PMPRO_BENCHMARK'))
		define('PMPRO_BENCHMARK', false);

	if (PMPRO_BENCHMARK)
	{
		error_log(str_repeat('-', 10) . date('Y-m-d H:i:s') . str_repeat('-', 10));
		$start_time = microtime(true);
		$start_memory = memory_get_usage(true);
	}


	/**
	 * Filter to set max number of records to process at a time
	 * for the export (helps manage memory footprint)
	 *
	 * Rule of thumb: 2000 records: ~50-60 MB of addl. memory (memory_limit needs to be between 128MB and 256MB)
	 *                4000 records: ~70-100 MB of addl. memory (memory_limit needs to be >= 256MB)
	 *                6000 records: ~100-140 MB of addl. memory (memory_limit needs to be >= 256MB)
	 *
	 * NOTE: Use the pmpro_before_members_list_csv_export hook to increase memory "on-the-fly"
	 *       Can reset with the pmpro_after_members_list_csv_export hook
	 *
	 * @since 1.8.7
	 */
	//set the number of users we'll load to try and protect ourselves from OOM errors
	$max_users_per_loop = apply_filters('pmpro_set_max_user_per_export_loop', 2000);

	global $wpdb;
	
	// requested a level id
	if(isset($_REQUEST['l']))
		$l = sanitize_text_field($_REQUEST['l']);
	else
		$l = false;

	//some vars for the search
	if(!empty($_REQUEST['pn']))
		$pn = intval($_REQUEST['pn']);
	else
		$pn = 1;

	if(!empty($_REQUEST['limit']))
		$limit = intval($_REQUEST['limit']);
	else
		$limit = false;

	if($limit)
	{
		$end = $pn * $limit;
		$start = $end - $limit;
	}
	else
	{
		$end = NULL;
		$start = NULL;
	}

	$headers = array();	
	$headers[] = "Content-Type: text/csv";
	$headers[] = "Cache-Control: max-age=0, no-cache, no-store";
	$headers[] = "Pragma: no-cache";
	$headers[] = "Connection: close";
	
	if(!empty($l))
		$headers[] = 'Content-Disposition: attachment; filename="pmpro_mailchimp_export_level_' . $l . '.csv"';
	else
		$headers[] = 'Content-Disposition: attachment; filename="pmpro_mailchimp_export.csv"';
		
	//set default CSV file headers, using comma as delimiter
	$csv_file_header = "email,PMPLEVEL,PMPLEVELID";	

	//these are the meta_keys for the fields (arrays are object, property. so e.g. $theuser->ID)
	$default_columns = array(		
		array("theuser", "user_email"),		
		array("theuser", "membership_name"),
		array("theuser", "membership_id")		
	);
	
	//set the preferred date format:
	$dateformat = apply_filters("pmpro_memberslist_csv_dateformat","Y-m-d");
	
	$csv_file_header .= "\n";

	//generate SQL for list of users to process
	$sqlQuery = "
		SELECT
			DISTINCT u.ID
		FROM $wpdb->users u ";	

	$sqlQuery .= "LEFT JOIN {$wpdb->pmpro_memberships_users} mu ON u.ID = mu.user_id ";
	$sqlQuery .= "LEFT JOIN {$wpdb->pmpro_membership_levels} m ON mu.membership_id = m.id ";
	
	$sqlQuery .= "WHERE mu.membership_id > 0 ";
	
	$filter = " AND mu.status = 'active' AND mu.membership_id = " . esc_sql($l) . " ";	

	//add the filter
	$sqlQuery .= $filter;

	//process based on limit value(s).
	$sqlQuery .= "ORDER BY u.ID ";

	if(!empty($limit))
		$sqlQuery .= "LIMIT {$start}, {$limit}";

	// Generate a temporary file to store the data in.
	$tmp_dir = sys_get_temp_dir();
	$filename = tempnam( $tmp_dir, 'pmpro_ml_');

	// open in append mode
	$csv_fh = fopen($filename, 'a');

	//write the CSV header to the file
	fprintf($csv_fh, '%s', $csv_file_header );

	//get users
	$theusers = $wpdb->get_col($sqlQuery);
	
	//if no records just transmit file with only CSV header as content
	if (empty($theusers)) {

		// send the data to the remote browser
		pmpro_transmit_content($csv_fh, $filename, $headers);
	}

	$users_found = count($theusers);

	if (PMPRO_BENCHMARK)
	{
		$pre_action_time = microtime(true);
		$pre_action_memory = memory_get_usage(true);
	}	

	$i_start = 0;
	$i_limit = 0;
	$iterations = 1;

	$csvoutput = array();

	if($users_found >= $max_users_per_loop)
	{
		$iterations = ceil($users_found / $max_users_per_loop);
		$i_limit = $max_users_per_loop;
	}

	$end = 0;
	$time_limit = ini_get('max_execution_time');

	if (PMPRO_BENCHMARK)
	{
		error_log("PMPRO_BENCHMARK - Total records to process: {$users_found}");
		error_log("PMPRO_BENCHMARK - Will process {$iterations} iterations of max {$max_users_per_loop} records per iteration.");
		$pre_iteration_time = microtime(true);
		$pre_iteration_memory = memory_get_usage(true);
	}

	//to manage memory footprint, we'll iterate through the membership list multiple times
	for ( $ic = 1 ; $ic <= $iterations ; $ic++ ) {

		if (PMPRO_BENCHMARK)
		{
			$start_iteration_time = microtime(true);
			$start_iteration_memory = memory_get_usage(true);
		}

		//make sure we don't timeout
		if ($end != 0) {

			$iteration_diff = $end - $start;
			$new_time_limit = ceil($iteration_diff*$iterations * 1.2);

			if ($time_limit < $new_time_limit )
			{
				$time_limit = $new_time_limit;
				set_time_limit( $time_limit );
			}
		}

		$start = current_time('timestamp');

		// get first and last user ID to use
		$first_uid = $theusers[$i_start];

		//get last UID, will depend on which iteration we're on.
		if ( $ic != $iterations )
			$last_uid = $theusers[($i_start + ( $max_users_per_loop - 1))];
		else
			// Final iteration, so last UID is the last record in the users array
			$last_uid = $theusers[($users_found - 1)];

		//increment starting position
		if(0 < $iterations)
		{
			$i_start += $max_users_per_loop;
		}

		$userSql = $wpdb->prepare("
	        SELECT
				DISTINCT u.ID,				
				u.user_email,				
				mu.membership_id as membership_id,				
				m.name as membership_name			
			FROM {$wpdb->users} u
			LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
			LEFT JOIN {$wpdb->pmpro_memberships_users} mu ON u.ID = mu.user_id
			LEFT JOIN {$wpdb->pmpro_membership_levels} m ON mu.membership_id = m.id			
			WHERE u.ID BETWEEN %d AND %d AND mu.membership_id > 0 {$filter}
			GROUP BY u.ID
			ORDER BY u.ID",
				$first_uid,
				$last_uid
		);

		// TODO: Only return the latest record for the user(s) current (and prior) levels IDs?
		
		$usr_data = $wpdb->get_results($userSql);
		$userSql = null;

		if (PMPRO_BENCHMARK)
		{
			$pre_userdata_time = microtime(true);
			$pre_userdata_memory = memory_get_usage(true);
		}

		// process the actual data we want to export
		foreach($usr_data as $theuser) {

			$csvoutput = array();

			unset($disSql);

			//default columns
			if(!empty($default_columns))
			{
				$count = 0;
				foreach($default_columns as $col)
				{
					//checking $object->property. note the double $$
					$val = isset(${$col[0]}->{$col[1]}) ? ${$col[0]}->{$col[1]} : null;
					array_push($csvoutput, pmpro_enclose($val));	//output the value
				}
			}			

			//free memory for user records
			$metavalues = null;
			$discount_code = null;
			$theuser = null;

			// $csvoutput .= "\n";
			$line = implode(',', $csvoutput) . "\n";

			fprintf($csv_fh, "%s", $line);

			//reset
			$line = null;
			$csvoutput = null;
		} // end of foreach usr_data

		if (PMPRO_BENCHMARK)
		{
			$end_of_iteration_time = microtime(true);
			$end_of_iteration_memory = memory_get_usage(true);
		}

		//keep memory consumption low(ish)
		wp_cache_flush();

		if (PMPRO_BENCHMARK)
		{
			$after_flush_time = microtime(true);
			$after_flush_memory = memory_get_usage(true);

			$time_in_iteration = $end_of_iteration_time - $start_iteration_time;
			$time_flushing = $after_flush_time - $end_of_iteration_time;
			$userdata_time = $end_of_iteration_time - $pre_userdata_time;

			list($iteration_sec, $iteration_usec) = explode('.', $time_in_iteration);
			list($udata_sec, $udata_usec) = explode('.', $userdata_time);
			list($flush_sec, $flush_usec) = explode('.', $time_flushing);

			$memory_used = $end_of_iteration_memory - $start_iteration_memory;

			error_log("PMPRO_BENCHMARK - For iteration #{$ic} of {$iterations} - Records processed: " . count($usr_data));
			error_log("PMPRO_BENCHMARK - \tTime processing whole iteration: " . date("H:i:s", $iteration_sec) . ".{$iteration_sec}");
			error_log("PMPRO_BENCHMARK - \tTime processing user data for iteration: " . date("H:i:s", $udata_sec) . ".{$udata_sec}");
			error_log("PMPRO_BENCHMARK - \tTime flushing cache: " . date("H:i:s", $flush_sec) . ".{$flush_usec}");
			error_log("PMPRO_BENCHMARK - \tAdditional memory used during iteration: ".number_format($memory_used, 2, '.', ',') . " bytes");
		}

		//need to increase max running time?
		$end = current_time('timestamp');

	} // end of foreach iteration

	if (PMPRO_BENCHMARK)
	{
		$after_data_time = microtime(true);
		$after_data_memory = memory_get_peak_usage(true);

		$time_processing_data = $after_data_time - $start_time;
		$memory_processing_data = $after_data_memory - $start_memory;

		list($sec, $usec) = explode('.', $time_processing_data);

		error_log("PMPRO_BENCHMARK - Time processing data: {$sec}.{$usec} seconds");
		error_log("PMPRO_BENCHMARK - Peak memory usage: " . number_format($memory_processing_data, false, '.', ',') . " bytes");
	}

	// free memory
	$usr_data = null;

	// send the data to the remote browser
	pmpro_transmit_content($csv_fh, $filename, $headers);

	exit;

	function pmpro_enclose($s)
	{
		return "\"" . str_replace("\"", "\\\"", $s) . "\"";
	}

	// responsible for trasnmitting content of file to remote browser
	function pmpro_transmit_content( $csv_fh, $filename, $headers = array() ) {

		//close the temp file
		fclose($csv_fh);

		//make sure we get the right file size
		clearstatcache( true, $filename );

		//did we accidentally send errors/warnings to browser?
		if (headers_sent())
		{
			echo str_repeat('-', 75) . "<br/>\n";
			echo 'Please open a support case and paste in the warnings/errors you see above this text to\n ';
			echo 'the <a href="http://paidmembershipspro.com/support/" target="_blank">Paid Memberships Pro support forum</a><br/>\n';
			echo str_repeat("=", 75) . "<br/>\n";
			echo file_get_contents($filename);
			echo str_repeat("=", 75) . "<br/>\n";
		}

		//transmission
		if (! empty($headers) )
		{
			//set the download size
			$headers[] = "Content-Length: " . filesize($filename);

			//set headers
			foreach($headers as $header)
			{
				header($header . "\r\n");
			}

			// disable compression for the duration of file download
			if(ini_get('zlib.output_compression')){
				ini_set('zlib.output_compression', 'Off');
			}

			// open and send the file contents to the remote location
			$fh = fopen( $filename, 'rb' );
			fpassthru($fh);
			fclose($fh);

			// remove the temp file
			unlink($filename);
		}
		
		exit;
	}
