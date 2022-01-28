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
				$ctl_chng  = FALSE;
				if( $channel === '-' )
					$tuner_stop = TRUE;
				else{
					if( $now_type === 'EX' )
						$cmd_num = $EX_TUNERS_CHARA[$now_tuner]['reccmd'];
					else
						$cmd_num = $now_tuner<TUNER_UNIT1 ? PT1_CMD_NUM : $OTHER_TUNERS_CHARA[$now_type][$now_tuner-TUNER_UNIT1]['reccmd'];
					if( $wave_disc === $now_type )
						if( $rec_cmds[$cmd_num]['httpS'] )
							$tuner_stop = FALSE;
						else
							if( $rec_cmds[$cmd_num]['cntrl'] ){
								$tuner_stop = FALSE;
								$ctl_chng   = TRUE;
							}else
								$tuner_stop = TRUE;
					else
						$tuner_stop = TRUE;
				}
				$real_view = (int)trim( file_get_contents( REALVIEW_PID ) );
				// 録画コマンド常駐確認
				$errno = searchProces( $real_view );
				if( $errno==ESRCH || $tuner_stop ){
					unlink( REALVIEW_PID );
					if( $errno != ESRCH ){
						// 非httpサーバ化対応録画コマンド終了 or リアルタイム視聴終了
						if( posix_kill( $real_view, 9 ) ){		// 録画コマンド停止 cvlcは自動終了
							do{
								usleep( 100*1000 );
							}while( searchProces( $real_view ) != ESRCH );
						}else{
							$errno = posix_get_last_error();
							reclog( 'watch.php::('.$errno.')'.posix_strerror( $errno ), EPGREC_WARN );
							if( $errno != ESRCH ){
								// 録画コマンド非常駐以外
								while( sem_acquire( $sem_id[$rv_smph] ) === FALSE )
									usleep( 100 );
								shmop_write_surely( $shm_id, $rv_smph, 0 );		// チューナー開放
								while( sem_release( $sem_id[$rv_smph] ) === FALSE )
									usleep( 100 );
								shmop_write_surely( $shm_id, SEM_REALVIEW, 0 );		// リアルタイム視聴tunerNo Clear
								while( sem_release( $rv_sem ) === FALSE )
									usleep( 100 );
								shmop_close( $shm_id );
								exit( 0 );
							}
						}
					}
					if( $now_type === $type )
						sleep( (int)$settings->rec_switch_time );
					unset( $now_type );
					while( sem_acquire( $sem_id[$rv_smph] ) === FALSE )
						usleep( 100 );
					shmop_write_surely( $shm_id, $rv_smph, 0 );		// チューナー開放
					while( sem_release( $sem_id[$rv_smph] ) === FALSE )
						usleep( 100 );
					shmop_write_surely( $shm_id, SEM_REALVIEW, 0 );		// リアルタイム視聴tunerNo Clear
				}else{
					if( $now_type === $wave_disc ){
						// チューナ継続使用
						$slc_tuner = $now_tuner;
						// recpt1ctlによるチャンネル変更
						if( $ctl_chng ){
							$real_view = (int)trim( file_get_contents( REALVIEW_PID ) );
							exec( RECPT1_CTL.' --pid '.$real_view.' --channel '.$channel.' --sid '.$sid.' >/dev/null' );
						}
						goto OUTPUT;
					}
				}
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
						// recpt1常駐判定
						if( isset( $now_type ) ){
							if( $slc_tuner>=TUNER_UNIT1 && $now_type!=='EX' ){
								// チューナー渡りのためリアルタイム視聴一時終了
								$real_view = (int)trim( file_get_contents( REALVIEW_PID ) );
								if( posix_kill( $real_view, 9 ) ){		// 録画コマンド停止 cvlcは自動終了
									do{
										usleep( 100*1000 );
									}while( searchProces( $real_view ) != ESRCH );
								}else{
									$errno = posix_get_last_error();
/*									echo $errno.': '.posix_strerror( $errno )."\n";
									while( sem_release( $rv_sem ) === FALSE )
										usleep( 100 );
									shmop_close( $shm_id );
									exit( 0 );
*/
									unlink( REALVIEW_PID );
									if( $errno != ESRCH ){
										// 録画コマンド非常駐以外
										while( sem_acquire( $sem_id[$rv_smph] ) === FALSE )
											usleep( 100 );
										shmop_write_surely( $shm_id, $rv_smph, 0 );		// チューナー開放
										while( sem_release( $sem_id[$rv_smph] ) === FALSE )
											usleep( 100 );
										shmop_write_surely( $shm_id, SEM_REALVIEW, 0 );		// リアルタイム視聴tunerNo Clear
										while( sem_release( $rv_sem ) === FALSE )
											usleep( 100 );
										shmop_close( $shm_id );
										reclog( 'watch.php::('.$errno.')'.posix_strerror( $errno ), EPGREC_WARN );
										exit( 0 );
									}
								}
								unset( $now_type );
							}
							while( sem_acquire( $sem_id[$rv_smph] ) === FALSE )
								usleep( 100 );
							shmop_write_surely( $shm_id, $rv_smph, 0 );		// チューナー開放
							while( sem_release( $sem_id[$rv_smph] ) === FAlSE )
								usleep( 100 );
							shmop_write_surely( $shm_id, SEM_REALVIEW, 0 );		// リアルタイム視聴tunerNo set
						}
						if( !isset( $now_type ) ){
							// リアルタイム視聴コマンド常駐
							$cmdline = 'CHANNEL='.$channel.' SID='.$sid." DURATION='-' TYPE=".$type.' TUNER_UNIT='.TUNER_UNIT1.' TUNER='.$slc_tuner." MODE=1 OUTPUT='-' ".DO_RECORD.' >/dev/null 2>&1';
							while(1){
								system( $cmdline );
								$real_cmd  = trim( file_get_contents( REALVIEW_PID.'_cmd' ) );
								$ps_output = shell_exec( PS_CMD );
								$rarr      = explode( "\n", $ps_output );
								for( $cc=0; $cc<count($rarr); $cc++ ){
									if( strpos( $rarr[$cc], $real_cmd ) !== FALSE ){
										$ps        = ps_tok( $rarr[$cc] );
										$real_view = (int)$ps->pid;
										// 常駐確認(ここでも問題が出たらsearchProces()に変更)
										if( posix_kill( $real_view, 0 ) ){
											// 常駐成功
											unlink( REALVIEW_PID.'_cmd' );
											$handle = fopen( REALVIEW_PID, 'w' );
											fwrite( $handle, (string)$real_view );
											fclose( $handle );
											break 2;
										}else{
											$errno = posix_get_last_error();
											if( $errno == ESRCH )
												continue 2;		// retry
											else{
												reclog( 'watch.php::('.$errno.')'.posix_strerror( $errno ), EPGREC_WARN );
//												unlink( REALVIEW_PID.'_cmd' );
												while( sem_release( $sem_id[$shm_name] ) === FALSE )
													usleep( 100 );
												while( sem_release( $rv_sem ) === FALSE )
													usleep( 100 );
												shmop_close( $shm_id );
												echo '録画コマンドの常駐に失敗しました。';
												exit( 0 );
											}
										}
									}
								}
							}
						}
						shmop_write_surely( $shm_id, $shm_name, 2 );		// リアルタイム視聴指示
						shmop_write_surely( $shm_id, SEM_REALVIEW, $shm_name );		// リアルタイム視聴tunerNo set
						while( sem_release( $sem_id[$shm_name] ) === FALSE )
							usleep( 100 );
						break 2;
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
OUTPUT:
	while( sem_release( $rv_sem ) === FALSE )
		usleep( 100 );
	shmop_close( $shm_id );

