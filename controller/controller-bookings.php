<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) { exit; }

// BOOKINGS
	
	// Get the HTML booking list for a given event
	/**
	 * AJAX Controller - Get booking rows
	 * 
	 * @version 1.3.0
	 */
	function bookacti_controller_get_booking_rows() {
		
		// Check nonce and capabilities
		$is_nonce_valid	= check_ajax_referer( 'bookacti_get_booking_rows', 'nonce', false );
		$is_allowed		= current_user_can( 'bookacti_edit_bookings' );

		if( $is_nonce_valid && $is_allowed ) {
			$Bookings_List_Table = new Bookings_List_Table();
			$Bookings_List_Table->prepare_items( array(), true );
			$rows = $Bookings_List_Table->get_rows_or_placeholder();
			
			if( $rows ) {
				wp_send_json( array( 'status' => 'success', 'rows' => $rows ) );
			} else {
				wp_send_json( array( 'status' => 'failed', 'error' => 'no_rows' ) );
			}
        } else {
			wp_send_json( array( 'status' => 'failed', 'error' => 'not_allowed' ) );
		}
	}
	add_action( 'wp_ajax_bookactiGetBookingRows', 'bookacti_controller_get_booking_rows' );



// BOOKINGS FILTERS

	/**
	 * AJAX Controller - Change selected template
	 * 
	 * @version 1.3.0
	 */
    function bookacti_controller_select_template_filter() {
		
		// Check nonce
		$is_nonce_valid = check_ajax_referer( 'bookacti_selected_template_filter', 'nonce', false );
		
		// Check capabilities
		$is_allowed = current_user_can( 'bookacti_read_templates' );
		if( $is_allowed ){
			// Get selected templates and format them
			$template_ids = bookacti_ids_to_array( $_POST[ 'template_ids' ] );

			// Remove templates current user is not allowed to manage
			foreach( $template_ids as $i => $template_id ){
				if( ! bookacti_user_can_manage_template( $template_id ) ){
					unset( $template_ids[ $i ] );
				}
			}
			// If none remains, disallow the action
			if( empty( $template_ids ) ) { $is_allowed = false; }
		}
		
		if( $is_nonce_valid && $is_allowed ) {
			
			// Change default template to the first selected
			update_user_meta( get_current_user_id(), 'bookacti_default_template', $template_ids[ 0 ] );
			
			// Actvity filters change depending on the templates selection, 
			// this retrieve the HTML for activity filters corresponding to templates selection
			$activities_html = bookacti_get_activities_html_for_booking_page( $template_ids );
			
			// Get calendar settings
			$template_data		= bookacti_get_mixed_template_data( $template_ids, true );
			$activity_ids		= bookacti_get_activity_ids_by_template( $template_ids, false );
			$group_categories	= bookacti_get_group_category_ids_by_template( $template_ids );
			
			$events_interval	= bookacti_get_new_interval_of_events( $template_data, array(), false, true );
			$events				= bookacti_fetch_booked_events( $template_ids, array(), array(), 0, true, $events_interval );
			$activities_data	= bookacti_get_activities_by_template( $template_ids, true );
			$groups_events		= bookacti_get_groups_events( $template_ids, $group_categories, array(), true );
			$groups_data		= bookacti_get_groups_of_events_by_template( $template_ids );
			$categories_data	= bookacti_get_group_categories_by_template( $template_ids );
			$bookings			= bookacti_get_number_of_bookings_by_events( $template_ids );
			
			wp_send_json( array( 
				'status'				=> 'success', 
				'activities_html'		=> $activities_html, 
				'events'				=> $events[ 'events' ] ? $events[ 'events' ] : array(), 
				'events_data'			=> $events[ 'data' ] ? $events[ 'data' ] : array(), 
				'events_interval'		=> $events_interval,
				'activities_data'		=> $activities_data, 
				'groups_events'			=> $groups_events,
				'groups_data'			=> $groups_data,
				'calendar_ids'			=> $template_ids,
				'activity_ids'			=> $activity_ids,
				'group_categories'		=> $group_categories,
				'group_categories_data'	=> $categories_data,
				'template_data'			=> $template_data,
				'bookings'				=> $bookings
			) );
		} else {
			wp_send_json( array( 'status' => 'failed', 'error' => 'not_allowed' ) );
		}
	}
	add_action( 'wp_ajax_bookactiSelectTemplateFilter', 'bookacti_controller_select_template_filter' );




