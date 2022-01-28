<?php
include('config.php');
include_once(INSTALL_PATH . '/DBRecord.class.php' );
include_once(INSTALL_PATH . '/reclib.php' );
include_once(INSTALL_PATH . '/Settings.class.php' );


function codec_format( $strow )
{
	$needles = array(	'ts'   => 'mpeg',
						'mp4'  => 'mp4',
						'webm' => 'webm',
						'ogg'  => 'ogg',
					);
	$path_parts = pathinfo( $strow, PATHINFO_EXTENSION );

	if( array_key_exists( $path_parts, $needles ) ){
		return ' type="video/'.$needles[$path_parts].'"';
	}else
		return '';
}


$settings = Settings::factory();

if( isset( $_GET['ch'] ) ){
	include_once( INSTALL_PATH . '/recLog.inc.php' );
	include( INSTALL_PATH . '/realview_stop.php' );

	$channel = $_GET['ch'];
	if( $channel === '-' ){
		realview_stop();
		echo '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body onLoad="location.href = document.referrer;"></body></html>';

		exit( 1 );
	}
	if( isset( $_GET['mode'] ) ){
		// 別PCからチャンネル変更(recpt1ctl対応チューナーのみ)
		$real_view = trim( file_get_contents( REALVIEW_PID ) );
		$ctrl_cmd  = RECPT1_CTL.' --pid '.$real_view.' --channel '.$channel;
		if( isset( $_GET['sid'] ) )
			$ctrl_cmd .= ' --sid '.$_GET['sid'];
//		@exec( $ctrl_cmd.' >/dev/null 2>&1' );
		exit( 1 );
	}

	$tuner_mode  = TRUE;
	$stream_mode = TRUE;
	$sid         = isset( $_GET['sid'] ) ? $_GET['sid'] : 'hd';
	$type        = substr( $_GET['type'], 0, 2 );			// index.htmlのchannel_discから流用してるため
	$trans_op    = isset( $_GET['trans'] ) ? '&trans='.$_GET['trans'] : '';
	$filename    = $channel;
	$title       = $channel.':'.$sid.' '.htmlspecialchars( $_GET['name'],ENT_QUOTES);
	$sorce_url   = 'sendstream.php?ch='.$channel.'&sid='.$sid.'&type='.$type.$trans_op;
	$target_path = 'tuner.'.(isset( $_GET['trans'] ) ? 'mp4' : 'ts' );		// 手抜き
}else{
	if( ! isset( $_GET['reserve_id'] ) )
		jdialog('予約番号が指定されていません', 'recordedTable.php');
	$reserve_id = $_GET['reserve_id'];
	$tuner_mode = FALSE;

	try{
		$rrec = new DBRecord( RESERVE_TBL, 'id', $reserve_id );

		if( isset( $_GET['trans_id'] ) ){
			$trans_set = new DBRecord( TRANSCODE_TBL, 'id', $_GET['trans_id'] );
			if( strncmp( $trans_set->path, INSTALL_PATH, strlen(INSTALL_PATH) ) )
				jdialog( 'URLルートで始まるパスではないので視聴が出来ません<br>'.$trans_set->path, 'recordedTable.php' );
			$target_path = substr( $trans_set->path, strlen(INSTALL_PATH)+1 );
			if( $trans_set->status == 1 ){
				$stream_mode = TRUE;
				$trans_op    = '&trans_id='.$_GET['trans_id'];
			}else{
				$stream_mode = FALSE;
				$trans_op    = '';
			}
			$filename = htmlspecialchars( end( explode( '/', $trans_set->path ) ) );
		}else{
			if( isset( $_GET['trans'] ) ){
				$target_path = '';
				$stream_mode = TRUE;
				$trans_op    = '&trans='.$_GET['trans'];
			}else{
				$target_path = $settings->spool.'/'.$rrec->path;
				$stream_mode = $rrec->complete==0;
				$trans_op    = '';
			}
			$filename = htmlspecialchars( end( explode( '/', $rrec->path ) ) );
		}

		$start_time = toTimestamp($rrec->starttime);
		$end_time = toTimestamp($rrec->endtime );
		$duration = $end_time - $start_time + $settings->former_time;
		$dh       = $duration / 3600;
		$duration = $duration % 3600;
		$dm       = $duration / 60;
		$duration = $duration % 60;
		$ds       = $duration;
		
		if( $stream_mode )
			$sorce_url = 'sendstream.php?reserve_id='.$reserve_id.$trans_op;
		else{
			$paths     = explode( '/', $target_path );
			$sorce_url = '';
			foreach( $paths as $part ){
				if( $part !== '' )
					$sorce_url .= rawurlencode( $part ).'/';
			}
			$sorce_url = rtrim( $sorce_url, '/' );
		}
		$title    = htmlspecialchars(str_replace(array("\r\n","\r","\n"), '', $rrec->title),ENT_QUOTES);
		$abstract = htmlspecialchars(str_replace(array("\r\n","\r","\n"), '', $rrec->description),ENT_QUOTES);
	}
	catch(exception $e ) {
		exit( $e->getMessage() );
	}
}

