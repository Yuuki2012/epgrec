<?php
  $script_path = dirname( __FILE__ );
  chdir( $script_path );
  include_once( $script_path . '/config.php');
  include_once( INSTALL_PATH . '/DBRecord.class.php' );
  include_once( INSTALL_PATH . '/Settings.class.php' );
	include_once( INSTALL_PATH . '/reclib.php' );
	include_once( INSTALL_PATH . '/recLog.inc.php' );

function searchProces( $pid )
{
// posix_kill( $pid, 0 )でのプロセス判定で不具合が発生
//	posix_kill( $pid, 0 );
//	return = posix_get_last_error();
	$ps_output = shell_exec( PS_CMD );
	$rarr      = explode( "\n", $ps_output );
	for( $cc=0; $cc<count($rarr); $cc++ ){
		if( strpos( $rarr[$cc], (string)$pid ) !== FALSE ){
			$ps = ps_tok( $rarr[$cc] );
			if( (int)$ps->pid == $pid )
				return 0;
		}
	}
	return ESRCH;
}

	$settings = Settings::factory();

	$channel = $_GET['ch'];
	if( isset( $_GET['sid'] ) )
		$sid = $_GET['sid'];
	$GR_max = (int)$settings->gr_tuners;
	$ST_max = (int)$settings->bs_tuners;
	$EX_max = EXTRA_TUNERS;
	for( $sem_cnt=0; $sem_cnt<$GR_max; $sem_cnt++ ){
		$rv_smph          = $sem_cnt + SEM_GR_START;
		$sem_id[$rv_smph] = sem_get_surely( $rv_smph );
		if( $sem_id[$rv_smph] === FALSE )
			exit;
	}
	for( $sem_cnt=0; $sem_cnt<$ST_max; $sem_cnt++ ){
		$rv_smph          = $sem_cnt + SEM_ST_START;
		$sem_id[$rv_smph] = sem_get_surely( $rv_smph );
		if( $sem_id[$rv_smph] === FALSE )
			exit;
	}
	for( $sem_cnt=0; $sem_cnt<$EX_max; $sem_cnt++ ){
		$rv_smph          = $sem_cnt + SEM_EX_START;
		$sem_id[$rv_smph] = sem_get_surely( $rv_smph );
		if( $sem_id[$rv_smph] === FALSE )
			exit;
	}
	if( $channel!=='-' && isset( $_GET['type'] ) ){
		$type = substr( $_GET['type'], 0, 2 );			// index.htmlのchannel_discから流用してるため
		switch( $type ){
			case 'GR':
				$sql_type = 'type="GR"';
				$smf_key  = SEM_GR_START;
				$tuners   = $GR_max;
				break;
			case 'EX':
				$sql_type = 'type="EX"';
				$smf_key  = SEM_EX_START;
				$tuners   = $EX_max;
				break;
			default:	//BS/CS
				$sql_type = '(type="BS" OR type="CS")';
				$smf_key  = SEM_ST_START;
				$tuners   = $ST_max;
				break;
		}
	}else
		$type = '';
	$shm_id = shmop_open_surely();
	$rv_sem = sem_get_surely( SEM_REALVIEW );
	if( $rv_sem === FALSE )
		exit;
