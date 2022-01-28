#!/usr/bin/php
<?php
$script_path = dirname( __FILE__ );
chdir( $script_path );
include_once( $script_path . '/config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Reservation.class.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/recLog.inc.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include( INSTALL_PATH . '/powerReduce.inc.php' );


function get_logcmd( $sys_logs, $grep_word, $omit_word )
{
	$put_logs = array();
	foreach( $sys_logs as $logline ){
		if( strpos( $logline, $grep_word ) !== FALSE )
			if( strpos( $logline, $omit_word ) === FALSE )
				$put_logs[] = htmlspecialchars( $logline );
	}
	return count( $put_logs ) ? '<br><font color="#ff0000">'.implode( '<br>', $put_logs ).'</font>' : '';
}

function get_syslog( $get_time )
{
	$ss = stat( '/var/log/syslog' );
	if( $ss['mode'] & 000004 ){
		$be_time  = (int)(($get_time-1)/10) * 10;
		$set_time = (int)(($get_time+1)/10) * 10;
		$sys_logs = file( '/var/log/syslog', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$put_logs = array();
		$seach_wd = date('M ',$be_time).sprintf( '% 2s', date('j',$be_time) ).date(' H:i:',$be_time).((int)(($be_time%60)/10));
		foreach( $sys_logs as $logline ){
			if( strpos( $logline, $seach_wd ) !== FALSE )
				$put_logs[] = $logline;
		}
		if( $be_time !== $set_time ){
			$seach_wd = date('M ',$set_time).sprintf( '% 2s', date('j',$set_time) ).date(' H:i:',$set_time).((int)(($set_time%60)/10));
			foreach( $sys_logs as $logline ){
				if( strpos( $logline, $seach_wd ) !== FALSE )
					$put_logs[] = $logline;
			}
		}
		if( count( $put_logs ) ){
			// PT1のログを取得
			$log = get_logcmd( $put_logs, 'Drop=', 'Drop=00000000:00000000:00000000:00000000' );
			if( $log === '' )
				// PT3のログを取得(ドライバーのログ出力が必要)
				$log = get_logcmd( $put_logs, ') error count ', 'error count 0' );
			return $log;
		}else
			return '';
	}else
		return '';
}

// トランスコードJOB追加
function trans_job_set( $rrec, $tran_ex )
{
	global $RECORD_MODE,$settings;

	$wrt_set = array();
	$wrt_set['rec_id']      = $rrec->id;
	$wrt_set['rec_endtime'] = $rrec->endtime;
	$wrt_set['mode']        = $tran_ex['mode'];
	$wrt_set['ts_del']      = $tran_ex['ts_del'];
	// ファイル名生成 文字数チェックは行なわない。
	$ts_name         = end( explode( '/', $rrec->path ) );
	$ts_suffix       = strpos( $ts_name, $RECORD_MODE[$tran_ex['mode']]['suffix'] )!==FALSE ? $RECORD_MODE[$tran_ex['mode']]['suffix'] : $RECORD_MODE[$rrec->mode]['suffix'];
	$trans_name      = str_replace( $ts_suffix, $RECORD_MODE[$tran_ex['mode']]['tsuffix'], $ts_name );
	$wrt_set['path'] = str_replace( '%VIDEO%', INSTALL_PATH.$settings->spool, TRANS_ROOT ).($tran_ex['dir']!='' ? '/'.$tran_ex['dir'] : '').'/'.$trans_name;
	$trans_obj = new DBRecord( TRANSCODE_TBL );
	$trans_obj->force_update( 0, $wrt_set );
}

$settings = Settings::factory();

$reserve_id = $argv[1];
$job_set    = FALSE;

try{
	$rrec = new DBRecord( RESERVE_TBL, 'id' , $reserve_id );
	$rev_id = '[予約ID:'.$rrec->id;
	$rev_ds = $rrec->channel_disc.'(T'.$rrec->tuner.'-'.$rrec->channel.') '.$rrec->starttime.' 『'.$rrec->title.'』';
	$ts_path = INSTALL_PATH .$settings->spool . '/'. $rrec->path;

	$autorec    = (int)$rrec->autorec;
	$program_id = (int)$rrec->program_id;
	$get_time   = time();
	if( $autorec>=0 && $program_id>0 && storage_free_space( $ts_path )>TS_STREAM_RATE ){
		// PID付き手動予約も制限付きで対応
		$prg = new DBRecord( PROGRAM_TBL, 'id', $program_id );
		if($autorec ){
			$keyword     = new DBRecord( KEYWORD_TBL, 'id', $autorec );
			$restart_lmt = toTimestamp( $prg->starttime ) + REC_RETRY_LIMIT + (int)$keyword->sft_start;
			$starttime   = $prg->starttime;
			$endtime     = $prg->endtime;
		}else{
			$restart_lmt = toTimestamp( $prg->starttime ) + REC_RETRY_LIMIT;
			$starttime   = $rrec->starttime;
			$endtime     = $rrec->shortened ? toDatetime(toTimestamp($rrec->endtime)+(int)$settings->former_time+(int)$settings->rec_switch_time) : $rrec->endtime;
		}
		if( $restart_lmt < toTimestamp( $rrec->endtime ) ){
			if( $restart_lmt <= $get_time ){
				// PT2が不安定な場合、リブートする
				$shm_id = shmop_open_surely();
				while(1){
					if( PT1_REBOOT && $rrec->type!=='EX' && $rrec->tuner<TUNER_UNIT1 && $get_time<$restart_lmt+REC_RETRY_LIMIT ){
						$smph = shmop_read_surely( $shm_id, SEM_REBOOT );
						switch( $smph ){
							case 1:
								break;
							case 2:
								// shepherd.phpによるリブートを抑制
								shmop_write_surely( $shm_id, SEM_REBOOT, 3 );
								$smph = 3;
							default:
								$res_obj = new DBRecord( RESERVE_TBL );
								// 5分以内に予約がなければリブート(変更する場合は3分より大きくすること)
								$sql_cmd = 'complete=0 AND id!='.$reserve_id.' AND endtime>"'.toDatetime($get_time).'" AND starttime<"'.toDatetime($get_time+5*60).'" ORDER BY endtime DESC';
								$revs    = $res_obj->fetch_array( null, null, $sql_cmd );
								if( count( $revs ) == 0 ){
									reclog( REBOOT_COMMENT.'now', EPGREC_WARN );
									system( REBOOT_CMD );
									shmop_write_surely( $shm_id, SEM_REBOOT, 0 );
									shmop_close( $shm_id );
									break 2;
								}else{
									if( $smph == 3 )
										// shepherd.phpにリブート権を委譲
										shmop_write_surely( $shm_id, SEM_REBOOT, 2 );
								}
								break;
						}
					}
					shmop_close( $shm_id );
					goto PUT_LOG;
				}
			}
			// 録画開始に失敗 再予約
			$pre_id        = $rrec->id;
			$channel_id    = $rrec->channel_id;
			$title         = $rrec->title;
			$description   = $rrec->description;
			$category_id   = $rrec->category_id;
			$mode          = $rrec->mode;
			$discontinuity = $rrec->discontinuity;
			$priority      = $rrec->priority;
			$rrec->delete();
			reclog( $rev_id.' 録画開始失敗] 再予約を試みます。 '.$rev_ds, EPGREC_WARN );
			try{
				$rval = Reservation::custom(
							$starttime,
							$endtime,
							$channel_id,
							$title,
							$description,
							$category_id,
							$program_id,
							$autorec,
							$mode,
							$discontinuity,
							0,
							$priority
				);
			}
			catch( Exception $e ) {
				if( $autorec == 0 ){
					// 手動予約のトラコン設定削除
					$trans_obj = new DBRecord( TRANSEXPAND_TBL );
					$tran_ex   = $trans_obj->fetch_array( null, null, 'key_id=0 AND type_no='.$pre_id );
					foreach( $tran_ex as $tran_set )
						$trans_obj->force_delete( $tran_set['id'] );
				}
				exit( "Error:".$e->getMessage() );
			}
			if( $autorec == 0 ){
				// 手動予約のトラコン設定の予約ID修正
				$wrt_set = array();
				list( , , $wrt_set['type_no'], ) = explode( ':', $rval );
				$trans_obj = new DBRecord( TRANSEXPAND_TBL );
				$tran_ex   = $trans_obj->fetch_array( null, null, 'key_id=0 AND type_no='.$pre_id );
				foreach( $tran_ex as $tran_set )
					$trans_obj->force_update( $tran_set['id'], $wrt_set );
			}
			exit();
		}
	}
PUT_LOG:
	if( file_exists( $ts_path ) ){
		usleep(10 * 1000);
		$syslog = get_syslog( $get_time );
		if( $syslog !== '' ){
			$rev_ds    = htmlspecialchars( $rev_ds );
			$log_level = EPGREC_WARN;
		}else
			$log_level = E_INFO;
		if( $autorec < 0 )
			$autorec = $autorec * -1 - 1;
		if( $autorec )
			$rev_id  = '<input type="button" value="録画済(ID:'.$autorec.')" onClick="location.href=\'recordedTable.php?key='.$autorec.'\'" style="padding:0;"> '.htmlspecialchars($rev_id);
		// 不具合が出る場合は、以下を入れ替えること
//		if( (int)trim(exec("stat -c %s '".$ts_path."'")) )
		if( filesize( $ts_path ) )
		{
			// 予約完了
			if( $get_time >= toTimestamp($rrec->endtime) ){
				reclog( $rev_id.' 録画完了] '.$rev_ds.$syslog, $log_level );
				$rec_success = TRUE;
			}else{
				if( (int)$rrec->autorec >= 0 ){
					if( storage_free_space( $ts_path ) )
						reclog( $rev_id.' 録画中断] '.$rev_ds.'<br>ソフトウェアもしくは記憶ストレージ・受信チューナーなどハードウェアに異常があります。'.$syslog, EPGREC_ERROR );
					else
						reclog( $rev_id.' 録画中断] '.$rev_ds.'<br>録画ストレージ残容量が0byteです。'.$syslog, EPGREC_ERROR );
					$rec_success = FALSE;
				}else{
					reclog( $rev_id.' 手動中断] '.$rev_ds.$syslog, $log_level );
					$rrec->autorec = $rrec->autorec * -1 - 1;
					$rrec->endtime = toDatetime( $get_time );
					$rec_success   = TRUE;
				}
			}
			$rrec->complete = '1';
			$rrec->update();
			if( $rec_success ){
				// トランスコードJOB追加
				$trans_obj = new DBRecord( TRANSEXPAND_TBL );
				if( $rrec->autorec ){
					$tran_ex = $trans_obj->fetch_array( null, null, 'key_id='.$rrec->autorec.' ORDER BY type_no' );
					foreach( $tran_ex as $tran_set ){
						trans_job_set( $rrec, $tran_set );
						$job_set = TRUE;
					}
				}else{
					// 手動予約用
					$tran_ex = $trans_obj->fetch_array( null, null, 'key_id=0 AND type_no='.$rrec->id );
					foreach( $tran_ex as $tran_set ){
						trans_job_set( $rrec, $tran_set );
						$trans_obj->force_delete( $tran_set['id'] );
						$job_set = TRUE;
					}
				}
				if( $job_set ){
					while(1){
						$sem_id = sem_get_surely( SEM_TRANSCODE );
						if( $sem_id !== FALSE ){
							while(1){
								if( sem_acquire( $sem_id ) === TRUE ){
									$ps_output = shell_exec( PS_CMD );
									$rarr      = explode( "\n", $ps_output );
									do{
										$job_name = INSTALL_PATH.'/trans_manager.php';
										foreach( $rarr as $prs_line ){
											if( strpos( $prs_line, $job_name ) !== FALSE )
												break 2;
										}
										@exec( $job_name.' >/dev/null 2>&1 &' );
									}while(0);
									while( sem_release( $sem_id ) === FALSE )
										usleep( 100 );
									break 2;
								}
								sleep(1);
							}
						}
						sleep(1);
					}
				}
				// mediatomb登録
				if( $settings->mediatomb_update == 1 ){
					// ちょっと待った方が確実っぽい
					@exec('sync');
					sleep(15);
					$dbh = mysqli_connect( $settings->db_host, $settings->db_user, $settings->db_pass, $settings->db_name );
					if( mysqli_connect_errno() === 0 ){
						// 別にやらなくてもいいが
						@mysqli_set_charset( $dbh, 'utf8' );
						$sqlstr = "update mt_cds_object set metadata='dc:description=".mysqli_real_escape_string( $dbh, $rrec->description )."&epgrec:id=".$reserve_id."' where dc_title='".$rrec->path."'";
						@mysqli_query( $dbh, $sqlstr );
						$sqlstr = "update mt_cds_object set dc_title='".mysqli_real_escape_string( $dbh, $rrec->title )."(".date("Y/m/d").")' where dc_title='".$rrec->path."'";
						@mysqli_query( $dbh, $sqlstr );
					}
				}
			}
		}else{
			if( storage_free_space( $ts_path ) )
				reclog( $rev_id.' 録画失敗] '.$rev_ds.'<br>録画ファイルサイズが0byteです。ソフトウェアもしくは記憶ストレージ・受信チューナーなどハードウェアに異常があります。'.$syslog, EPGREC_ERROR );
			else
				reclog( $rev_id.' 録画失敗] '.$rev_ds.'<br>録画ストレージ残容量が0byteです。'.$syslog, EPGREC_ERROR );
			$rrec->delete();
		}
	}
	else {
		// 予約実行失敗
		reclog( $rev_id.' 録画失敗] 録画ファイルが存在しません。'.$rev_ds, EPGREC_ERROR );
		$rrec->delete();
	}
}
catch( exception $e ) {
	reclog( 'recomplete:: 予約テーブルのアクセスに失敗した模様('.$e->getMessage().')', EPGREC_ERROR );
	exit( $e->getMessage() );
}
// 省電力
if( !$job_set )
	power_reduce( RESERVE );

exit();
?>
