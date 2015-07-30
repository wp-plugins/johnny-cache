/*globals window, document, $, jQuery, ajaxurl */

(function ($) {
	"use strict";

	var $instanceStore,
		$refreshInstance,
		$instanceSelector;

    function remove_group(el) {
        return function () {
            $( el ).closest('tr').fadeOut().remove();
        };
    }

    function remove_item(el) {
        return function () {
            $( el ).closest('p').fadeOut().remove();
        };
    }

	function handleChange( e ) {
		var $el = $( e.currentTarget ), val;

		val = $.trim( $el.val() );

		if ( val ) {
			$.ajax({
				type: 'post',
				url : ajaxurl,
				data : {
					action : 'jc-get-instance',
					nonce  : $el.data('nonce'),
					name   : val
				},
				cache : false,
				success : function ( data ) {
					if ( data ) {
						$instanceStore.html( data );
						$refreshInstance.show();
					}
				}
			});
		}
	}

    $(document).ready(function () {
        $instanceStore = $('#instance-store');
        $refreshInstance = $('#refresh-instance');
        $instanceSelector = $('#instance-selector');

        $refreshInstance.click(function () {
            $instanceSelector.trigger('change');
            return false;
        });

        $instanceSelector.bind( 'change', handleChange );

        $( document.body )
			.on('click', '.jc-flush-group', function (e) {
				var elem = $( e.currentTarget ), keys = [];

				elem.parents('td').next().find('p').each(function () {
					keys.push($(this).data('key'));
				});

				$.ajax({
					url : e.currentTarget.href,
					type : 'post',
					data : {
						keys: keys
					},
					success : remove_group( elem[0] )
				});
				return false;
			})
			.on('click', '.jc-remove-item', function (e) {
				$.ajax({
					type : 'post',
					url : e.currentTarget.href,
					success : remove_item( e.currentTarget )
				});
				return false;
			})
			.on( 'click', '.jc-view-item', function (e) {
				$.ajax({
					url : e.currentTarget.href,
					type : 'post',
					success : function (data) {
						$('#debug').html(data);
						window.location.hash = 'jc-wrapper';
					}
				});

				return false;
			} );
    });

}(jQuery));