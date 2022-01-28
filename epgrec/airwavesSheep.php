#!/usr/bin/php
<?php
  $script_path = dirname( __FILE__ );
  chdir( $script_path );
  include( $script_path . '/config.php');
  include_once( INSTALL_PATH . '/DBRecord.class.php' );
  include_once( INSTALL_PATH . '/Settings.class.php' );
  include_once( INSTALL_PATH . '/Reservation.class.php' );
  include_once( INSTALL_PATH . '/storeProgram.inc.php' );
	include_once( INSTALL_PATH . '/reclib.php' );
	include_once( INSTALL_PATH . '/recLog.inc.php' );

// 録画開始前EPG更新に定期EPG更新が重ならないようにする。
function scout_wait()
{
	$sql_cmd = 'WHERE complete=0 AND starttime>now() AND starttime<addtime( now(), "00:03:00" )';
	while(1){
		$num = DBRecord::countRecords( RESERVE_TBL, $sql_cmd );
		if( $num ){
			$revs = DBRecord::createRecords( RESERVE_TBL, $sql_cmd.' ORDER BY starttime DESC' );
			$sleep_next = toTimestamp( $revs[0]->starttime );
			sleep( $sleep_next-time() );
		}else
			return;
	}
}

function sig_handler()
{
	global	$temp_xml,$temp_ts;

	// シャットダウンの処理
	//テンポラリーファイル削除
	if( isset( $temp_ts ) && file_exists( $temp_ts ) )
		@unlink( $temp_ts );
	if( isset( $temp_xml ) && file_exists( $temp_xml ) )
		@unlink( $temp_xml );
	//共有メモリー変数初期化
	switch( $_SERVER['argv'][1] ){
		case 'GR':
			$shm_name = SEM_GR_START;
			break;
		case 'BS':
		case 'CS':
			$shm_name = SEM_ST_START;
			break;
		case 'EX':
			$shm_name = SEM_EX_START;
			break;
		default:
			exit;
	}
	$shm_name += (int)$_SERVER['argv'][2];
	$shm_id    = shmop_open_surely();
	if( shmop_read_surely( $shm_id, $shm_name ) ){
		shmop_write_surely( $shm_id, $shm_name, 0 );
	}
	shmop_close( $shm_id );
	exit;
}

	// シグナルハンドラを設定
	declare( ticks = 1 );
	pcntl_signal( SIGTERM, 'sig_handler' );

	$type     = $argv[1];	//GR/BS/CS/EX
	$tuner    = (int)$argv[2];
	$value    = $argv[3];	//ch
	$rec_time = (int)$argv[4];
	$ch_disk  = $argv[5];
	$slp_time = isset( $argv[6] ) ? (int)$argv[6] : 0;
	$cut_sids = isset( $argv[7] ) ? $argv[7] : '';

	$smf_type = $type==='CS' ? 'BS' : $type;
	switch( $smf_type ){
		case 'GR':
			$shm_name = SEM_GR_START;
			break;
		case 'BS':
//		case 'CS':
			$shm_name = SEM_ST_START;
			break;
		case 'EX':
			$shm_name = SEM_EX_START;
			break;
		default:
			reclog( 'airwavesSheep.php::チューナー種別エラー "'.$type.'"は未定義です。<br>チューナー占有フラグが残留している可能性があります。', EPGREC_ERROR );
			exit;
	}
	$shm_name += $tuner;
	$dmp_type = $type==='GR' ? $ch_disk : '/'.$type;								// 無改造でepgdumpのプレミアム対応が出来ればこのまま
