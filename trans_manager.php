#!/usr/bin/php
<?php
$script_path = dirname( __FILE__ );
chdir( $script_path );
include_once( $script_path . '/config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/recLog.inc.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include( INSTALL_PATH . '/powerReduce.inc.php' );

// SIGTERMシグナル
function handler( $signo = 0 ) {
	exit();
}

// デーモン化
function daemon() {
	if( pcntl_fork() != 0 )
		exit();
	posix_setsid();
	if( pcntl_fork() != 0 )
		exit;
	declare( ticks = 1 );
	pcntl_signal(SIGTERM, 'handler');
}


run_user_regulate();
// デーモン化
daemon();
// プライオリティ低に
pcntl_setpriority(20);

$trans_obj = new DBRecord( TRANSCODE_TBL );
$res_obj   = new DBRecord( RESERVE_TBL );

$settings = Settings::factory();
$trans_stack = array();
$wait_time   = 1;
while(1){
	$transing_cnt = DBRecord::countRecords( TRANSCODE_TBL, 'WHERE status=1' );
	if( $transing_cnt && count( $trans_stack )==0 ){
		// 中断ジョブのやり直し
		$wrt_set = array();
		$wrt_set['status'] = 0;
		$stack_trans = $trans_obj->fetch_array( 'status', 1 );
		foreach( $stack_trans as $tran_job ){
			$trans_obj->force_update( $tran_job['id'], $wrt_set );
		}
		$transing_cnt = 0;
	}
	if( $transing_cnt < TRANS_PARA ){
		$pending_trans = $trans_obj->fetch_array( null, null, 'status=0 ORDER BY rec_endtime, rec_id' );
		if( count( $pending_trans ) ){
			$tran_start       = $pending_trans[0];
			$sauce_ts         = $res_obj->fetch_array( 'id', $tran_start['rec_id'] );
			$tran_start['hd'] = '[予約ID:'.$tran_start['rec_id'].' トランスコード';
			$tran_start['tl'] = '['.$RECORD_MODE[$tran_start['mode']]['name'].'(mode'.$tran_start['mode'].')]] '.
					$sauce_ts[0]['channel_disc'].'(T'.$sauce_ts[0]['tuner'].'-'.$sauce_ts[0]['channel'].') '.$sauce_ts[0]['starttime'].' 『'.$sauce_ts[0]['title'].'』';
			if( storage_free_space( $tran_start['path'] ) == 0 ){
				reclog( $tran_start['hd'].'開始失敗'.$tran_start['tl'].' ストレージ残容量が0byteです。', EPGREC_ERROR );
				$wrt_set = array();
				$wrt_set['status'] = 3;
				$wrt_set['enc_starttime'] = $wrt_set['enc_endtime'] = toDatetime(time());
				$trans_obj->force_update( $tran_start['id'], $wrt_set );
				continue;
			}
			// 
			$tran_start['ts'] = $sauce_ts[0]['path'];
			$trans      = array('%FFMPEG%' => $settings->ffmpeg,
								'%TS%'     => '\''.$tran_start['ts'].'\'',
								'%TRANS%'  => '\''.$tran_start['path'].'\'',
								'%FORMAT%' => $RECORD_MODE[$tran_start['mode']]['format'],
								'%VIDEO%'  => $RECORD_MODE[$tran_start['mode']]['video'],
								'%VBRATE%' => $RECORD_MODE[$tran_start['mode']]['vbrate'],
								'%FPS%'    => $RECORD_MODE[$tran_start['mode']]['fps'],
								'%ASPECT%' => $RECORD_MODE[$tran_start['mode']]['aspect'],
								'%SIZE%'   => $RECORD_MODE[$tran_start['mode']]['size'],
								'%AUDIO%'  => $RECORD_MODE[$tran_start['mode']]['audio'],
								'%ABRATE%' => $RECORD_MODE[$tran_start['mode']]['abrate'],
							);
			if( $RECORD_MODE[$tran_start['mode']]['command'] === '' ){
				$cmd_set               = strtr( TRANS_CMD, $trans );
				$tran_start['succode'] = TRANS_SUCCESS_CODE;
			}else{
				$cmd_set               = strtr( $RECORD_MODE[$tran_start['mode']]['command'], $trans );
				$tran_start['succode'] = $RECORD_MODE[$tran_start['mode']]['succode']===TRUE ? TRANS_SUCCESS_CODE : $RECORD_MODE[$tran_start['mode']]['succode'];
			}
			$descspec = array(
							0 => array( 'file','/dev/null','r' ),
							1 => array( 'file','/dev/null','w' ),
							2 => array( 'file','/dev/null','w' ),
			);
			$tran_start['pro'] = proc_open( $cmd_set, $descspec, $pipes, INSTALL_PATH.$settings->spool );
			if( is_resource( $tran_start['pro'] ) ){
				reclog( $tran_start['hd'].'開始'.$tran_start['tl'] );
				$wrt_set = array();
				$wrt_set['enc_starttime'] = toDatetime(time());
				$wrt_set['name']          = $RECORD_MODE[$tran_start['mode']]['name'];
				$wrt_set['status']        = 1;
				$st                       = proc_get_status( $tran_start['pro'] );
				$wrt_set['pid']           = $st['pid'];
				$trans_obj->force_update( $tran_start['id'], $wrt_set );

				$tran_start['title'] = $sauce_ts[0]['title'];
				$tran_start['desc']  = $sauce_ts[0]['description'];
				if( $RECORD_MODE[$tran_start['mode']]['tm_rate'] > 0 )
					$tran_start['tm_lmt'] = time() + (int)(( toTimestamp( $sauce_ts[0]['endtime'] ) - toTimestamp( $sauce_ts[0]['starttime'] ) ) * $RECORD_MODE[$tran_start['mode']]['tm_rate'] );
				else
					$tran_start['tm_lmt'] = 0;		// 監視無効
				array_push( $trans_stack, $tran_start );
				$transing_cnt++;
			}else{
				reclog( $tran_start['hd'].'開始失敗'.$tran_start['tl'].' コマンドに異常がある可能性があります', EPGREC_ERROR );
				$wrt_set = array();
				$wrt_set['status'] = 3;
				$wrt_set['enc_starttime'] = $wrt_set['enc_endtime'] = toDatetime(time());
				$trans_obj->force_update( $tran_start['id'], $wrt_set );
			}
			continue;
		}
	}
	if( $transing_cnt ){
		$key = 0;
		do{
			if( $trans_stack[$key]['pro'] !== FALSE ){
				$st = proc_get_status( $trans_stack[$key]['pro'] );
				if( $st['running'] == FALSE ){
					// トランスコード終了処理
					proc_close( $trans_stack[$key]['pro'] );
					$wrt_set = array();
					$wrt_set['enc_endtime'] = toDatetime(time());
					if( file_exists( $trans_stack[$key]['path'] ) ){
						// 不具合が出る場合は、以下を入れ替えること
//						if( (int)trim(exec("stat -c %s '".$trans_stack[$key]['path']."'")) )
						if( filesize($trans_stack[$key]['path']) )
							$wrt_set['status'] = (!$trans_stack[$key]['succode'] || $st['exitcode']===$trans_stack[$key]['succode']) ? 2 : 3;	// FFmpegの終了値で成否を判断
						else
							$wrt_set['status'] = 3;
					}else
						$wrt_set['status'] = 3;
					$trans_obj->force_update( $trans_stack[$key]['id'], $wrt_set );
					if( $wrt_set['status'] == 2 ){
						reclog( $trans_stack[$key]['hd'].'終了(code='.$st['exitcode'].')'.$trans_stack[$key]['tl'] );
						if( $trans_stack[$key]['ts_del'] && DBRecord::countRecords( TRANSCODE_TBL, 'WHERE rec_id='.$trans_stack[$key]['rec_id'].' AND status IN (0,1,3)' )==0 ){
							// 元TSのファイルとパスの削除
							@unlink( INSTALL_PATH.$settings->spool.'/'.$trans_stack[$key]['ts'] );
//							$wrt_set = array();
//							$wrt_set['path'] = '';
//							$res_obj->force_update( $trans_stack[$key]['rec_id'], $wrt_set );
						}
						// mediatomb登録
						if( $settings->mediatomb_update == 1 ) {
							// ちょっと待った方が確実っぽい
							@exec('sync');
							sleep(15);
							$dbh = mysqli_connect( $settings->db_host, $settings->db_user, $settings->db_pass, $settings->db_name );
							if( mysqli_connect_errno() === 0 ){
								// 別にやらなくてもいいが
								@mysqli_set_charset( $dbh, 'utf8' );
								$sqlstr = "update mt_cds_object set metadata='dc:description=".mysqli_real_escape_string( $dbh, $tran_start['desc'] ).
															"&epgrec:id=".$trans_stack[$key]['rec_id']."' where dc_title='".$trans_stack[$key]['path']."'";
								@mysqli_query( $dbh, $sqlstr );
								$sqlstr = "update mt_cds_object set dc_title='[".$RECORD_MODE[$trans_stack[$key]['mode']]['name'].']'.
											mysqli_real_escape_string( $dbh, $tran_start['title'] )."(".date('Y/m/d').")' where dc_title='".$trans_stack[$key]['path']."'";
								@mysqli_query( $dbh, $sqlstr );
							}
						}
					}else
						reclog( $trans_stack[$key]['hd'].'失敗(code='.$st['exitcode'].')'.$trans_stack[$key]['tl'], EPGREC_WARN );
					array_splice( $trans_stack, $key, 1 );
					continue 2;
				}else{
					if( $trans_stack[$key]['tm_lmt']>0 && time()>=$trans_stack[$key]['tm_lmt'] ){
						// time out
						proc_terminate( $trans_stack[$key]['pro'], 9 );
						$wrt_set = array();
						$wrt_set['enc_endtime'] = toDatetime(time());
						$wrt_set['status']      = 3;
						$trans_obj->force_update( $trans_stack[$key]['id'], $wrt_set );
						reclog( $trans_stack[$key]['hd'].'失敗(タイムアウト)'.$trans_stack[$key]['tl'], EPGREC_WARN );
						array_splice( $trans_stack, $key, 1 );
						continue 2;
					}else
						$key++;
				}
			}else
				array_splice( $trans_stack, $key, 1 );
		}while( $key < count($trans_stack) );
	}else{
		// 省電力
		power_reduce( RESERVE );

		exit();
	}
	sleep( $wait_time );
}
?>