//	@unlink( '/tmp/*.asx' );		// 所有権がapacheにない(ブラウザが所持)
	while(1){
		if( sem_acquire( $rv_sem ) === TRUE ){
			// リアルタイム視聴中確認
			$rv_smph = shmop_read_surely( $shm_id, SEM_REALVIEW );
			if( $rv_smph > 0 ){
				// 使用中チューナ仕様取得
				if( $rv_smph < SEM_ST_START ){
					// GR
					$now_tuner = $rv_smph - SEM_GR_START;
					$now_type  = 'GR';
				}else
				if( $rv_smph < SEM_EX_START ){
					// satelite
					$now_tuner = $rv_smph - SEM_ST_START;
					$now_type  = 'BS';
				}else{
					// EX
					$now_tuner = $rv_smph - SEM_EX_START;
					$now_type  = 'EX';
				}
				$wave_disc = $type==='CS' ? 'BS' : $type;
				if( file_exists( REALVIEW_PID ) ){
					// 録画コマンド終了 or リアルタイム視聴終了
					$real_view = (int)trim( file_get_contents( REALVIEW_PID ) );
					unlink( REALVIEW_PID );
					while( searchProces( $real_view ) === 0 ){
						posix_kill( $real_view, 9 );		// 録画コマンド停止
						usleep( 100*1000 );
					}
				}
				while( sem_acquire( $sem_id[$rv_smph] ) === FALSE )
					usleep( 100 );
				shmop_write_surely( $shm_id, $rv_smph, 0 );
				while( sem_release( $sem_id[$rv_smph] ) === FALSE )
					usleep( 100 );
//				if( $now_type === $type )
//					sleep( (int)$settings->rec_switch_time );
				shmop_write_surely( $shm_id, SEM_REALVIEW, 0 );
			}
			break;
		}
	}
	if( $channel === '-' ){
		while( sem_release( $rv_sem ) === FALSE )
			usleep( 100 );
		shmop_close( $shm_id );
		echo '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body onLoad="location.href = document.referrer;"></body></html>';

		exit( 1 );
	}

	$lp = 0;
	$res_obj = new DBRecord( RESERVE_TBL );
	while(1){
		$sql_cmd    = 'complete=0 AND '.$sql_type.' AND endtime>now() AND starttime<addtime( now(), "00:03:00" )';
		$revs       = $res_obj->fetch_array( null, null, $sql_cmd );
		$off_tuners = count( $revs );
		if( $off_tuners < $tuners ){
			//空チューナー降順探索
			for( $slc_tuner=$tuners-1; $slc_tuner>=0; $slc_tuner-- ){
				for( $cnt=0; $cnt<$off_tuners; $cnt++ ){
					if( $revs[$cnt]['tuner'] == $slc_tuner )
						continue 2;
				}
				$shm_name = $smf_key + $slc_tuner;
				if( sem_acquire( $sem_id[$shm_name] ) === TRUE ){
					$smph = shmop_read_surely( $shm_id, $shm_name );
					if( $smph == 0 ){
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
						$asf_buf .= '<REF HREF="'.$scheme.$view_url.$base_path.'/sendstream.php?ch='.$channel.'&sid='.$sid.'&type='.$type.'&shm='.$shm_name.$trans.'" />';
						$asf_buf .= '</ENTRY>';
						$asf_buf .= '</ASX>';
						if( !isset( $_GET['mode'] ) ){
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

/* 入れると動かない 多ユーザー化するときの障害にもなりそう
							// sendstream.phpの起動確認してセマフォ開放
							$lp = 0;
							do{
								$ps_output = shell_exec( PS_CMD );
								$rarr      = explode( "\n", $ps_output );
								foreach( $rarr as $cc ){
									if( strpos( $cc, 'sendstream.php' ) !== FALSE )
										break 2;
								}
								usleep( 100 );
							}while( ++$lp < 100000 );	// 10sec
*/
						}else{
							// 別PCからチャンネル変更をする試み（失敗）
							$asf_file_name = '/tmp/'.$channel.'.asx';
							file_put_contents ( $asf_file_name, $asf_buf );
							exec( 'sudo -u user-name vlc '.$asf_file_name );		// --playlist-enqueue ここがうまくいかない
						}
						while( sem_release( $sem_id[$shm_name] ) === FALSE )
							usleep( 100 );
						while( sem_release( $rv_sem ) === FALSE )
							usleep( 100 );
						shmop_close( $shm_id );
						exit( 1 );
					}else
						//占有失敗
						while( sem_release( $sem_id[$shm_name] ) === FALSE )
							usleep( 100 );
				}
			}
		}
		if( $lp++ > 60 ){
			while( sem_release( $rv_sem ) === FALSE )
				usleep( 100 );
			shmop_close( $shm_id );
			echo '別処理でチューナーを使用中です。';
			exit( 1 );
		}
		sleep(1);
	}
?> 
