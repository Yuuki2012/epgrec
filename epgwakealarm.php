#!/usr/bin/php
<?php
$script_path = dirname( __FILE__ );
chdir( $script_path );
include( $script_path . '/config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/recLog.inc.php' );
include( INSTALL_PATH . '/powerReduce.inc.php' );

$acpi_timer_path = '/sys/class/rtc/rtc0/wakealarm';	// ここは書き換える必要があるPCがあるかもしれない

$settings = Settings::factory();

$wakeupvars = power_settings();
if( isset( $argv[1] ) ){
	if( strcasecmp( $argv[1], 'start' ) == 0 ){
		if( intval($settings->use_power_reduce) != 0 ){
			try {
				// 規定時間以内に予約はあるか
				$count = DBRecord::countRecords( RESERVE_TBL, 'WHERE complete=0 AND starttime>=now() AND starttime<=addtime(now(),sec_to_time('.((int)$settings->wakeup_before*60+PADDING_TIME+2).'))' );
			}catch( Exception $e ){
				$count = 0;
			}
			if( $count > 0 ){
				$wakeupvars->reason = RESERVE;
			}else
				if( intval($wakeupvars->getepg_time) + intval($settings->getepg_timer) * 3600 - time() <= (int)$settings->wakeup_before*60 ){
					$wakeupvars->reason = GETEPG;
// cronにおまかせ
//					@exec( INSTALL_PATH.'/shepherd.php >/dev/null 2>&1 &' );
				}else
					$wakeupvars->reason = OTHER;
		}else
			$wakeupvars->reason = OTHER;
		$wakeupvars->asXML( WAKEUP_VARS );
		chmod( WAKEUP_VARS, 0666 );
		exit();
	}else
		if( strcasecmp( $argv[1], 'stop' ) == 0 ){
			if( intval($settings->use_power_reduce) != 0 ){
				try{
					// 録画中はないか？
					$rec_start = toDatetime( time() + ((int)$settings->wakeup_before+1)*60 );
					$count = DBRecord::countRecords( RESERVE_TBL, ' WHERE complete=0 AND starttime<"'.$rec_start.'" AND endtime>now()' );
					if( $count != 0 ){
						// シャットダウン中止を試みる
						exec( 'sudo '.$settings->shutdown.' -c' );
						reclog( '録画中または予約録画開始'.$settings->wakeup_before.'分以内にシャットダウンが実行された', EPGREC_WARN );
						exit();
					}

					// 次の予約録画の開始時刻は？
					$nextreserves = DBRecord::createRecords( RESERVE_TBL, ' WHERE complete=0 AND starttime>="'.$rec_start.'" ORDER BY starttime LIMIT 10' );
					$next_rectime = 0;
					foreach( $nextreserves as $reserve ){
						$next_rectime = toTimestamp($reserve->starttime) - 60 * intval($settings->wakeup_before);
						break;
					}

					// 次のgetepgの時間は？
					if( intval($wakeupvars->getepg_time) == 0 ){
						$next_getepg_time = (int)(time()/60) * 60 + intval($settings->getepg_timer) * 3600;	// 現在から設定時間後
					}else{
						$next_getepg_time = intval($wakeupvars->getepg_time) - (int)$settings->wakeup_before*60;
						do{
							$next_getepg_time += intval($settings->getepg_timer) * 3600;
						}while( $next_getepg_time < time() );
					}

					if( $next_rectime===0 || $next_getepg_time<$next_rectime )
						$waketime = $next_getepg_time;
					else
						$waketime = $next_rectime;

					// いったんリセットする
					$fp = fopen( $acpi_timer_path, 'w' );
					if( $fp === FALSE ){
						exec( 'sudo '.$settings->shutdown.' -c' );
						reclog( 'epgwakealarm.php:: file open error('.$acpi_timer_path.'). stop shutdown!!', EPGREC_ERROR );
						exit();
					}
					if( fwrite($fp , '0') === FALSE ){
						exec( 'sudo '.$settings->shutdown.' -c' );
						reclog( 'epgwakealarm.php:: file write error('.$acpi_timer_path.'). stop shutdown!!', EPGREC_ERROR );
						fclose($fp);
						exit();
					}
					fclose($fp);

					$fp = fopen( $acpi_timer_path, 'w' );
					fwrite($fp , (string)($waketime) );
					fclose($fp);
					reclog( '次起動時刻 '.toDatetime($waketime).'('.$waketime.')', EPGREC_DEBUG );
				}catch( Exception $e ){
					exec( 'sudo '.$settings->shutdown.' -c' );
					reclog( 'epgwakealarm.php:: '.$e, EPGREC_ERROR );
				}
				exit();
			}
		}
}else
	if( isset( $_GET['mode'] ) ){
		power_reduce( $_GET['mode'] );
		echo '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body onLoad="location.href = document.referrer;"></body></html>';
		exit();
	}
?>
