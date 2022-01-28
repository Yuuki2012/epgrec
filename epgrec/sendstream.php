<?php
ob_start();


include('config.php');
include_once(INSTALL_PATH . '/DBRecord.class.php' );
include_once(INSTALL_PATH . '/reclib.php' );
include_once(INSTALL_PATH . '/Settings.class.php' );
include( INSTALL_PATH . '/realview_stop.php' );


function searchRecProces( $cmd, $pid )
{
	$cmd_pie     = explode( ' ', $cmd );
	$cmd_pie[0] .= ' ';
	$ps_output   = shell_exec( PS_CMD );
	$rarr        = explode( "\n", $ps_output );
	foreach( $rarr as $cc ){
		if( strpos( $cc, $cmd_pie[0] ) !== FALSE ){
			$ps = ps_tok( $cc );
			if( (int)$ps->pid === $pid )
				return 0;
		}
	}
	return ESRCH;
}


$container = 'mpeg';
function codec_format( $strow )
{
	$needles = array(	'mpeg' => '-f mpegts',
						'mp4'  => '-f mp4',
						'webm' => '-f webm',
						'ogg'  => '-f ogg',
					);

	foreach( $needles as $key => $row ){
		if( stripos( $strow, $row ) !== FALSE ){
			return $key;
		}
	}
	return 'mpeg';
}

$settings = Settings::factory();

if( isset( $_GET['reserve_id'] ) ){
	$reserve_id = $_GET['reserve_id'];
	try{
		$rrec = new DBRecord( RESERVE_TBL, 'id', $reserve_id );
		$start_time = toTimestamp($rrec->starttime);
		$end_time = toTimestamp($rrec->endtime );
		$duration = $end_time - $start_time;
		$path     = $rrec->path;
	}catch(exception $e ){
		reclog( 'sendstream: 失敗 '.$e, EPGREC_WARN );
		exit( $e->getMessage() );
	}
	$pipe_mode = isset( $_GET['trans'] );
}else
	if( isset( $_GET['ch'] ) ){
		$pipe_mode = TRUE;
		$path      = 'TUNER';
	}else
		jdialog('予約番号が指定されていません', 'recordedTable.php');

