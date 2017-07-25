<?php
/**
 * Shipment Tracking importer
 *
 * Import shipment tracking numbers and set meta data to existing WooCommerce orders
 *
 * @author 		GrowDevelopment
 * @category 	Admin
 * @package 	WooCommerce/Admin/Importers
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( class_exists( 'WP_Importer' ) ) {
    class WC_Custom_XML_Shipment_Tracking_Importer extends WP_Importer {

        var $id;
        var $file_url;
        var $import_page;
        var $delimiter = ",";
        var $posts = array();
        var $total;
        var $imported;
		var $carriers;
        var $skipped;

        /**
         * __construct function.
         *
         * @access public
         */
        public function __construct() {
            $this->import_page = 'wc_custom_xml_shipment_tracking';
        }

        /**
         * Registered callback function for the WordPress Importer
         *
         * Manages the three separate stages of the CSV import process
         */
        function dispatch() {
            $this->header();

            $step = empty( $_GET['step'] ) ? 0 : (int) $_GET['step'];
            switch ( $step ) {
                case 0:
                    $this->greet();
                    break;
                case 1:
					check_admin_referer( 'import-upload' );
						
                    if ( $this->handle_upload() ) {

                        if ( $this->id )
                            $file = get_attached_file( $this->id );
                        else
                            $file = ABSPATH . $this->file_url;

                        add_filter( 'http_request_timeout', array( $this, 'bump_request_timeout' ) );

                        if ( function_exists( 'gc_enable' ) )
                            gc_enable();

                        @set_time_limit(0);
                        @ob_flush();
                        @flush();

                        $this->import( $file );
                    }
                    break;
			}
            $this->footer();
        }


        /**
         * import function.
         *
         * @access public
         * @param mixed $file
         * @return void
         */
        function import( $file ) {

            $this->total = $this->imported = $this->carriers = $this->skipped = 0;
            $skipped_report = '';
			$xml = simplexml_load_file($file, "SimpleXMLElement",LIBXML_NOCDATA);
		
            if ( $file === FALSE ) {
                echo '<p><strong>' . __( 'Sorry, there has been an error.', 'woocommerce' ) . '</strong><br />';
                echo __( 'The file does not exist, please try again.', 'woocommerce' ) . '</p>';
                $this->footer();
                die();
            }

			
            else {

                

                if ( file_exists($file) ) {

                    $loop = 0;

                    foreach ( $xml as $uploaded_file ) {
						
						// ordernumber
						$XMLorder_id = $this->getXMLnode($xml, 'ordernumber');
						$order_id = (int)$XMLorder_id; // needs to be an integer
						// trackingnumber
						$XMLtracking_number = $this->getXMLnode($xml, 'trackingnumber');
						$tracking_number = (string)$XMLtracking_number; // needs to be a string
						// carrier
						$XMLcarrier = $this->getXMLnode($xml, 'carrier');
						$carrier = (string)$XMLcarrier; // needs to be a string

                        $row = count($xml->children());
						
                        // verify order is found
                        	// verify order is found
                        if ( $order_id > 0 ) {

                            // verify tracking number
                            if ( $tracking_number !== '' ) {
                                $tracking_provider        = 'ups';
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
									}
								}

                                // Send email
                               // WC()->mailer();
                                //do_action('woocommerce_shipping_tracking_number_added', $order_id );

                                $loop++;
                                $this->imported++;
								$this->carriers++;
                            } else {
                                echo  "<p>Skipped shipment " . $loop . ", Order ID=" . $order_id . ", Tracking Number is invalid.</p>";
                                $loop ++;
                                $this->skipped++;
                            }
                        } else {
                            echo "<p>Skipped shipment " . $loop . ", Order ID=" . $order_id . ", Order not found.</p>";
                            $loop++;
                            $this->skipped++;
                        }
                    }

                    $this->total = $loop;

                } else {

                    echo '<p><strong>' . __( 'Sorry, there has been an error.', 'woocommerce' ) . '</strong><br />';
                    echo __( 'The XML is invalid.', 'woocommerce' ) . '</p>';
                    $this->footer();
                    die();

                }

            }

            // Show Result
            echo '<div class="updated settings-error below-h2"><p>
				'.sprintf( __( 'Import complete: Totals: <strong>%s</strong> order number found, <strong>%s</strong> tracking numbers, <strong>%s</strong> carriers and skipped <strong>%s</strong>.', 'woocommerce' ), $this->total, $this->imported, $this->carriers, $this->skipped ).'
			</p>

			</div>';

            $this->import_end();
        }
		/** When searching a specific node in the object need to use this function
		*/
		public function &getXMLnode($object, $param) {
			foreach($object as $key => $value) {
				if(isset($object->$key->$param)) {
					return $object->$key->$param;
				}
				if(is_object($object->$key)&&!empty($object->$key)) {
					$new_obj = $object->$key;
					$ret = getCfgParam($new_obj, $param);    
				}
			}
			if($ret) return (string) $ret;
        return false;
    }
        /**
         * Performs post-import cleanup of files and the cache
         */
        function import_end() {
            echo '<p>' . __( 'All done!', 'woocommerce' ) . '</p>';

            do_action( 'import_end' );
        }

        /**
         * Handles the CSV upload and initial parsing of the file to prepare for
         * displaying author import options
         *
         * @return bool False if error uploading or invalid file, true otherwise
         */
        function handle_upload() {

            if ( empty( $_POST['file_url'] ) ) {

                $file = wp_import_handle_upload();

                if ( isset( $file['error'] ) ) {
                    echo '<p><strong>' . __( 'Sorry, there has been an error.', 'woocommerce' ) . '</strong><br />';
                    echo esc_html( $file['error'] ) . '</p>';
                    return false;
                }

                $this->id = (int) $file['id'];

            } else {

                if ( file_exists( ABSPATH . $_POST['file_url'] ) ) {

                    $this->file_url = esc_attr( $_POST['file_url'] );

                } else {

                    echo '<p><strong>' . __( 'Sorry, there has been an error.', 'woocommerce' ) . '</strong></p>';
                    return false;

                }

            }

            return true;
        }

        /**
         * header function.
         *
         * @access public
         * @return void
         */
        function header() {
            echo '<div class="wrap"><div class="icon32 icon32-woocommerce-importer" id="icon-woocommerce"><br></div>';
            echo '<h2>' . __( 'Import Shipment Tracking Numbers', 'woocommerce' ) . '</h2>';
        }

        /**
         * footer function.
         *
         * @access public
         * @return void
         */
        function footer() {
            echo '</div>';
        }

        /**
         * greet function.
         *
         * @access public
         * @return void
         */
        function greet() {

            echo '<div class="narrow">';
            echo '<p>' . __( 'Howdy! Upload a XML file containing shipment tracking numbers to import. Choose a .xml file to upload, then click "Upload file and import".', 'woocommerce' ).'</p>';

            echo '<p>' . __( 'The file needs to have three elements: Order number, Tracking number, Carrier', 'woocommerce' ) . '</p>';

            $action = 'admin.php?import=wc_custom_xml_shipment_tracking&step=1';

            $bytes = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
            $size = size_format( $bytes );
            $upload_dir = wp_upload_dir();
            if ( ! empty( $upload_dir['error'] ) ) :
                ?><div class="error"><p><?php _e( 'Before you can upload your import file, you will need to fix the following error:', 'woocommerce' ); ?></p>
                <p><strong><?php echo $upload_dir['error']; ?></strong></p></div><?php
            else :
                ?>
                <form enctype="multipart/form-data" id="import-upload-form" method="post" action="<?php echo esc_attr(wp_nonce_url($action, 'import-upload')); ?>">
                    <table class="form-table">
                        <tbody>
                        <tr>
                            <th>
                                <label for="upload"><?php _e( 'Choose a file from your computer:', 'woocommerce' ); ?></label>
                            </th>
                            <td>
                                <input type="file" id="upload" name="import" size="25" />
                                <input type="hidden" name="action" value="save" />
                                <input type="hidden" name="max_file_size" value="<?php echo $bytes; ?>" />
                                <small><?php printf( __('Maximum size: %s', 'woocommerce' ), $size ); ?></small>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                    <p class="submit">
                        <input type="submit" class="button" value="<?php esc_attr_e( 'Upload file and import', 'woocommerce' ); ?>" />
                    </p>
                </form>
            <?php
            endif;

            echo '</div>';
        }

        /**
         * Added to http_request_timeout filter to force timeout at 60 seconds during import
         * @param  int $val
         * @return int 60
         */
        function bump_request_timeout( $val ) {
            return 60;
        }
    }
}
