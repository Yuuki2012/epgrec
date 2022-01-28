<?php
// 省電力
define( 'WAKEUP_VARS', INSTALL_PATH.'/settings/wakeupvars.xml' );
define( 'RESERVE', 'reserve' );
define( 'GETEPG', 'getepg' );
define( 'OTHER', 'other' );
define( 'REPAIREPG', 'repairEpg' );		// 
define( 'STAY', 'stay' );		// 間欠運用の一時停止
define( 'RESUME', 'resume' );	// 間欠運用の復帰
define( 'FORCE', 'force' );		// 間欠運用の強制復帰


function power_settings(){
	if( file_exists( WAKEUP_VARS ) ){
		$wakeupvars_text = file_get_contents( WAKEUP_VARS );
		$wakeupvars      = new SimpleXMLElement( $wakeupvars_text );
	}else{
		$wakeupvars_text = '<?xml version="1.0" encoding="UTF-8" ?><epgwakeup></epgwakeup>';
		$wakeupvars      = new SimpleXMLElement( $wakeupvars_text );
		if(count($wakeupvars->getepg_time) == 0)
			$wakeupvars->getepg_time = 0;
		$wakeupvars->reason = OTHER;
	}
	return $wakeupvars;
}


function power_reduce( $mode, $epg_st_tm=0 ){
	global $settings;

	if( $mode===GETEPG || intval($settings->use_power_reduce)!=0 ){
		// 占有
		while(1){
			$sem_id = sem_get_surely( SEM_PW_REDUCE );
			if( $sem_id !== FALSE ){
				while(1){
					if( sem_acquire( $sem_id ) === TRUE ){
						break 2;
					}
					sleep(1);
				}
			}
			sleep(1);
		}

		$wakeupvars = power_settings();
		if( $mode===STAY || $mode===REPAIREPG ){
			// 間欠運用の一時停止
			exec( 'sudo '.$settings->shutdown.' -c >/dev/null 2>&1' );
			$wrt_name = $mode===REPAIREPG ? REPAIREPG : OTHER;
			if($wakeupvars->reason !== $wrt_name ){
				$wakeupvars->reason = $wrt_name;
				$wakeupvars->asXML( WAKEUP_VARS );
			}
			while( sem_release( $sem_id ) === FALSE )
				usleep( 100 );
		}else{
			// 指定時間以内に録画があるか
			$next_t = toDatetime( time() + ((int)$settings->rec_after)*60 );
			$count  = DBRecord::countRecords( RESERVE_TBL, 'WHERE complete=0 AND starttime<="'.$next_t.'" AND endtime>now()' );
			// トランスコード
			$count += DBRecord::countRecords( TRANSCODE_TBL, 'WHERE status IN (0,1)' );
			// リアルタイム視聴
			$shm_id = shmop_open_surely();
			if( shmop_read_surely( $shm_id, SEM_REALVIEW ) != 0 ){
				$realtime = TRUE;
				$count++;
			}else
				$realtime = FALSE;
			shmop_close( $shm_id );
			// repairEpg.php
			$ps_output = shell_exec( PS_CMD.' 2>/dev/null' );
			$rarr      = explode( "\n", $ps_output );
			$my_pid    = posix_getpid();
			$repairEpg = FALSE;
			for( $cc=0; $cc<count($rarr); $cc++ ){
				if( strpos( $rarr[$cc], 'repairEpg.php' ) !== FALSE ){
					$ps = ps_tok( $rarr[$cc] );
					if( $my_pid !== (int)$ps->pid ){
						$repairEpg = TRUE;
						$count++;
						break;
					}
				}
			}
			// 起動理由・動作指示を調べる
			switch( $mode ){
				case GETEPG:
					// EPG更新終了時を書込み
					$epg_st_tm               = (int)($epg_st_tm/60) * 60;
					$wakeupvars->getepg_time = $epg_st_tm;
					$wakeupvars->getepg_date = toDatetime( $epg_st_tm );
					if( intval($settings->use_power_reduce) !== 0 ){
						// 録画・トランスコード・リアルタイム視聴があるなら電源を切らない
						if( $count === 0 ){
							if( strcasecmp( OTHER, $wakeupvars->reason ) !== 0 ){
								$wakeupvars->asXML( WAKEUP_VARS );
								break;
							}
						}else
							$wakeupvars->reason = $realtime ? OTHER : ( $repairEpg ? REPAIREPG : RESERVE );
					}
					$wakeupvars->asXML( WAKEUP_VARS );
					while( sem_release( $sem_id ) === FALSE )
						usleep( 100 );
					return;
					break;
				case FORCE:	// 間欠運用の強制再開
					if( $repairEpg ){
						$repairEpg = FALSE;
						$count--;
					}
				case RESUME:	// 間欠運用の再開
				case RESERVE:
					$chg_reason = FALSE;
					$stk_reason = $wakeupvars->reason;
					// 録画・トランスコード終了時
					if( $realtime ){	// リアルタイム視聴
						$wakeupvars->reason = OTHER;
						$chg_reason         = TRUE;
					}else
						if( $repairEpg ){	// repairEpg.php
							$wakeupvars->reason = REPAIREPG;
							$chg_reason         = TRUE;
						}else
							if( $count > 0 ){	// 録画・トランスコードがあるか
								$wakeupvars->reason = RESERVE;
								$chg_reason         = TRUE;
							}else
								// 次のgetepgの時間は？
								if( intval($wakeupvars->getepg_time) === 0 ){
									// epgwakealarm.phpでやる
								}else{
									$next_getepg_time = intval($wakeupvars->getepg_time);
									do{
										$next_getepg_time += intval($settings->getepg_timer) * 3600;
									}while( $next_getepg_time < time() );
									if( $next_getepg_time - time() <= (int)$settings->rec_after*60 ){
										$wakeupvars->reason = GETEPG;
										$chg_reason         = TRUE;
									}else
										if( $mode===RESERVE && $wakeupvars->reason===OTHER )
											$chg_reason = TRUE;
								}
					if( $chg_reason ){
						if( $stk_reason !== $wakeupvars->reason )
							$wakeupvars->asXML( WAKEUP_VARS );
						while( sem_release( $sem_id ) === FALSE )
							usleep( 100 );
						return;
					}
					break;
				default:
					while( sem_release( $sem_id ) === FALSE )
						usleep( 100 );
					return;
			}
			while( sem_release( $sem_id ) === FALSE )
				usleep( 100 );
			@exec( 'sudo '.$settings->shutdown.' -h +1 >/dev/null 2>&1 &' );
		}
	}
}
?>