if( $pipe_mode ){
	// pipeストリーム
	if( isset( $_GET['ch'] ) ){
		// チューナー
		$ff_input = 'pipe:0';

		// リアルタイム視聴の停止
		$shm_id = shmop_open_surely();
		$rv_sem = realview_stop( $shm_id );
		if( $rv_sem === FALSE ){
			shmop_close( $shm_id );
			exit;
		}

		// チューナー確保
		if( !isset( $_GET['type'] ) ){
			shmop_close( $shm_id );
			exit;
		}
		$type = $_GET['type'];
		switch( $type ){
			case 'GR':
				$sql_type = 'type="GR"';
				$smf_key  = SEM_GR_START;
				$tuners   = (int)$settings->gr_tuners;
				break;
			case 'EX':
				$sql_type = 'type="EX"';
				$smf_key  = SEM_EX_START;
				$tuners   = EXTRA_TUNERS;
				break;
			case 'BS':
			case 'CS':
				$sql_type = '(type="BS" OR type="CS")';
				$smf_key  = SEM_ST_START;
				$tuners   = (int)$settings->bs_tuners;
				break;
			default:
				shmop_close( $shm_id );
				exit;
		}
		$sem_id = array();
		for( $sem_cnt=0; $sem_cnt<$tuners; $sem_cnt++ ){
			$rv_smph          = $smf_key + $sem_cnt;
			$sem_id[$rv_smph] = sem_get_surely( $rv_smph );
			if( $sem_id[$rv_smph] === FALSE ){
				shmop_close( $shm_id );
				exit;
			}
		}
		$res_obj = new DBRecord( RESERVE_TBL );
		$sql_cmd = 'complete=0 AND '.$sql_type.' AND endtime>now() AND starttime<addtime( now(), "00:03:00" )';
		$lp      = 0;
		while(1){
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
						if( $smph === 0 ){
							// チューナー占有
							if( $type === 'EX' ){
								$cmd_num = $EX_TUNERS_CHARA[$slc_tuner]['reccmd'];
								$device  = $EX_TUNERS_CHARA[$slc_tuner]['device']!=='' ? ' '.trim($EX_TUNERS_CHARA[$slc_tuner]['device']) : '';
							}else{
								if( $slc_tuner < TUNER_UNIT1 ){
									$cmd_num = PT1_CMD_NUM;
									$device  = '';
								}else{
									$other_tuner = $slc_tuner-TUNER_UNIT1;
									$cmd_num = $OTHER_TUNERS_CHARA[$type][$other_tuner]['reccmd'];
									$device  = $OTHER_TUNERS_CHARA[$type][$other_tuner]['device']!=='' ? ' '.trim($OTHER_TUNERS_CHARA[$type][$other_tuner]['device']) : '';
								}
							}
							$rec_cmd = $rec_cmds[$cmd_num]['cmd'].$rec_cmds[$cmd_num]['b25'].$device.' --sid '.$_GET['sid'].' '.$_GET['ch'].' - -';
							break 2;	// トラコン設定へ
						}else{
							//占有失敗
							while( sem_release( $sem_id[$shm_name] ) === FALSE )
								usleep( 100 );
						}
					}
				}
			}
			if( $lp++ > 10 ){
				while( sem_release( $rv_sem ) === FALSE )
					usleep( 100 );
				shmop_close( $shm_id );
				echo '別処理でチューナーを使用中です。';
				exit( 1 );
			}
			sleep(1);
		}
	}else{
		// ファイル
		$ff_input = '\''.INSTALL_PATH.$settings->spool.'/'.$path.'\'';
		$rec_cmd  = '';
	}
	if( isset($_GET['trans']) && $_GET['trans']!=='-1' ){
		// トランスコード有り
		if( (int)$_GET['trans'] >= RESIZE_LOW ){
			// 画角直接指定
			$width  = (int)$_GET['trans'];
			if( $width > RESIZE_HIGH )
				$width = RESIZE_HIGH;
			$height = (int)( $width * 9 / 16 );
		}else{
			// 画角設定値指定
			$size_mode = $_GET['trans']==='ON' || (int)$_GET['trans']>=count( $TRANSSIZE_SET ) ? TRANSTREAM_SIZE_DEFAULT : (int)$_GET['trans'];
			$width     = $TRANSSIZE_SET[$size_mode]['width'];
			$height    = $TRANSSIZE_SET[$size_mode]['height'];
		}
		$trans = array( '%FFMPEG%' => $settings->ffmpeg,
						'%TS%'     => $ff_input,									// 入力元
						'%WIDTH%'  => $width,										// 幅
						'%HIEGHT%' => $height,										// 高さ
						'%OUTPUT%' => 'pipe:1',										// 出力先
//						'%PORT%'   => ':'.REALVIEW_HTTP_PORT,						// ポート番号
				);
		$container = codec_format( TRANSTREAM_CMD );
		$trans_cmd = strtr( TRANSTREAM_CMD, $trans );
		if( $rec_cmd !== '' )
			$trans_cmd = $rec_cmd.' | '.$trans_cmd;
	}else
		$trans_cmd = $rec_cmd;
	$ts_descspec = array(
					0 => array( 'file','/dev/null','r' ),
					1 => array( 'pipe','w' ),
					2 => array( 'file','/dev/null','w' ),
	);
	$ts_pro = proc_open( $trans_cmd, $ts_descspec, $ts_pipes );
	if( !is_resource( $ts_pro ) ){
		reclog( 'ストリーミング失敗:コマンドに異常がある可能性があります<br>'.$trans_cmd, EPGREC_WARN );
		if( $rec_cmd !== '' ){
			while( sem_release( $sem_id[$shm_name] ) === FALSE )
				usleep( 100 );
			while( sem_release( $rv_sem ) === FALSE )
				usleep( 100 );
			shmop_close( $shm_id );
		}
		exit();
	}
	if( $rec_cmd !== '' ){
		// 録画コマンドのPID保存
		$ts_stat = proc_get_status( $ts_pro );
		if( $ts_stat['running'] === TRUE ){
			$ppid            = (int)$ts_stat['pid'];
			$rec_cmd_pie     = explode( ' ', $rec_cmd );
			$rec_cmd_pie[0] .= ' ';
			$pid_retry       = FALSE;
GET_PID_RETRY:
			// ここで少し待った方が良いかも
			sleep(1);
			$ps_output = shell_exec( PS_CMD );
			$rarr      = explode( "\n", $ps_output );
			$stock_pid = 0;
			// PID取得
			foreach( $rarr as $cc ){
				if( strpos( $cc, $rec_cmd_pie[0] ) !== FALSE ){
					$ps = ps_tok( $cc );
					if( $ppid === (int)$ps->ppid ){
						$stock_pid = (int)$ps->pid;
						break;
					}
				}
			}
			if( $stock_pid === 0 ){
				if( $pid_retry === FALSE ){
					$pid_retry = TRUE;
					goto GET_PID_RETRY;
				}else{
					foreach( $rarr as $cc ){
						if( strpos( $cc, $rec_cmd_pie[0] ) !== FALSE ){
							$ps = ps_tok( $cc );
							// shellのPIDで代用
							if( $ppid === (int)$ps->pid ){
								$stock_pid = (int)$ps->pid;
								break;
							}
						}
					}
				}
			}
			if( $stock_pid !== 0 ){
				// 常駐成功
				$handle = fopen( REALVIEW_PID, 'w' );
				fwrite( $handle, $stock_pid );
				fclose( $handle );
				shmop_write_surely( $shm_id, $shm_name, 2 );		// リアルタイム視聴指示
				while( sem_release( $sem_id[$shm_name] ) === FALSE )
					usleep( 100 );
				shmop_write_surely( $shm_id, SEM_REALVIEW, $shm_name );		// リアルタイム視聴tunerNo set
				while( sem_release( $rv_sem ) === FALSE )
					usleep( 100 );
				goto SEND_STREAM;
			}else{
				// 常駐失敗? PID取得失敗
				$errno = posix_get_last_error();
				reclog( 'sendstream.php::録画コマンドPID取得失敗('.$errno.')'.posix_strerror( $errno ), EPGREC_WARN );
			}
		}else{
			// 常駐失敗
			$errno = posix_get_last_error();
			reclog( 'sendstream.php::録画コマンド常駐失敗[exitcode='.$ts_stat['exitcode'].']$errno('.$errno.')'.posix_strerror( $errno ), EPGREC_WARN );
		}
		fclose( $ts_pipes[1] );
		proc_close( $ts_pro );
		while( sem_release( $sem_id[$shm_name] ) === FALSE )
			usleep( 100 );
		while( sem_release( $rv_sem ) === FALSE )
			usleep( 100 );
		shmop_close( $shm_id );
		exit();
	}

