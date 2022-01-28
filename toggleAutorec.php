<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );

if( isset($_GET['program_id']) ){
	$program_id = $_GET['program_id'];
	$bef_auto   = $_GET['bef_auto'];
	if( $program_id ){
		try {
			$rec = new DBRecord(PROGRAM_TBL, 'id', $program_id );
			$tgl_autorec = $rec->autorec ? 0:1;
			if( $bef_auto == $rec->autorec ){
				$rec->autorec = $tgl_autorec;
				$rec->update();
			}
		}
		catch( Exception $e ) {
			// 無視
		}
	}
}
exit();
?>
