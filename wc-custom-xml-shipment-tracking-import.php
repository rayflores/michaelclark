<?php
/**
 * Plugin Name: WooCommerce Custom XML Shipment Tracking Importer
 * Plugin URI: https://rayflores.com
 * Description: Add-on for the <a href="http://www.woothemes.com/products/shipment-tracking/">WooThemes Shipment Tracking</a> plugin that adds a XML importer for tracking numbers.
 * Author: Ray Flores
 * Author URI: https:// rayflores.com
 * Version: 1.1
 * Requires at least: 3.9.1
 * Tested up to: 4.7.5
 *
 */

// if shipment tracking plugin is active
add_action( 'admin_init', 'wc_custom_xml_shipment_tracking_register_importers');

/**
 * Add menu items
 */
function wc_custom_xml_shipment_tracking_register_importers() {
    register_importer( 'wc_custom_xml_shipment_tracking', __( 'WooCommerce Shipment Tracking Numbers (XML)', 'woocommerce' ), __( 'Import shipment tracking numbers to your store via a XML file.', 'woocommerce'), 'wc_custom_xml_shipment_tracking_importer' );
}

/**
 * Add menu item
 */
function wc_custom_xml_shipment_tracking_importer() {
    // Load Importer API
    require_once ABSPATH . 'wp-admin/includes/import.php';

    if ( ! class_exists( 'WP_Importer' ) ) {
        $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
        if ( file_exists( $class_wp_importer ) )
            require $class_wp_importer;
    }

    // includes
    require 'importers/class-custom-xml-shipment-tracking-importer.php';

    // Dispatch
    $importer = new WC_Custom_XML_Shipment_Tracking_Importer();
    $importer->dispatch();
}
// Scheduled Action Hook
function wc_custom_xml_cron_action( ) {
	import_from_path();
}
// if scheduled, run it :)  
add_action( 'wc_custom_xml_cron_scheduler','wc_custom_xml_cron_action' );

// Schedule Cron Job Event
function wc_custom_xml_schedule_xml_fetch_cron() {
	wp_clear_scheduled_hook('wc_custom_xml_cron_scheduler');
	
	if ( ! wp_next_scheduled( 'wc_custom_xml_cron_scheduler' ) ) {
		wp_schedule_event( strtotime('04:00:00'), 'daily', 'wc_custom_xml_cron_scheduler' );
	}
	
}
add_action( 'wp', 'wc_custom_xml_schedule_xml_fetch_cron' );

/**
 * import function.
 *
 * @access public
 * @param mixed $file
 * @return void
 */