// BOOKING ACTIONS
	
	// SINGLE BOOKING
		/**
		 * AJAX Controller - Cancel a booking
		 * 
		 * @version 1.3.0
		 */
		function bookacti_controller_cancel_booking() {

			$booking_id = intval( $_POST[ 'booking_id' ] );

			// Check nonce, capabilities and other params
			$is_nonce_valid		= check_ajax_referer( 'bookacti_cancel_booking', 'nonce', false );
			$is_allowed			= bookacti_user_can_manage_booking( $booking_id );
			$can_be_cancelled	= bookacti_booking_can_be_cancelled( $booking_id );

			if( $is_nonce_valid && $is_allowed && $can_be_cancelled ) {

				$cancelled = bookacti_cancel_booking( $booking_id );

				if( $cancelled ) {

					do_action( 'bookacti_booking_state_changed', $booking_id, 'cancelled', array( 'is_admin' => false ) );

					$allow_refund	= bookacti_booking_can_be_refunded( $booking_id );
					$actions_html	= bookacti_get_booking_actions_html( $booking_id, 'front' );
					$formatted_state= bookacti_format_booking_state( 'cancelled', false );

					wp_send_json( array( 'status' => 'success', 'allow_refund' => $allow_refund, 'new_actions_html' => $actions_html, 'formatted_state' => $formatted_state ) );

				} else {
					wp_send_json( array( 'status' => 'failed' ) );
				}

			} else {
				wp_send_json( array( 'status' => 'failed', 'error' => 'not_allowed' ) );
			}
		}
		add_action( 'wp_ajax_bookactiCancelBooking', 'bookacti_controller_cancel_booking' );
		add_action( 'wp_ajax_nopriv_bookactiCancelBooking', 'bookacti_controller_cancel_booking' );


		/**
		 * AJAX Controller - Get possible actions to refund a booking
		 */
		function bookacti_controller_get_refund_actions_html() {

			$booking_id = intval( $_POST[ 'booking_id' ] );

			// Check nonce, capabilities and other params
			$is_nonce_valid	= check_ajax_referer( 'bookacti_get_refund_actions_html', 'nonce', false );
			$is_allowed		= bookacti_user_can_manage_booking( $booking_id );
			$can_be_refund	= bookacti_booking_can_be_refunded( $booking_id );

			if( $is_nonce_valid && $is_allowed && $can_be_refund ) {

				$refund_actions_array	= bookacti_get_refund_actions_by_booking_id( $booking_id );
				$refund_actions_html	= bookacti_get_refund_dialog_html_by_booking_id( $booking_id );

				if( ! empty( $refund_actions_html ) ) {

					wp_send_json( array( 'status' => 'success', 'actions_html' => $refund_actions_html, 'actions_array' => $refund_actions_array ) );

				} else {
					wp_send_json( array( 'status' => 'failed' ) );
				}

			} else {
				wp_send_json( array( 'status' => 'failed', 'error' => 'not_allowed' ) );
			}
		}
		add_action( 'wp_ajax_bookactiGetBookingRefundActionsHTML', 'bookacti_controller_get_refund_actions_html' );
		add_action( 'wp_ajax_nopriv_bookactiGetBookingRefundActionsHTML', 'bookacti_controller_get_refund_actions_html' );


		/**
		 * AJAX Controller - Refund a booking
		 * 
		 * @version 1.3.0
		 */
		function bookacti_controller_refund_booking() {

			$booking_id			= intval( $_POST[ 'booking_id' ] );
			$is_admin			= intval( $_POST[ 'is_admin' ] );
			$sanitized_action	= sanitize_title_with_dashes( stripslashes( $_POST[ 'refund_action' ] ) );
			$refund_action		= array_key_exists( $sanitized_action, bookacti_get_refund_actions_by_booking_id( $booking_id ) ) ? $sanitized_action : 'email';

			// Check nonce, capabilities and other params
			$is_nonce_valid	= check_ajax_referer( 'bookacti_refund_booking', 'nonce', false );
			$is_allowed		= bookacti_user_can_manage_booking( $booking_id );
			$can_be_refund	= bookacti_booking_can_be_refunded( $booking_id, $refund_action );

			if( $is_nonce_valid && $is_allowed && $can_be_refund ) {

				$refund_message	= sanitize_text_field( stripslashes( $_POST[ 'refund_message' ] ) );

				if( $refund_action === 'email' ) {
					$refunded = bookacti_send_email_refund_request( $booking_id, 'single', $refund_message );
					if( $refunded ) {
						$refunded = array( 'status' => 'success', 'new_state' => 'refund_requested' );
					} else {
						$refunded = array( 'status' => 'failed', 'error' => 'cannot_send_email' );
					}
				} else {
					$refunded = apply_filters( 'bookacti_refund_booking', array( 'status' => 'failed' ), $booking_id, 'single', $refund_action, $refund_message );
				}

				if( $refunded[ 'status' ] === 'success' ) {
					$new_state = $refunded[ 'new_state' ] ? $refunded[ 'new_state' ] : 'refunded';
					$updated = bookacti_update_booking_state( $booking_id, $new_state );

					// Hook status changes
					if( $updated ) {
						do_action( 'bookacti_booking_state_changed', $booking_id, $new_state, array( 'is_admin' => $is_admin, 'refund_action' => $refund_action ) );
					}

					// Get new booking actions
					$admin_or_front = $is_admin ? 'admin' : 'front';
					$actions_html	= bookacti_get_booking_actions_html( $booking_id, $admin_or_front );
					$refunded[ 'new_actions_html' ] = $actions_html;

					// Get new booking state formatted
					$refunded[ 'formatted_state' ] = bookacti_format_booking_state( $new_state, $is_admin );
				}

				wp_send_json( $refunded );

			} else {
				wp_send_json( array( 'status' => 'failed', 'error' => 'not_allowed' ) );
			}
		}
		add_action( 'wp_ajax_bookactiRefundBooking', 'bookacti_controller_refund_booking' );
		add_action( 'wp_ajax_nopriv_bookactiRefundBooking', 'bookacti_controller_refund_booking' );


		/**
		 * AJAX Controller - Change booking state
		 * 
		 * @version 1.3.0
		 */
		function bookacti_controller_change_booking_state() {

			$booking_id			= intval( $_POST[ 'booking_id' ] );
			$sanitized_state	= sanitize_title_with_dashes( $_POST[ 'new_state' ] );
			$send_notifications	= $_POST[ 'send_notifications' ] ? 1 : 0;
			$new_state			= array_key_exists( $sanitized_state, bookacti_get_booking_state_labels() ) ? $sanitized_state : false;

			// Check nonce, capabilities and other params
			$is_nonce_valid			= check_ajax_referer( 'bookacti_change_booking_state', 'nonce', false );
			$is_allowed				= current_user_can( 'bookacti_edit_bookings' );		
			$state_can_be_changed	= bookacti_booking_state_can_be_changed_to( $booking_id, $new_state );

			if( $is_nonce_valid && $is_allowed && $state_can_be_changed && $new_state ) {

				$was_active	= bookacti_is_booking_active( $booking_id ) ? 1 : 0;
				$active		= in_array( $new_state, bookacti_get_active_booking_states(), true ) ? 1 : 0;

				if( ! $was_active && $active ) {
					$booking	= bookacti_get_booking_by_id( $booking_id );
					$validated	= bookacti_validate_booking_form( 'single', $booking->event_id, $booking->event_start, $booking->event_end, $booking->quantity );
				} else {
					$validated['status'] = 'success';
				}

				if( $validated['status'] === 'success' ) {
					$updated= bookacti_update_booking_state( $booking_id, $new_state );

					if( $updated ) {
						
						$is_bookings_page	= intval( $_POST[ 'is_bookings_page' ] ) === 1 ? true : false;
						do_action( 'bookacti_booking_state_changed', $booking_id, $new_state, array( 'is_admin' => $is_bookings_page, 'active' => $active, 'send_notifications' => $send_notifications ) );
						
						$actions_html	= bookacti_get_booking_actions_html( $booking_id, 'admin' );
						$formatted_state= bookacti_format_booking_state( $new_state, $is_bookings_page );
						$active_changed = $active === $was_active ? false : true;
						
						wp_send_json( array( 'status' => 'success', 'new_actions_html' => $actions_html, 'formatted_state' => $formatted_state, 'active_changed' => $active_changed ) );
					} else {
						wp_send_json( array( 'status' => 'failed' ) );
					}
				} else {
					wp_send_json( array( 'status' => 'failed', 'error' => $validated['error'], 'message' => esc_html( $validated['message'] ) ) );
				}

			} else {
				wp_send_json( array( 'status' => 'failed', 'error' => 'not_allowed' ) );
			}
		}
		add_action( 'wp_ajax_bookactiChangeBookingState', 'bookacti_controller_change_booking_state' );


		/**
		 * AJAX Controller - Get booking system data by booking ID
		 * 
		 * @version 1.2.2
		 */
		function bookacti_controller_get_booking_data() {

			// Check nonce, no need to check capabilities
			$is_nonce_valid = check_ajax_referer( 'bookacti_get_booking_data', 'nonce', false );

			if( $is_nonce_valid ) {

				$booking_id	= intval( $_POST[ 'booking_id' ] );
				$booking_data = bookacti_get_booking_data( $booking_id );

				if( is_array( $booking_data ) && ! empty( $booking_data ) ) {
					wp_send_json( array( 'status' => 'success', 'booking_data' => $booking_data ) );
				} else {
					wp_send_json( array( 'status' => 'failed', 'error' => 'empty_data' ) );
				}

			} else {
				wp_send_json( array( 'status' => 'failed', 'error' => 'not_allowed' ) );
			}
		}
		add_action( 'wp_ajax_bookactiGetBookingData', 'bookacti_controller_get_booking_data' );
		add_action( 'wp_ajax_nopriv_bookactiGetBookingData', 'bookacti_controller_get_booking_data' );


		/**
		 * AJAX Controller - Reschedule a booking
		 * 
		 * @version 1.3.0
		 */
		function bookacti_controller_reschedule_booking() {

			$booking_id			= intval( $_POST[ 'booking_id' ] );
			$event_id			= intval( $_POST[ 'event_id' ] );
			$event_start		= bookacti_sanitize_datetime( $_POST[ 'event_start' ] );
			$event_end			= bookacti_sanitize_datetime( $_POST[ 'event_end' ] );

			// Check nonce, capabilities and other params
			$is_nonce_valid		= check_ajax_referer( 'bookacti_reschedule_booking', 'nonce', false );
			$is_allowed			= bookacti_user_can_manage_booking( $booking_id );
			$can_be_rescheduled	= bookacti_booking_can_be_rescheduled_to( $booking_id, $event_id, $event_start, $event_end );
			
			if( ! $is_nonce_valid || ! $is_allowed || !$can_be_rescheduled ) {
				wp_send_json( array( 'status' => 'failed', 'error' => 'not_allowed', 'message' => esc_html__( 'You are not allowed to do this.', BOOKACTI_PLUGIN_NAME ) ) );
			}

			// Validate availability
			$booking	= bookacti_get_booking_by_id( $booking_id );
			$validated	= bookacti_validate_booking_form( 'single', $event_id, $event_start, $event_end, $booking->quantity );

			if( $validated[ 'status' ] !== 'success' ) {
				wp_send_json( array( 'status' => 'failed', 'error' => $validated['error'], 'message' => esc_html( $validated[ 'message' ] ) ) );
			}

			$rescheduled = bookacti_reschedule_booking( $booking_id, $event_id, $event_start, $event_end );
			
			if( $rescheduled === 0 ) {
				$message = __( 'You must select a different time slot than the current one.', BOOKACTI_PLUGIN_NAME );
				wp_send_json( array( 'status' => 'no_changes', 'error' => 'no_changes', 'message' => $message ) );
			}
			
			if( ! $rescheduled ) {
				wp_send_json( array( 'status' => 'failed' ) );
			}

			$is_bookings_page	= intval( $_POST[ 'is_bookings_page' ] );
			$send_notifications	= $is_bookings_page ? intval( $_POST[ 'send_notifications' ] ) : 1;

			do_action( 'bookacti_booking_rescheduled', $booking_id, $booking, array( 'is_admin' => $is_bookings_page, 'send_notifications' => $send_notifications ) );

			$admin_or_front		= $is_bookings_page ? 'admin' : 'front';
			$actions_html		= bookacti_get_booking_actions_html( $booking_id, $admin_or_front );

			if( $is_bookings_page ) {
				$Bookings_List_Table = new Bookings_List_Table();
				$Bookings_List_Table->prepare_items( array( 'booking_id' => $booking_id ), true );
				$row = $Bookings_List_Table->get_rows_or_placeholder();
			} else {
				$user_id	= get_current_user_id();
				$booking	= bookacti_get_booking_by_id( $booking_id );
				$columns	= bookacti_get_booking_list_columns( $user_id );
				$row		= bookacti_get_booking_list_rows( array( $booking ), $columns, $user_id );
			}

			wp_send_json( array( 'status' => 'success', 'actions_html' => $actions_html, 'row' => $row ) );
		}
		add_action( 'wp_ajax_bookactiRescheduleBooking', 'bookacti_controller_reschedule_booking' );
		add_action( 'wp_ajax_nopriv_bookactiRescheduleBooking', 'bookacti_controller_reschedule_booking' );
	
	
	
	// BOOKING GROUPS
		
		/**
		 * Trigger bookacti_booking_state_changed for each bookings of the group when bookacti_booking_group_state_changed is called
		 * 
		 * @since 1.2.0
		 * @param int $booking_group_id
		 * @param string $status
		 * @param array $args
		 */
		function bookacti_trigger_booking_state_change_for_each_booking_of_a_group( $booking_group_id, $status , $args ) {
			$bookings = bookacti_get_bookings_by_booking_group_id( $booking_group_id );
			$args[ 'booking_group_state_changed' ] = true;
			foreach( $bookings as $booking ) {
				do_action( 'bookacti_booking_state_changed', $booking->id, $status, $args );
			}
		}
		add_action( 'bookacti_booking_group_state_changed', 'bookacti_trigger_booking_state_change_for_each_booking_of_a_group', 10, 3 );
		
		
		/**
		 * AJAX Controller - Cancel a booking group
		 * 
		 * @since 1.1.0
		 * @version 1.3.0
		 */
		function bookacti_controller_cancel_booking_group() {

			$booking_group_id = intval( $_POST[ 'booking_id' ] );
			
			// Check nonce, capabilities and other params
			$is_nonce_valid		= check_ajax_referer( 'bookacti_cancel_booking', 'nonce', false );
			$is_allowed			= bookacti_user_can_manage_booking_group( $booking_group_id );
			$can_be_cancelled	= bookacti_booking_group_can_be_cancelled( $booking_group_id );

			if( $is_nonce_valid && $is_allowed && $can_be_cancelled ) {
				
				$cancelled = bookacti_update_booking_group_state( $booking_group_id, 'cancelled', 'auto', true );

				if( $cancelled ) {

					do_action( 'bookacti_booking_group_state_changed', $booking_group_id, 'cancelled', array( 'is_admin' => false ) );
					
					$allow_refund	= bookacti_booking_group_can_be_refunded( $booking_group_id );
					$actions_html	= bookacti_get_booking_group_actions_html( $booking_group_id, 'front' );
					$formatted_state= bookacti_format_booking_state( 'cancelled', false );
					
					wp_send_json( array( 'status' => 'success', 'allow_refund' => $allow_refund, 'new_actions_html' => $actions_html, 'formatted_state' => $formatted_state ) );

				} else {
					wp_send_json( array( 'status' => 'failed' ) );
				}

			} else {
				wp_send_json( array( 'status' => 'failed', 'error' => 'not_allowed' ) );
			}
		}
		add_action( 'wp_ajax_bookactiCancelBookingGroup', 'bookacti_controller_cancel_booking_group' );
		add_action( 'wp_ajax_nopriv_bookactiCancelBookingGroup', 'bookacti_controller_cancel_booking_group' );


		/**
		 * AJAX Controller - Get possible actions to refund a booking group
		 * 
		 * @since 1.1.0
		 */
		function bookacti_controller_get_booking_group_refund_actions_html() {

			$booking_group_id = intval( $_POST[ 'booking_id' ] );

			// Check nonce, capabilities and other params
			$is_nonce_valid	= check_ajax_referer( 'bookacti_get_refund_actions_html', 'nonce', false );
			$is_allowed		= bookacti_user_can_manage_booking_group( $booking_group_id );
			$can_be_refund	= bookacti_booking_group_can_be_refunded( $booking_group_id );

			if( $is_nonce_valid && $is_allowed && $can_be_refund ) {

				$refund_actions_array	= bookacti_get_refund_actions_by_booking_group_id( $booking_group_id );
				$refund_actions_html	= bookacti_get_refund_dialog_html_by_booking_group_id( $booking_group_id );

				if( ! empty( $refund_actions_html ) ) {

					wp_send_json( array( 'status' => 'success', 'actions_html' => $refund_actions_html, 'actions_array' => $refund_actions_array ) );

				} else {
					wp_send_json( array( 'status' => 'failed' ) );
				}

			} else {
				wp_send_json( array( 'status' => 'failed', 'error' => 'not_allowed' ) );
			}
		}
		add_action( 'wp_ajax_bookactiGetBookingGroupRefundActionsHTML', 'bookacti_controller_get_booking_group_refund_actions_html' );
		add_action( 'wp_ajax_nopriv_bookactiGetBookingGroupRefundActionsHTML', 'bookacti_controller_get_booking_group_refund_actions_html' );


		/**
		 * AJAX Controller - Refund a booking group
		 * 
		 * @since 1.1.0
		 * @version 1.3.0
		 */
		function bookacti_controller_refund_booking_group() {

			$booking_group_id	= intval( $_POST[ 'booking_id' ] );
			$is_admin			= intval( $_POST[ 'is_admin' ] );
			$sanitized_action	= sanitize_title_with_dashes( stripslashes( $_POST[ 'refund_action' ] ) );
			$refund_action		= array_key_exists( $sanitized_action, bookacti_get_refund_actions_by_booking_group_id( $booking_group_id ) ) ? $sanitized_action : 'email';
			
			// Check nonce, capabilities and other params
			$is_nonce_valid	= check_ajax_referer( 'bookacti_refund_booking', 'nonce', false );
			$is_allowed		= bookacti_user_can_manage_booking_group( $booking_group_id );
			$can_be_refund	= bookacti_booking_group_can_be_refunded( $booking_group_id, $refund_action );

			if( $is_nonce_valid && $is_allowed && $can_be_refund ) {

				$refund_message	= sanitize_text_field( stripslashes( $_POST[ 'refund_message' ] ) );

				if( $refund_action === 'email' ) {
					$refunded = bookacti_send_email_refund_request( $booking_group_id, 'group', $refund_message );
					if( $refunded ) {
						$refunded = array( 'status' => 'success', 'new_state' => 'refund_requested' );
					} else {
						$refunded = array( 'status' => 'failed', 'error' => 'cannot_send_email' );
					}
				} else {
					$refunded = apply_filters( 'bookacti_refund_booking', array( 'status' => 'failed' ), $booking_group_id, 'group', $refund_action, $refund_message );
				}
				
				if( $refunded[ 'status' ] === 'success' ) {
					
					$booking_ids= bookacti_get_booking_group_bookings_ids( $booking_group_id );
					$new_state	= $refunded[ 'new_state' ] ? $refunded[ 'new_state' ] : 'refunded';
					$updated	= bookacti_update_booking_group_state( $booking_group_id, $new_state, 'auto', true );

					// Hook status changes
					if( $updated ) {
						do_action( 'bookacti_booking_group_state_changed', $booking_group_id, $new_state, array( 'is_admin' => $is_admin, 'refund_action' => $refund_action ) );
					}
					
					// Get new booking actions
					$admin_or_front = $is_admin ? 'admin' : 'front';
					$actions_html	= bookacti_get_booking_group_actions_html( $booking_group_id, $admin_or_front );
					$refunded[ 'new_actions_html' ] = $actions_html;

					// Get new booking state formatted
					$refunded[ 'formatted_state' ] = bookacti_format_booking_state( $new_state, $is_admin );
					
					// Get grouped booking rows if they are displayed and need to be refreshed
					$rows = '';
					$reload_grouped_bookings = intval( $_POST[ 'reload_grouped_bookings' ] ) === 1 ? true : false;
					if( $reload_grouped_bookings ) {
						$Bookings_List_Table = new Bookings_List_Table();
						$Bookings_List_Table->prepare_items( array( 'booking_group_id' => $booking_group_id ), true );
						$rows = $Bookings_List_Table->get_rows_or_placeholder();
					}
					
					$refunded[ 'grouped_booking_rows' ] = $rows;
				}

				wp_send_json( $refunded );

			} else {
				wp_send_json( array( 'status' => 'failed', 'error' => 'not_allowed' ) );
			}
		}
		add_action( 'wp_ajax_bookactiRefundBookingGroup', 'bookacti_controller_refund_booking_group' );
		add_action( 'wp_ajax_nopriv_bookactiRefundBookingGroup', 'bookacti_controller_refund_booking_group' );


		/**
		 * AJAX Controller - Change booking group state
		 * 
		 * @since 1.1.0
		 * @version 1.3.0
		 */
		function bookacti_controller_change_booking_group_state() {

			$booking_group_id	= intval( $_POST[ 'booking_id' ] );
			$sanitized_state	= sanitize_title_with_dashes( $_POST[ 'new_state' ] );
			$send_notifications	= $_POST[ 'send_notifications' ] ? 1 : 0;
			$new_state			= array_key_exists( $sanitized_state, bookacti_get_booking_state_labels() ) ? $sanitized_state : false;

			// Check nonce, capabilities and other params
			$is_nonce_valid			= check_ajax_referer( 'bookacti_change_booking_state', 'nonce', false );
			$is_allowed				= current_user_can( 'bookacti_edit_bookings' );		
			$state_can_be_changed	= bookacti_booking_group_state_can_be_changed_to( $booking_group_id, $new_state );
			
			if( $is_nonce_valid && $is_allowed && $state_can_be_changed && $new_state ) {

				$booking_group	= bookacti_get_booking_group_by_id( $booking_group_id );
				$was_active		= $booking_group->active ? 1 : 0;
				$active			= in_array( $new_state, bookacti_get_active_booking_states(), true ) ? 1 : 0;
				
				// If the booking group was inactive and become active, we need to check availability
				if( ! $was_active && $active ) {
					$quantity	= bookacti_get_booking_group_quantity( $booking_group_id );
					$validated	= bookacti_validate_booking_form( $booking_group->event_group_id, null, null, null, $quantity );
				} else {
					$validated['status'] = 'success';
				}

				if( $validated['status'] === 'success' ) {
					
					$booking_ids= bookacti_get_booking_group_bookings_ids( $booking_group_id );
					$updated	= bookacti_update_booking_group_state( $booking_group_id, $new_state, $active, true, true );
					
					if( $updated ) {

						$is_bookings_page = intval( $_POST[ 'is_bookings_page' ] ) === 1 ? true : false;
						do_action( 'bookacti_booking_group_state_changed', $booking_group_id, $new_state, array( 'is_admin' => $is_bookings_page, 'active' => $active, 'send_notifications' => $send_notifications ) );
						
						$actions_html	= bookacti_get_booking_group_actions_html( $booking_group_id, 'admin' );
						$formatted_state= bookacti_format_booking_state( $new_state, $is_bookings_page );
						$active_changed = $active === $was_active ? false : true;
						
						$rows = '';
						$reload_grouped_bookings = intval( $_POST[ 'reload_grouped_bookings' ] ) === 1 ? true : false;
						if( $reload_grouped_bookings ) {
							$Bookings_List_Table = new Bookings_List_Table();
							$Bookings_List_Table->prepare_items( array( 'booking_group_id' => $booking_group_id ), true );
							$rows = $Bookings_List_Table->get_rows_or_placeholder();
						}
						
						wp_send_json( array( 'status' => 'success', 'new_actions_html' => $actions_html, 'formatted_state' => $formatted_state, 'active_changed' => $active_changed, 'grouped_booking_rows' => $rows ) );
					} else {
						wp_send_json( array( 'status' => 'failed' ) );
					}
				} else {
					wp_send_json( array( 'status' => 'failed', 'error' => $validated['error'], 'message' => esc_html( $validated['message'] ) ) );
				}

			} else {
				wp_send_json( array( 'status' => 'failed', 'error' => 'not_allowed' ) );
			}
		}
		add_action( 'wp_ajax_bookactiChangeBookingGroupState', 'bookacti_controller_change_booking_group_state' );




// BOOKING LIST
	
	/**
	 * Change Customer name in bookings list
	 *  
	 * @param array $booking_item
	 * @param object $booking
	 * @param WP_User $user
	 * @return array
	 */
	function bookacti_change_customer_name_in_bookings_list( $booking_item, $booking, $user ) {
		
		if( is_numeric( $booking->user_id ) ) {
			if( isset( $user->first_name ) && $user->last_name ) {
				$customer = '<a  href="' . esc_url( get_admin_url() . 'user-edit.php?user_id=' . $booking->user_id ) . '" '
							.  ' target="_blank" >'
								. esc_html( $user->first_name . ' ' . $user->last_name )
						.   '</a>';
				$booking_item[ 'customer' ] = $customer;
			}
		}
		
		return $booking_item;
	}
	add_filter( 'bookacti_booking_list_booking_columns', 'bookacti_change_customer_name_in_bookings_list', 10, 3 );