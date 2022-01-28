<?php
header('Expires: Thu, 01 Dec 1994 16:00:00 GMT');
header('Last-Modified: '. gmdate('D, d M Y H:i:s'). ' GMT');
header('Cache-Control: no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');


include('config.php');
include_once(INSTALL_PATH . '/DBRecord.class.php' );
include_once(INSTALL_PATH . '/reclib.php' );
include_once(INSTALL_PATH . '/Settings.class.php' );

$settings = Settings::factory();


function searchProces( $cmd, $pid )
{
	$ps_output = shell_exec( PS_CMD );
	$rarr      = explode( "\n", $ps_output );
	foreach( $rarr as $cc ){
		if( strpos( $cc, $cmd ) !== FALSE ){
			$ps = ps_tok( $cc );
			if( (int)$ps->pid === $pid )
				return 0;
		}
	}
	return ESRCH;
}

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
		$shm_name = (int)$_GET['shm'];
		if( $shm_name >= SEM_EX_START ){
			if( count($EX_TUNERS_CHARA) > $shm_name-SEM_EX_START ){
				$cmd_num = $EX_TUNERS_CHARA[$shm_name-SEM_EX_START]['reccmd'];
				$device  = $EX_TUNERS_CHARA[$shm_name-SEM_EX_START]['device']!=='' ? ' '.trim($EX_TUNERS_CHARA[$shm_name-SEM_EX_START]['device']) : '';
			}else{
				reclog( 'sendstream:$EX_TUNERS_CHARAの設定数が不足($_GET[\'shm\']='.$shm_name.')', EPGREC_WARN );
				exit();
			}
		}else{
			if( $shm_name >= SEM_ST_START ){
				$tuner = $shm_name - SEM_ST_START;
				$type  = 'BS';
			}else
				if( $shm_name >= SEM_GR_START ){
					$tuner = $shm_name - SEM_GR_START;
					$type  = 'GR';
				}else{
					reclog( 'sendstream:チューナー指定が無効($_GET[\'shm\']='.$shm_name.')', EPGREC_WARN );
					exit();
				}
			if( $tuner < TUNER_UNIT1 ){
				$cmd_num = PT1_CMD_NUM;
				$device  = '';
			}else
				if( count($OTHER_TUNERS_CHARA[$type]) > $tuner-TUNER_UNIT1 ){
					$cmd_num = $OTHER_TUNERS_CHARA[$type][$tuner-TUNER_UNIT1]['reccmd'];
					$device  = $OTHER_TUNERS_CHARA[$type][$tuner-TUNER_UNIT1]['device']!=='' ? ' '.trim($OTHER_TUNERS_CHARA[$type][$tuner-TUNER_UNIT1]['device']) : '';
				}else{
					reclog( 'sendstream:$OTHER_TUNERS_CHARA['.$type.']の設定数が不足($_GET[\'shm\']='.$shm_name.')', EPGREC_WARN );
					exit();
				}
		}
		$rec_cmd = $rec_cmds[$cmd_num]['cmd'].$rec_cmds[$cmd_num]['b25'].$device.' --sid '.$_GET['sid'].' '.$_GET['ch'].' - -';
		// チューナー占有
		$shm_id = shmop_open_surely();
		$sem_id = sem_get_surely( $shm_name );
		$rv_sem = sem_get_surely( SEM_REALVIEW );
		while( sem_acquire( $rv_sem ) === FALSE )
			usleep( 100 );
		while( sem_acquire( $sem_id ) === FALSE )
			usleep( 100 );
		shmop_write_surely( $shm_id, $shm_name, 2 );		// リアルタイム視聴指示
		while( sem_release( $sem_id ) === FALSE )
			usleep( 100 );
		shmop_write_surely( $shm_id, SEM_REALVIEW, $shm_name );		// リアルタイム視聴tunerNo set
		while( sem_release( $rv_sem ) === FALSE )
			usleep( 100 );
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
		exit();
	}
	if( $rec_cmd !== '' ){
		// 録画コマンドのPID保存
/* シェルのPIDになるのでダメ
		$ts_stat = proc_get_status( $ts_pro );
		if( $ts_stat['running'] === TRUE ){
			$handle = fopen( REALVIEW_PID, 'w' );
			fwrite( $handle, (string)$ts_stat['pid'] );
			fclose( $handle );
		}else{
			$errno = posix_get_last_error();
			reclog( 'sendstream.php::録画コマンド常駐失敗[exitcode='.$ts_stat['exitcode'].']$errno('.$errno.')'.posix_strerror( $errno ), EPGREC_WARN );
			goto STREAM_END;
		}
*/
		// ここで少し待った方が良いかも
		sleep(1);
		$ps_output = shell_exec( PS_CMD );
		$rarr      = explode( "\n", $ps_output );
		$pid       = $ppid = 0;
		// PID取得
		foreach( $rarr as $cc ){
			if( strpos( $cc, $rec_cmd ) !== FALSE ){
				$ps      = ps_tok( $cc );
				$tmppid  = (int)$ps->pid;
				$tmpppid = (int)$ps->ppid;
				if( ( $pid===0 && $ppid===0 ) || $pid===$tmpppid || $tmpppid===1 ){
					$pid  = $tmppid;
					$ppid = $tmpppid;
				}
			}
		}
		if( $pid !== 0 ){
			// 常駐成功
			$handle = fopen( REALVIEW_PID, 'w' );
			fwrite( $handle, $pid );
			fclose( $handle );
//			$st = proc_get_status( $ts_pro );
//			if( $pid !== (int)$st['pid'] )
//				reclog( 'sendstream.php::true PID['.$pid.'] get PID['.$st['pid'].']', EPGREC_WARN );
		}else{
			// 常駐失敗
			$errno   = posix_get_last_error();
			$ts_stat = proc_get_status( $ts_pro );
			if( $ts_stat['running'] == TRUE )
				proc_terminate( $ts_pro, 9 );
			reclog( 'sendstream.php::録画コマンド常駐失敗('.$errno.')'.posix_strerror( $errno ), EPGREC_WARN );
			echo '録画コマンドの常駐に失敗しました。';
			goto STREAM_END;
		}
	}

	header('Content-type: video/x-mpeg');
	header('Content-Disposition: inline; filename="'.$path.'"');

	while( ob_get_level() > 0 )
		ob_end_clean();
	flush();

	ignore_user_abort( TRUE );
	set_time_limit( 0 );
	stream_set_blocking( $ts_pipes[1], 0 );		// 必要ないかも
	$file_end = FALSE;
	do {
		$start = microtime( true );
		if( feof( $ts_pipes[1] ) ){
			$file_end = TRUE;
			break;
		}
		echo fread( $ts_pipes[1], 6292 );
		@usleep( 2000 - (int)((microtime(true) - $start) * 1000 * 1000));
	}while( connection_aborted() == 0 );