SEND_STREAM:
	ignore_user_abort( TRUE );
	set_time_limit( 0 );
//	flush();
	stream_set_blocking( $ts_pipes[1], 0 );		// 必要ないかも
	header('Expires: Thu, 01 Dec 1994 16:00:00 GMT');
	header('Last-Modified: '. gmdate('D, d M Y H:i:s'). ' GMT');
	header('Cache-Control: no-cache, must-revalidate');
	header('Cache-Control: post-check=0, pre-check=0', false);
	header('Pragma: no-cache');
	header('Content-type: video/'.$container);
//	header('Content-Type: video/octet-stream');
//	header('Content-Disposition: inline; filename="'.htmlspecialchars( end( explode( '/', $path ) ) ).'"');
	header('Content-Disposition: inline');
//	echo "\r\n";
	while( ob_get_level() > 0 )
		ob_end_clean();
	do {
		$start = microtime( true );
		if( feof( $ts_pipes[1] ) ){
			break;
		}
		echo fread( $ts_pipes[1], 12032 );
		@usleep( 2000 - (int)((microtime(true) - $start) * 1000 * 1000));
	}while( connection_aborted() == 0 );

	fclose( $ts_pipes[1] );
	proc_close( $ts_pro );
	if( $rec_cmd !== '' ){
		while( sem_acquire( $rv_sem ) === FALSE )
			usleep( 100 );
		if( file_exists( REALVIEW_PID ) ){
			$real_view = (int)trim( file_get_contents( REALVIEW_PID ) );
			if( $real_view === $stock_pid ){
				unlink( REALVIEW_PID );
				// 録画コマンド停止
				if( searchRecProces( $rec_cmd, $real_view ) !== ESRCH ){
					posix_kill( $real_view, 9 );
					usleep( 100*1000 );
				}
				if( shmop_read_surely( $shm_id, SEM_REALVIEW ) === $shm_name ){
					// 他プロセスからの停止時はやらない
					while( sem_acquire( $sem_id[$shm_name] ) === FALSE )
						usleep( 100 );
					shmop_write_surely( $shm_id, $shm_name, 0 );		// チューナー開放
					while( sem_release( $sem_id[$shm_name] ) === FALSE )
						usleep( 100 );
					shmop_write_surely( $shm_id, SEM_REALVIEW, 0 );		// リアルタイム視聴tunerNo clear
				}
			}
		}
		while( sem_release( $rv_sem ) === FALSE )
			usleep( 100 );
		shmop_close( $shm_id );
	}

	exit(0);	// とりあえず様子見
}else{
	if( isset( $_GET['trans_id'] ) ){
		// トラコン中ファイル
		try{
			$trans_set   = new DBRecord( TRANSCODE_TBL, 'id', $_GET['trans_id'] );
			$target_path = $trans_set->path;
			$filename    = htmlspecialchars( end( explode( '/', $target_path ) ) );
			$size        = 3 * 1024 * 1024 * $duration;	// いいかげん
			$container   = codec_format( $RECORD_MODE[$trans_set->mode]['format'] );
		}catch(exception $e ){
			reclog( 'sendstream: 失敗 '.$e, EPGREC_WARN );
			exit( $e->getMessage() );
		}
	}else{
		// TSファイル
		$target_path = INSTALL_PATH.$settings->spool.'/'.$path;
		$filename    = htmlspecialchars( end( explode( '/', $path ) ) );
		$size        = 3 * 1024 * 1024 * $duration;	// 1秒あたり3MBと仮定
	}

//	flush();
	header('Expires: Thu, 01 Dec 1994 16:00:00 GMT');
	header('Last-Modified: '. gmdate('D, d M Y H:i:s'). ' GMT');
	header('Cache-Control: no-cache, must-revalidate');
	header('Cache-Control: post-check=0, pre-check=0', false);
	header('Pragma: no-cache');
	header('Content-type: video/'.$container);
//	header('Content-Type: video/octet-stream');
	header('Content-Disposition: inline; filename="'.$filename.'"');
	header('Content-Length: '.$size );
//	echo "\r\n";
	while( ob_get_level() > 0 )
		ob_end_clean();

	$fp = @fopen( $target_path, 'r' );
	if( $fp !== false ) {
		do {
			$start = microtime(true);
			if( feof( $fp ) ) break;
			echo fread( $fp, 12032 );
			@usleep( 2000 - (int)((microtime(true) - $start) * 1000 * 1000));
		}while( connection_aborted() == 0 );
		fclose($fp);
	}
}
?>