// HTML5でのストリーミングはMSE・MPEG-DASHあたりで調査中
$html5 = FALSE;
if( $stream_mode || $tuner_mode )
	$html5 = FALSE;

if( $html5 ){
	$codec_type = codec_format( $target_path );
	echo '<html>';
	echo '<head>';
	echo '<meta charset="UTF-8">';
	echo '<title>'.$title.'</title>';
	echo '</head>';
	echo '<body>';
//	echo '<video src="'.$sorce_url.'" controls  autoplay>';
	echo '<video controls  autoplay>';
	echo '<source src="'.$sorce_url.'"'.$codec_type.'>';
	echo '<p>動画を再生するにはvideoタグをサポートしたブラウザが必要です。</p></video>';
	echo '</body>';
	echo '</html>';
}else{
	if( $NET_AREA==='G' && strpos( $settings->install_url, '://192.168.' )===FALSE && strpos( $settings->install_url, '://localhost/' )===FALSE ){
		$url_parts = parse_url( $settings->install_url );
		$scheme    = $url_parts['scheme'].'://';
		$view_url  = $url_parts['host'];
		if( isset( $url_parts['port'] ) )
			$port = $url_parts['port'];
	}else{
		$scheme = ( isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ) ? 'https://' : 'http://';
		if( isset( $_SERVER['HTTP_REFERER'] ) && strpos( $_SERVER['HTTP_REFERER'], '://' ) !== FALSE ){
			$url_parts = parse_url( $_SERVER['HTTP_REFERER'] );
			$view_url  = $url_parts['host'];
			if( isset( $url_parts['port'] ) )
				$port = $url_parts['port'];
		}else{
			if( isset( $_SERVER['HTTP_HOST'] ) && $_SERVER['HTTP_HOST']!=='' )
				$view_url = $_SERVER['HTTP_HOST'];
			else
				if( isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], '://' )!==FALSE ){
					$url_parts = parse_url( $_SERVER['REQUEST_URI'] );
					$view_url  = $url_parts['host'];
					if( isset( $url_parts['port'] ) )
						$port = $url_parts['port'];
				}else
					if( $NET_AREA==='G' && get_net_area( $_SERVER['SERVER_ADDR'] )!=='G' ){
						$name_stat = get_net_area( $_SERVER['SERVER_NAME'] );
						if( $name_stat==='T' || $name_stat==='G' ){
							$view_url = $_SERVER['SERVER_NAME'];
							$port     = $_SERVER['SERVER_PORT'];
						}else{
							// ここは適当 たぶんダメ
							if( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ){
								$view_url = $_SERVER['REMOTE_ADDR'];	// proxy
								$port     = $_SERVER['REMOTE_PORT'];
							}else{
								$view_url = $_SERVER['SERVER_ADDR'];	// NAT
								$port     = $_SERVER['SERVER_PORT'];
							}
						}
					}else{
						$view_url = $_SERVER['SERVER_ADDR'];
						$port     = $_SERVER['SERVER_PORT'];
					}
		}
	}
	if( STREAMURL_INC_PW && $AUTHORIZED && isset($_SERVER['PHP_AUTH_USER']) )
		$scheme .= $_SERVER['PHP_AUTH_USER'].':'.$_SERVER['PHP_AUTH_PW'].'@';
	if( strpos( $view_url, ':' )!==FALSE &&  strpos( $view_url, '[' )===FALSE )
		$view_url = '['.$view_url.']';
	if( isset($port) && $port !== '80' )
		$view_url .= ':'.$port;
	$part_path = explode( '/', $_SERVER['PHP_SELF'] );
	array_pop( $part_path );
	$base_path = implode( '/', $part_path ).'/';

	header('Expires: Thu, 01 Dec 1994 16:00:00 GMT');
	header('Last-Modified: '. gmdate('D, d M Y H:i:s'). ' GMT');
	header('Cache-Control: no-cache, must-revalidate');
	header('Cache-Control: post-check=0, pre-check=0', false);
	header('Pragma: no-cache');
	header('Content-type: video/x-ms-asf; charset="UTF-8"');
	header('Content-Disposition: inline; filename="'.$filename.'.asx"');

	$asf_buf  = '<ASX version = "3.0">';
	$asf_buf .= '<PARAM NAME = "Encoding" VALUE = "UTF-8" />';
	$asf_buf .= '<ENTRY>';
	$asf_buf .= '<TITLE>'.$title.'</TITLE>';
	$asf_buf .= '<REF HREF="'.$scheme.$view_url.$base_path.$sorce_url.'" />';

	if( !$tuner_mode ){
		$asf_buf .= '<ABSTRACT>'.$abstract.'</ABSTRACT>';
		$asf_buf .= '<DURATION VALUE="'.sprintf( '%02d:%02d:%02d',$dh, $dm, $ds ).'" />';
	}
	$asf_buf .= '</ENTRY>';
	$asf_buf .= '</ASX>';
	echo $asf_buf;
	flush();
	ob_flush();
}
?>