function import_from_path(  ) {
	$to = get_option('admin_email');
	// $to = 'rayflores88@gmail.com';
	$message = '';
	$from = 'From: XML Importer' . $to;
	$headers = array('Content-Type: text/html; charset=UTF-8',$from);
	
	$path = $_SERVER['DOCUMENT_ROOT'] . '/bmorders/bmimports'; 
	$newpath = $_SERVER['DOCUMENT_ROOT'] . '/bmorders/bmimports/completed_bmimports'; 
	$moved = 0;
	$latest_filename = '';    
		// now let's move the files to archive
	// Open a known directory, and proceed to read its contents
    if (is_dir($path)) {
        if ($dh = opendir($path)) {
            while (($file = readdir($dh)) !== false) {
				if ($file==".") continue;
				if ($file=="..")continue;
				if (rename($path.'/'.$file, $newpath.'/'.$file)){
					$moved++;
				}
			}
			closedir($dh);
		}
	}
	$d = dir($newpath);
	$filnames = array();
	while (false !== ($entry = $d->read())) {
	  $filepath = "{$newpath}/{$entry}";
	  // could do also other checks than just checking whether the entry is a file
	  if ( is_file($filepath) ) {
		$latest_ctime = filectime($filepath);
		$latest_filename = $entry;
		$filenames[] = $entry;
	  }
	}
	
	$total = $imported = $carriers = $skipped = 0;
	$loop = 0;
	foreach ($filenames as $onefile){
		
	$newest_file = $newpath . '/' . $onefile;
	
	$temp = file_get_contents($newest_file);
	
	$skipped_report = '';
	$true = false;
	// if ( file_exists($file) ) {
		$xml = simplexml_load_string(stripslashes('<?xml version="1.0" encoding="utf-8"?>'.$temp)); 
		
		$true = true;
	// }
		if ( isset($xml->shipment) ) {
			
			
			$success = false;
			foreach ( $xml as $uploaded_file ) {
				
				// ordernumber
				$XMLorder_id = $xml->shipment->ordernumber;
				$order_number = (int)$XMLorder_id; // needs to be an integer
				
				// ******* IMPORTANT ******** //
				// NEED THIS BECAUSE WE ARE USING SEQUENTIAL ORDER NUMBER PRO //
				$order_id = wc_seq_order_number_pro()->find_order_by_order_number( $order_number );  
				
				
				// trackingnumber
				$XMLtracking_number = $xml->shipment->trackingnumber;
				$tracking_number = (string)$XMLtracking_number; // needs to be a string
				// carrier
				$XMLcarrier = $xml->shipment->carrier;
				$carrier = (string)$XMLcarrier; // needs to be a string
				$tracker = substr($carrier, 0, 4);

				$row = count($xml->children());
				
				// verify order is found
					// verify order is found
				if ( $order_id > 0 ) {

					// verify tracking number
					if ( $tracking_number !== '' ) {
						if ($tracker === "USPS"){
							
							$tracking_provider = 'usps';
						} else {
							$tracking_provider = 'ups';
						}
						$custom_tracking_provider = '';
						$custom_tracking_link     = '';
						$date_shipped             = time();
						// Update order data
						if ( class_exists('WC_Shipment_Tracking_Actions') ) {
							$WC_Shipment_Tracking_Actions = new WC_Shipment_Tracking_Actions();
							$tracking_order_id = wc_clean( $order_id );
							$args = array(
								'tracking_provider'        => wc_clean( $tracking_provider ),
								'custom_tracking_provider' => wc_clean( $custom_tracking_provider ),
								'custom_tracking_link'     => wc_clean( $custom_tracking_link ),
								'tracking_number'          => wc_clean( $tracking_number ),
								'date_shipped'             => wc_clean( $date_shipped ),
							);

							$pre_existing_tracking = $WC_Shipment_Tracking_Actions->get_tracking_items( $tracking_order_id, true );
									
									if ( count( $pre_existing_tracking ) === 0 ) {
										$tracking_item = $WC_Shipment_Tracking_Actions->add_tracking_item( $tracking_order_id, $args );
										$success = true;
									} else {
										$skipped++;
									}
						}
						$order = new WC_Order( $order_id );
						$order->update_status( 'completed' );
						

						$loop++;
						$imported++;
						$carriers++;
					} else {
						$message .= "<p>Skipped shipment " . $loop . ", Order ID=" . $order_id . ", Tracking Number is invalid.</p>";
						$loop ++;
						$skipped++;
						wp_mail( $to, 'XML Import: Tracking Number invalid', $message );
					}
				} else {
					echo "<p>Skipped shipment " . $loop . ", Order ID=" . $order_id . ", Order not found.</p>";
					$loop++;
					$skipped++;
					wp_mail( $to, 'XML Import: Order not found', $message );
				}
				
			}

			$total = $loop;

		} else {
			$message .=  __( 'Sorry, there has been an error.', 'woocommerce' );
			$message .= __( 'The XML is invalid.', 'woocommerce' );
			$message .= 
			wp_mail( $to, 'XML Import: The XML is invalid', $message );
			die();

		}

    		
	} // end foreach

			if ($success === true ){
		// send Result
			$message .= sprintf( __( 'Import complete: Totals: %s order number found, %s tracking numbers, %s carriers and skipped %s and %s files moved to archive folder.', 'woocommerce' ), $total, $imported, $carriers, $skipped, $moved );
			$headers = "Disposition-Notification-To: yourEmailID\n";
			wp_mail( $to, 'XML Import complete', $message );
			} 
			
}

