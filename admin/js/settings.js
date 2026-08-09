/**
 * Keep the three-step setup focused on the amount field in use.
 */
(( $ ) => {
	const syncAdvanceFields = () => {
		const mode = $( "#cod_shield_advance_mode" ).val();
		$( "#cod_shield_advance_fixed" ).closest( "tr" ).toggle( "fixed" === mode );
		$( "#cod_shield_advance_percentage" )
			.closest( "tr" )
			.toggle( "percentage" === mode );
	};

	$( () => {
		syncAdvanceFields();
		$( "#cod_shield_advance_mode" ).on( "change", syncAdvanceFields );
	} );
})( jQuery );