$asf_buf  = "<ASX version = \"3.0\">";
$asf_buf .= "<PARAM NAME = \"Encoding\" VALUE = \"UTF-8\" />";
$asf_buf .= "<ENTRY>";
$asf_buf .= "<TITLE>".$channel.":".$sid.' '.$_GET['name']."</TITLE>";
$now_type = $type==='CS' ? 'BS' : $type;
if( $now_type === 'EX' )
	$cmd_num = $EX_TUNERS_CHARA[$slc_tuner]['reccmd'];
else
	$cmd_num = $slc_tuner<TUNER_UNIT1 ? PT1_CMD_NUM : $OTHER_TUNERS_CHARA[$now_type][$slc_tuner-TUNER_UNIT1]['reccmd'];

if( $NET_AREA==='G' && strpos( $settings->install_url, '://192.168.' )===FALSE && strpos( $settings->install_url, '://localhost/' )===FALSE ){
	$view_url  = 'http://';
	$host_name = parse_url( $settings->install_url, PHP_URL_HOST );
}else{
	$view_url = ( isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ) ? 'https://' : 'http://';
	if( isset( $_SERVER['HTTP_REFERER'] ) && strpos( $_SERVER['HTTP_REFERER'], '://' ) !== FALSE ){
		$host_name = parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_HOST );
	}else{
		if( isset( $_SERVER['HTTP_HOST'] ) ){
			$host_part = explode( ':', $_SERVER['HTTP_HOST'] );
			$host_name = $host_part[0];
		}else
			if( isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], '://' )!==FALSE )
				$host_name = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_HOST );
			else
				if( $NET_AREA==='G' && get_net_area( $_SERVER['SERVER_ADDR'] )!=='G' ){
					$name_stat = get_net_area( $_SERVER['SERVER_NAME'] );
					if( $name_stat==='T' || $name_stat==='G' )
						$host_name = $_SERVER['SERVER_NAME'];
					else{
						// ここは適当 たぶんダメ
						if( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
							$host_name = $_SERVER['REMOTE_ADDR'];	// proxy
						else
							$host_name = $_SERVER['SERVER_ADDR'];	// NAT
					}
				}else
					$host_name = $_SERVER['SERVER_ADDR'];
	}
}
if( STREAMURL_INC_PW && $AUTHORIZED && isset($_SERVER['PHP_AUTH_USER']) )
	$view_url .= $_SERVER['PHP_AUTH_USER'].':'.$_SERVER['PHP_AUTH_PW'].'@';
$host_name .= ':'.REALVIEW_HTTP_PORT;
$asf_buf .= '<REF HREF="'.$view_url.$host_name.( $rec_cmds[$cmd_num]['httpS'] ? '/'.$channel.'/'.$sid.'" />' : '/" />' );
$asf_buf .= "</ENTRY>";
$asf_buf .= "</ASX>";
if( !isset( $_GET['mode'] ) ){
	header("Expires: Thu, 01 Dec 1994 16:00:00 GMT");
	header("Last-Modified: ". gmdate("D, d M Y H:i:s"). " GMT");
	header("Cache-Control: no-cache, must-revalidate");
	header("Cache-Control: post-check=0, pre-check=0", false);
	header("Pragma: no-cache");
	header("Content-type: video/x-ms-asf; charset=\"UTF-8\"");
	header('Content-Disposition: inline; filename="'.$channel.'.asx"');
	echo $asf_buf;
}else{
	// 別PCからチャンネル変更をする試み（失敗）
	$asf_file_name = '/tmp/'.$channel.'.asx';
	file_put_contents ( $asf_file_name, $asf_buf );
	exec( 'sudo -u user-name vlc '.$asf_file_name );		// --playlist-enqueue ここがうまくいかない
}
exit( 1 );
?> 