//	$dmp_type = $type==='GR' ? $ch_disk : '/'.($type==='EX' ? 'CS' : $type);

	$settings = Settings::factory();
	$temp_xml = $settings->temp_xml.'_'.$type.$value;
	$temp_ts  = $settings->temp_data.'_'.$smf_type.$tuner.$type.$value;

	//EPG受信
	sleep( $settings->rec_switch_time+1 );
	if( $type === 'EX' ){
		$cmd_num = $EX_TUNERS_CHARA[$tuner]['reccmd'];
		$device  = $EX_TUNERS_CHARA[$tuner]['device']!=='' ? ' '.trim($EX_TUNERS_CHARA[$tuner]['device']) : '';
	}else{
		if( $tuner < TUNER_UNIT1 ){
			$cmd_num = PT1_CMD_NUM;
			$device  = '';
		}else{
			$cmd_num = $OTHER_TUNERS_CHARA[$smf_type][$tuner-TUNER_UNIT1]['reccmd'];
			$device  = $OTHER_TUNERS_CHARA[$smf_type][$tuner-TUNER_UNIT1]['device']!=='' ? ' '.trim($OTHER_TUNERS_CHARA[$smf_type][$tuner-TUNER_UNIT1]['device']) : '';
		}
	}
	$sid      = $rec_cmds[$cmd_num]['epgTs'] ? ' --sid epg' : '';
	$falldely = $rec_cmds[$cmd_num]['falldely']>0 ? ' || sleep '.$rec_cmds[$cmd_num]['falldely'] : '';
	$cmd_ts   = $rec_cmds[$cmd_num]['cmd'].$rec_cmds[$cmd_num]['b25'].$device.$sid.' '.$value.' '.$rec_time.' '.$temp_ts.$falldely;
	// プライオリティ低に
	pcntl_setpriority(20);
	exe_start( $cmd_ts, (int)$rec_time, 10, FALSE );

	//チューナー占有解除
	$shm_id = shmop_open_surely();
	$sem_id = sem_get_surely( $shm_name );
	while( sem_acquire( $sem_id ) === FALSE )
		sleep( 1 );
	shmop_write_surely( $shm_id, $shm_name, 0 );
	while( sem_release( $sem_id ) === FALSE )
		usleep( 100 );

	if( file_exists( $temp_ts ) && filesize( $temp_ts ) ){
		scout_wait();
		while(1){
			$sem_id = sem_get_surely( SEM_EPGDUMP );
			if( $sem_id !== FALSE ){
				while(1){
					if( sem_acquire( $sem_id ) === TRUE ){
						//xml抽出
						$cmd_xml = $settings->epgdump.' '.$dmp_type.' '.$temp_ts.' '.$temp_xml;
						if( $type!=='GR' && $cut_sids!=='' )
							$cmd_xml .= ' -cut '.$cut_sids;
						if( exe_start( $cmd_xml, 5*60 ) === 2 ){
							$new_name = $temp_ts.'.'.toDatetime(time());
							rename( $temp_ts, $new_name );
						}else
							@unlink( $temp_ts );
						while( sem_release( $sem_id ) === FALSE )
							usleep( 100 );
						break 2;
					}
					sleep(1);
				}
			}
			sleep(1);
		}
		if( file_exists( $temp_xml ) ){
			if( $slp_time )
				sleep( $slp_time );
			scout_wait();
			while(1){
				$sem_id = sem_get_surely( SEM_EPGSTORE );
				if( $sem_id !== FALSE ){
					while(1){
						if( sem_acquire( $sem_id ) === TRUE ){
							//EPG更新
							if( storeProgram( $type, $temp_xml ) != -1 )
								@unlink( $temp_xml );
							else
								reclog( $cmd_ts, EPGREC_WARN );
							while( sem_release( $sem_id ) === FALSE )
								usleep( 100 );
							break 2;
						}
						sleep(1);
					}
				}
				sleep(1);
			}
		}else
			reclog( 'EPG受信失敗:xmlファイル"'.$temp_xml.'"がありません(放送間帯でないなら問題ありません)', EPGREC_WARN );
	}else{
		reclog( 'EPG受信失敗:TSファイル"'.$temp_ts.'"がありません(放送間帯でないなら問題ありません)<br>'.$cmd_ts, EPGREC_WARN );
		if( $type!=='EX' && $tuner<TUNER_UNIT1 ){
			$smph = shmop_read_surely( $shm_id, SEM_REBOOT );
			if( $smph === 0 )
				shmop_write_surely( $shm_id, SEM_REBOOT, 1 );
		}
	}
	shmop_close( $shm_id );
	exit();
?>
