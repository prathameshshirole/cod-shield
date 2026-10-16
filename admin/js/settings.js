/**
 * Keep the three-step setup focused on the amount field in use.
 */
(( $ ) => {
	const syncAdvanceFields = () => {
		const mode = $( "#cod_shield_advance_mode" ).val();
		$( ".cod-shield-fixed-field" ).toggle( "fixed" === mode );
		$( ".cod-shield-percentage-field" ).toggle( "percentage" === mode );
	};

	$( () => {
		syncAdvanceFields();
		$( "#cod_shield_advance_mode" ).on( "change", syncAdvanceFields );
	} );
})( jQuery );
