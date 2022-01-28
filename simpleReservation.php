<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Reservation.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );

if( ! isset( $_GET['program_id'] ) ) exit('Error: 番組が指定されていません' );
$program_id = $_GET['program_id'];

$settings = Settings::factory();
$mode     = (int)$settings->autorec_mode;

try {
	$rval = Reservation::simple( $program_id , 0, $mode, ((int)$settings->force_cont_rec===0 ? 1 : 0) );
}
catch( Exception $e ) {
	exit( 'Error:'. $e->getMessage() );
}
if( isset( $RECORD_MODE[$mode]['tsuffix'] ) ){
	// 手動予約のトラコン設定
	list( , , $rec_id, ) = explode( ':', $rval );
	$tex_obj = new DBRecord( TRANSEXPAND_TBL );
	$tex_obj->key_id  = 0;
	$tex_obj->type_no = $rec_id;
	$tex_obj->mode    = $mode;
	$tex_obj->ts_del  = 0;
	$tex_obj->dir     = '';
	$tex_obj->update();
}
exit( $rval );
?>
