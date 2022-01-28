<?php
$script_path = dirname( __FILE__ );
chdir( $script_path );
include( $script_path . '/config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/recLog.inc.php' );

$settings = Settings::factory();

set_time_limit(0);
if( isset( $_GET['reserve_id'] ) ){
	$reserve_id = $_GET['reserve_id'];
	try{
		$rrec = new DBRecord( RESERVE_TBL, 'id', $reserve_id );
		$duration = toTimestamp($rrec->endtime ) - toTimestamp($rrec->starttime);
		$temp_ts  = INSTALL_PATH.$settings->spool.'/'.$rrec->path;
	}catch(exception $e ){
		reclog( 'download_file: 失敗 '.$e->getMessage(), EPGREC_WARN );
		exit;
	}
	$start_time = (int)$_GET['start'];
	$end_time   = (int)$_GET['end'];
	if( $end_time<=$start_time || $duration<$end_time ){
		reclog( 'download_file: 時間指定が不正です。', EPGREC_WARN );
		exit;
	}
	if( file_exists( $temp_ts ) ){
		$file_size = filesize( $temp_ts );
		if( $file_size ){
			$fp = @fopen( $temp_ts, 'r' );
			if( $fp !== FALSE ) {
				$send_cnt = $all_cnt = (int)( $file_size / 188 );
				if( $start_time ){
					$seek_cnt  = (int)( ( $all_cnt * $start_time ) / ( $duration + $settings->former_time + $settings->extra_time ) );
					$send_cnt -= $seek_cnt;
					@fseek( $fp, $seek_cnt * 188 );
				}
				if( $duration > $end_time )
					$send_cnt -= (int)( ( $all_cnt * ( $duration - $end_time ) ) / ( $duration + $settings->former_time + $settings->extra_time ) );

				$html_name = htmlspecialchars( end( explode( '/', $temp_ts ) ) );
				while( ob_get_level() > 0 )
					ob_end_clean();
//				header('Content-Description: File Transfer');
				header('Expires: 0');
				header('Accept-Ranges: bytes');
				header('Cache-Control: no-cache, must-revalidate');
//				header('Content-Type: application/octet-stream');
				header('Content-Type: application/force-download; charset=binary; name="'.$html_name.'"');
				header('Content-Disposition: attachment; filename="'.$html_name.'"');
//				header('Pragma: public');
				header('Content-Length: ' . $send_cnt*188);
				do {
					if( feof( $fp ) )
						break;
					if( $send_cnt > 64 )
						$send_size = 64*188;
					else
						$send_size = $send_cnt*188;
					echo fread( $fp, $send_size );		// 適当なのでこれで良し
					$send_cnt -= 64;
				}while( connection_aborted()==0 && $send_cnt>0 );
				fclose( $fp );
			}
		}
	}
}else
if( isset( $_GET['trans_id'] ) ){
	$reserve_id = $_GET['trans_id'];
	try{
		$trans_set = new DBRecord( TRANSCODE_TBL, 'id', $_GET['trans_id'] );
		$temp_ts   = $trans_set->path;
	}catch(exception $e ){
		reclog( 'download_file: 失敗 '.$e->getMessage(), EPGREC_WARN );
		exit;
	}
	if( file_exists( $temp_ts ) ){
		$file_size = filesize( $temp_ts );
		if( $file_size ){
			$fp = @fopen( $temp_ts, 'r' );
			if( $fp !== FALSE ) {
				$html_name = htmlspecialchars( end( explode( '/', $temp_ts ) ) );
				while( ob_get_level() > 0 )
					ob_end_clean();
//				header('Content-Description: File Transfer');
				header('Expires: 0');
				header('Accept-Ranges: bytes');
				header('Cache-Control: no-cache, must-revalidate');
//				header('Content-Type: application/octet-stream');
				header('Content-Type: application/force-download; charset=binary; name="'.$html_name.'"');
				header('Content-Disposition: attachment; filename="'.$html_name.'"');
//				header('Pragma: public');
				header('Content-Length: '.$file_size);
				do {
					if( feof( $fp ) )
						break;
					if( $file_size > 64 )
						$send_size = 64*188;
					else
						$send_size = $file_size;
					echo fread( $fp, $send_size );
					$file_size -= 64*188;
				}while( connection_aborted()==0 && $file_size>0 );
				fclose( $fp );
			}
		}
	}
}
exit;
?>
