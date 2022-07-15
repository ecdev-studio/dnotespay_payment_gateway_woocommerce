jQuery(document).ready(function($){
	
	$('.dnotespay-click-to-copy').each(function(){
		$(this).after('<a href="#" class="dnotespay-click-to-copy-btn">Click to Copy</a>');
	});

	$(document).on('click', '.dnotespay-click-to-copy-btn', function(e){
		e.preventDefault();
		dnotespayCopyToClipboard( $(this).prev('.dnotespay-click-to-copy') );
		return false;
	});

	function dnotespayCopyToClipboard( $elem ) {
	    var $temp = $('<input />').appendTo('body');
	    $temp.val( $elem.text() ).select();
	    document.execCommand("copy");
	    $temp.remove();
	}

	var check_payment_status_timeout;
	$('#dnotespay-check-status').on('click', function(e){
		e.preventDefault();
		check_payment_status();
		return false;
	});
	$('#dnotespay-just-paid').on('click', function(e){
		e.preventDefault();
		check_payment_status( 'processing-payment', true );
		return false;
	});
	check_payment_status();
	function check_payment_status( new_status, redirect_typ ) {
		var $_this = $('#dnotespay-check-status'),
			new_status = ( typeof new_status != undefined ) ? new_status : null,
			redirect_typ = ( typeof redirect_typ != undefined ) ? redirect_typ : false;
		if( dnotespay && dnotespay.hasOwnProperty('ajaxurl') && dnotespay.hasOwnProperty('order_id') && !$_this.hasClass('processing') ) {
			$_this.addClass('processing');
			$.post(
				dnotespay.ajaxurl,
				{
					'action' : 'dnotespay_check_payment',
					'order_id' : dnotespay.order_id,
					'security' : dnotespay.nonce,
					'new_status' : new_status
				},
				function ( response ) {
					$_this.removeClass('processing');
					if ( redirect_typ && dnotespay && dnotespay.hasOwnProperty('redirect') ) {
						location.href = dnotespay.redirect;
						return false;
					}
					if ( response.hasOwnProperty('payment_status') ) {
						$('.dnotespay-wrapper').attr('data-payment-status', response.payment_status);
						if ( response.payment_status == 'success' && dnotespay && dnotespay.hasOwnProperty('redirect') ) {							
							location.href = dnotespay.redirect;
						} else {
							clearTimeout(check_payment_status_timeout);
							check_payment_status_timeout = setTimeout(check_payment_status,30000);
						}
					}
					if ( response.hasOwnProperty('time_left') ) {
						var time_left = parseInt(response.time_left);
						if ( time_left > 0 ) {
							cur_time_sec = time_left;
							n = 1;
							clearTimeout(decrease_second_timeout);
							decrease_second_timeout = decrease_second();
						} else {
							clearTimeout(check_payment_status_timeout);
							location.href = location.href;
						}
					}
				}
			);
		}
	}

	// time counter
	var seconds_per_day = 86400,
		seconds_per_hour = 3600,
		seconds_per_minute = 60,
		minutes_per_hour = 60,
		hours_per_day = 24,
		n = 1,
		$time_container = $('#dnotespay-time-left'),
		cur_time = $time_container.text(),
		cur_time_sec = convert_time_to_seconds( cur_time ),
		decrease_second_timeout;

	function convert_time_to_seconds( time ) {
		var time = time.split('day'),
			day = false;
		if ( time.length == 1) {
			time = time[0];
		} else {
			day = time[0];
			time = time[1];
		}

		var cur_time_arr = time.split(':'),
			k = 1,
			result = 0;
		for ( var i = cur_time_arr.length - 1; i >= 0; i-- ) {
			if ( day && i == 0 ) {
				cur_time_arr[i] = parseInt(cur_time_arr[i].replace('s', '')) + parseInt(day) * hours_per_day;
			}
			result += parseInt(cur_time_arr[i]) * k;
			k = k * 60;
		}
		return result;
	}	

	function convert_seconds_to_time( seconds ) {
		if ( seconds <= 0 ) return 0;

		var time = '',
			time_arr = new Array(),
			days = parseInt( seconds / seconds_per_day ); // days;

        days = days ? ( days + ( days > 1 ? ' days ' : ' day ' ) ) : '';

		time_arr.push( parseInt( seconds / seconds_per_hour ) % hours_per_day ); // hours
		time_arr.push( parseInt( seconds / seconds_per_minute ) % minutes_per_hour ); // minutes
		time_arr.push( seconds % seconds_per_minute ); // seconds
		for ( var i = 0; i < time_arr.length; i++ ) {
			if ( !( time_arr[i] == 0 && days == '' && ( i == 0 || ( i == 1 && time_arr[0] == 0 ) ) ) ) {
				if ( time_arr[i] < 10 ) time_arr[i] = '0' + time_arr[i];
				time += time_arr[i];
				if ( i+1 != time_arr.length ) {
					time += ':';
				}
			}
		}
		return days + time;
	}
		
	decrease_second();
	function decrease_second(){
		var seconds = cur_time_sec - n,
			time = convert_seconds_to_time( seconds );

		$time_container.text(time);

		if ( seconds > 0 ) {
			decrease_second_timeout = setTimeout( decrease_second, 1000);
			n++;
		} else {
			check_payment_status();
		}
	}
});
