<?php
  $script_path = dirname( __FILE__ );
  chdir( $script_path );
  include_once( $script_path . '/config.php');
  include_once( INSTALL_PATH . '/DBRecord.class.php' );
  include_once( INSTALL_PATH . '/Settings.class.php' );
	include_once( INSTALL_PATH . '/reclib.php' );
	include_once( INSTALL_PATH . '/recLog.inc.php' );
	include( INSTALL_PATH . '/realview_stop.php' );

	$settings = Settings::factory();

	$channel = $_GET['ch'];
	if( $channel === '-' ){
		realview_stop();
		echo '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body onLoad="location.href = document.referrer;"></body></html>';

		exit( 1 );
	}

	$sid     = isset( $_GET['sid'] ) ? $_GET['sid'] : 'hd';
	$type    = substr( $_GET['type'], 0, 2 );			// index.htmlのchannel_discから流用してるため
	if( !isset( $_GET['mode'] ) ){
		// リアルタイム視聴コマンド常駐へ
		$asf_buf  = '<ASX version = "3.0">';
		$asf_buf .= '<PARAM NAME = "Encoding" VALUE = "UTF-8" />';
		$asf_buf .= '<ENTRY>';
		$asf_buf .= '<TITLE>'.$channel.':'.$sid.' '.$_GET['name'].'</TITLE>';
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
		$base_path = implode( '/', $part_path );
		$trans    = isset( $_GET['trans'] ) ? '&trans='.$_GET['trans'] : '';
		$asf_buf .= '<REF HREF="'.$scheme.$view_url.$base_path.'/sendstream.php?ch='.$channel.'&sid='.$sid.'&type='.$type.$trans.'" />';
		$asf_buf .= '</ENTRY>';
		$asf_buf .= '</ASX>';
		header('Expires: Thu, 01 Dec 1994 16:00:00 GMT');
		header('Last-Modified: '. gmdate('D, d M Y H:i:s'). ' GMT');
		header('Cache-Control: no-cache, must-revalidate');
		header('Cache-Control: post-check=0, pre-check=0', false);
		header('Pragma: no-cache');
		header('Content-type: video/x-ms-asf; charset="UTF-8"');
		header('Content-Disposition: inline; filename="'.$channel.'.asx"');
		echo $asf_buf;
		flush();
		ob_flush();
	}else{
		// 別PCからチャンネル変更
		$real_view = (int)trim( file_get_contents( REALVIEW_PID ) );
//		$asf_file_name = '/tmp/'.$channel.'.asx';
//		file_put_contents ( $asf_file_name, $asf_buf );
//		exec( 'sudo -u user-name vlc '.$asf_file_name );		// --playlist-enqueue ここがうまくいかない
	}
	exit( 1 );
?> 