STREAM_END:
	fclose( $ts_pipes[1] );
	proc_close( $ts_pro );
	if( $rec_cmd !== '' ){
		if( file_exists( REALVIEW_PID ) ){
			$real_view = (int)trim( file_get_contents( REALVIEW_PID ) );
			unlink( REALVIEW_PID );
			// 録画コマンド停止
			$search_throw = TRUE;
			while( searchProces( $rec_cmd, $real_view ) !== ESRCH ){
				$search_throw = FALSE;
				posix_kill( $real_view, 9 );
				usleep( 100*1000 );
			}
			if( $search_throw )
				$file_end = shmop_read_surely( $shm_id, SEM_REALVIEW )==0;
		}
		if( $file_end === FALSE ){
			// 他プロセスからの停止時はやらない
			// チューナー開放
			while( sem_acquire( $sem_id ) === FALSE )
				usleep( 100 );
			shmop_write_surely( $shm_id, $shm_name, 0 );
			while( sem_release( $sem_id ) === FALSE )
				usleep( 100 );
			// リアルタイム視聴tunerNo clear
			while( sem_acquire( $rv_sem ) === FALSE )
				usleep( 100 );
			shmop_write_surely( $shm_id, SEM_REALVIEW, 0 );
			while( sem_release( $rv_sem ) === FALSE )
				usleep( 100 );
		}
		shmop_close( $shm_id );
	}

	exit(0);	// とりあえず様子見
}else{
	if( isset( $_GET['trans_id'] ) ){
		// トラコン中ファイル
		try{
			$trans_set   = new DBRecord( TRANSCODE_TBL, 'id', $_GET['trans_id'] );
			$target_path = $trans_set->path;
			$filename    = end( explode( '/', $target_path ) );
			$size        = 3 * 1024 * 1024 * $duration;	// いいかげん
		}catch(exception $e ){
			reclog( 'sendstream: 失敗 '.$e, EPGREC_WARN );
			exit( $e->getMessage() );
		}
	}else{
		// TSファイル
		$target_path = INSTALL_PATH.$settings->spool.'/'.$path;
		$filename    = $path;
		$size        = 3 * 1024 * 1024 * $duration;	// 1秒あたり3MBと仮定
	}

	header('Content-type: video/mpeg');
	header('Content-Disposition: inline; filename="'.$filename.'"');
	header('Content-Length: ' . $size );

	while (ob_get_level() > 0)
		ob_end_clean();
	flush();

	$fp = @fopen( $target_path, 'r' );
	if( $fp !== false ) {
		do {
			$start = microtime(true);
			if( feof( $fp ) ) break;
			echo fread( $fp, 6292 );
			@usleep( 2000 - (int)((microtime(true) - $start) * 1000 * 1000));
		}while( connection_aborted() == 0 );
		fclose($fp);
	}
}
?>
