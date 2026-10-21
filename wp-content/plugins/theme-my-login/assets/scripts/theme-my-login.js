( function ( $ ) {

	$( '.tml' ).on( 'submit', 'form[data-ajax="1"]', function( e ) {
		var form = $( this ),
			input = form.find( ':input' ),
			submit = form.find( ':submit' ),
			container = $( e.delegateTarget ),
			notices = container.find( '.tml-alerts' );

		function showErrors( errors ) {
			if ( ! errors ) {
				errors = $( '<ul class="tml-errors"><li class="tml-error"></li></ul>' );
				errors.find( 'li' ).text( themeMyLogin.ajaxErrorMessage );
			}
			notices.hide().html( errors ).fadeIn();
		}

		e.preventDefault();

		notices.empty();

		input.prop( 'readonly', true );
		submit.prop( 'disabled', true );

		$.ajax( {
			data: form.serialize() + '&ajax=1',
			method: form.attr( 'method' ) || 'get',
			url: form.attr( 'action' )
		} )
		.always( function() {
			input.prop( 'readonly', false );
			submit.prop( 'disabled', false );
		} )
		.done( function( response ) {
			if ( response && response.success ) {
				if ( response.data.refresh ) {
					location.reload( true );
				} else if ( response.data.redirect ) {
					location.href = response.data.redirect;
				} else if ( response.data.notice ) {
					notices.hide().html( response.data.notice ).fadeIn();
				}
			} else {
				showErrors( response && response.data && response.data.errors );
			}
		} )
		.fail( function( jqXHR ) {
			var json = jqXHR.responseJSON;

			showErrors( json && json.data && json.data.errors );
		} );
	} );
} )( jQuery );

( function ( $ ) {

	$( initFocus );

	function initFocus() {
		var userLogin, key;

		if ( ! themeMyLogin.action || ! themeMyLogin.autofocus ) {
			return;
		}

		userLogin = $( '#user_login' );

		switch ( themeMyLogin.action ) {
			case 'activate' :
				key = $( '#key' );
				if ( key.length ) {
					key.focus();
				}
				break;

			case 'lostpassword' :
			case 'retrievepassword' :
			case 'register' :
				userLogin.focus();
				break;

			case 'resetpass' :
			case 'rp' :
				$( '#pass1' ).focus();
				break;

			case 'login' :
				if ( -1 != themeMyLogin.errors.indexOf( 'invalid_username' ) ) {
					userLogin.val( '' );
				}

				if ( userLogin.val() ) {
					$( '#user_pass' ).focus();
				} else {
					userLogin.focus();
				}
				break;
		}
	}
} )( jQuery );

( function( $ ) {
	function checkPasswordStrength() {
		var pass1 = $( '#pass1' ).val(),
			result = $( '#pass-strength-result' ),
			strength;

		result.removeClass('short bad good strong empty');
		if ( ! pass1 || '' === pass1.trim() ) {
			result.addClass('empty').html( '&nbsp;' );
			return;
		}

		strength = wp.passwordStrength.meter( pass1, wp.passwordStrength.userInputDisallowedList(), pass1 );

		switch ( strength ) {
			case -1:
				result.addClass( 'bad' ).html( pwsL10n.unknown );
				break;
			case 2:
				result.addClass( 'bad' ).html( pwsL10n.bad );
				break;
			case 3:
				result.addClass( 'good' ).html( pwsL10n.good );
				break;
			case 4:
				result.addClass( 'strong' ).html( pwsL10n.strong );
				break;
			case 5:
				result.addClass( 'short' ).html( pwsL10n.mismatch );
				break;
			default:
				result.addClass( 'short' ).html( pwsL10n['short'] );
		}
	}

	$( document ).ready( function() {
		if ( 'undefined' === typeof wp || ! wp.passwordStrength ) {
			return;
		}

		$( '#pass1' ).val( '' ).on( 'keyup paste', checkPasswordStrength );
	} );
} )( jQuery );

( function ( $ ) {

	var $pass2 = $( '#pass2' );

	if ( ! $pass2.length ) {
		return;
	}

	$pass2.closest( '.tml-field-wrap' ).hide();

	$( '#pass1' ).on( 'input', function() {
		$pass2.val( $( this ).val() );
	} );

} )( jQuery );

( function ( $ ) {

	$( '.tml' ).on( 'click', '.tml-toggle-pwd', function() {
		var button = $( this ),
			input = button.siblings( 'input' ),
			isHidden = 'password' === input.attr( 'type' );

		input.attr( 'type', isHidden ? 'text' : 'password' );

		button
			.attr( 'aria-label', isHidden ? themeMyLogin.hidePasswordLabel : themeMyLogin.showPasswordLabel )
			.find( '.dashicons' )
				.toggleClass( 'dashicons-hidden', isHidden )
				.toggleClass( 'dashicons-visibility', ! isHidden );
	} );
} )( jQuery );
