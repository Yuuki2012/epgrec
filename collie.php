#!/usr/bin/php
<?php
	$script_path = dirname( __FILE__ );
	chdir( $script_path );
	include_once( $script_path . '/config.php');
	include_once( INSTALL_PATH . '/DBRecord.class.php' );
	include_once( INSTALL_PATH . '/Settings.class.php' );
	include_once( INSTALL_PATH . '/reclib.php' );
	include_once( INSTALL_PATH . '/recLog.inc.php' );

function sheep_release( $cmd ) {
	$descspec = array(
					0 => array( 'file','/dev/null','r' ),
					1 => array( 'file','/dev/null','w' ),
					2 => array( 'file','/dev/null','w' ),
	);
	$pro = proc_open( $cmd, $descspec, $pipes );
	if( is_resource( $pro ) )
		return $pro;
	return false;
}

function create_sql_time( $tmp_time ) {
	global	$settings;

	return ' AND endtime>subtime( now(), sec_to_time('.($settings->extra_time+2).') ) AND starttime<addtime( now(), sec_to_time('.$tmp_time.') )';
}

function rest_check( $ch_disk, $sql_time ){
	global $pro_obj,$settings;

	$now_tm    = time();
	$rec_start = toDatetime( $now_tm );
	$rec_end   = toDatetime( $now_tm+$sql_time );
	$pro_sql   = 'channel LIKE "'.$ch_disk.'" AND endtime>"'.toDatetime( $now_tm-($settings->extra_time+2)).'" AND starttime<"'.$rec_end.'"';
	$pro_list  = $pro_obj->fetch_array( null, null, $pro_sql.
				' AND title NOT LIKE "%放送%休止%" AND title NOT LIKE "%放送設備%" AND title NOT LIKE "%試験放送%" AND title NOT LIKE "%メンテナンス%" ORDER BY channel_disc, starttime' );
	if( count($pro_list) == 0 )
		return DBRecord::countRecords( PROGRAM_TBL, 'WHERE '.$pro_sql )===0 ? FALSE : TRUE;		//初回起動:停波中
	$chk_disc  = '';
	$rec_joint = '';
	foreach( $pro_list as $event ){
		if( $chk_disc === $event['channel_disc'] ){
			if( $rec_joint === $event['starttime'] ){
				$rec_joint = $event['endtime'];
				if( $rec_end <= $rec_joint )
					return FALSE;		//放送中
			}else
				$rec_joint = '';
		}else{
			$chk_disc = $event['channel_disc'];
			if( $event['starttime'] <= $rec_start ){
				$rec_joint = $event['endtime'];
				if( $rec_end <= $rec_joint )
					return FALSE;		//放送中
			}else
				$rec_joint = '';
		}
	}
	return TRUE;			//停波中
}

	$settings      = Settings::factory();
	$tuners        = (int)$settings->bs_tuners;
	$usable_tuners = (int)$argv[1];

// 衛星波を処理する
if( $usable_tuners !== 0 ){
	$smf_type  = 'BS';
	$type      = array( 'BS', 'CS', 'CS' );
	$rec_time  = array( 220, 240, 180 );
	// 'BS17_0','BS17_1'は、難視聴なので削除
	$ch_list   = array(
					array( BS_EPG_CHANNEL, 'BS15_0','BS15_1','BS1_0','BS1_1','BS3_0','BS3_1','BS5_0','BS5_1','BS7_0','BS7_1','BS7_2','BS9_0','BS9_1','BS9_2',
							'BS11_0','BS11_1','BS11_2','BS13_0','BS13_1','BS19_0','BS19_1','BS19_2','BS21_0','BS21_1','BS21_2','BS23_0','BS23_1','BS23_2' ),
					array( CS2_EPG_CHANNEL, 'CS4','CS6','CS12','CS14','CS16','CS18','CS20','CS22','CS24' ),
					array( CS1_EPG_CHANNEL, 'CS2','CS8','CS10' )
				);
	$sheep_lmt = $settings->cs_rec_flg==0 ? 1 : 3;
	$add_time  = $settings->rec_switch_time + 2;
	for( $sem_cnt=0; $sem_cnt<$tuners; $sem_cnt++ ){
		$sem_id[$sem_cnt] = sem_get_surely( $sem_cnt+SEM_ST_START );
		if( $sem_id[$sem_cnt] === FALSE )
			exit;
	}
	$shm_id   = shmop_open_surely();
	$sql_base = 'complete=0 AND (type="BS" OR type="CS")';
	$loop_tim = 10;
	$key      = 0;
	$use_cnt  = 0;
	$end_flag = FALSE;
	$pro_cnt  = 0;
	$pro      = array();
	$pro_obj  = new DBRecord( PROGRAM_TBL );
	$res_obj  = new DBRecord( RESERVE_TBL );
	do{
		if( !$end_flag ){
			$sql_time = $rec_time[$key] + $add_time;
			$sql_cmd  = $sql_base.create_sql_time( $rec_time[$key] + $add_time*2 + $settings->former_time + $loop_tim );
			$sql_chk  = $sql_base.' AND starttime>now() AND starttime<addtime( now(), sec_to_time('.( $rec_time[$key]+$add_time + PADDING_TIME ).') )';
			if( $use_cnt < $usable_tuners ){
				// 録画重複チェック
				$revs       = $res_obj->fetch_array( null, null, $sql_cmd );
				$off_tuners = count( $revs );
				if( $off_tuners+$use_cnt < $tuners ){
					$lp_st = time();
					do{
						//空チューナー降順探索
						for( $slc_tuner=$tuners-1; $slc_tuner>=0; $slc_tuner-- ){
							for( $cnt=0; $cnt<$off_tuners; $cnt++ ){
								if( $revs[$cnt]['tuner'] == $slc_tuner )
									continue 2;
							}
							if( sem_acquire( $sem_id[$slc_tuner] ) === TRUE ){
								$shm_name = $slc_tuner + SEM_ST_START;
								$smph     = shmop_read_surely( $shm_id, $shm_name );
								if( $smph==2 && $tuners-$off_tuners===1 ){
									// リアルタイム視聴停止
									$real_view = (int)trim( file_get_contents( REALVIEW_PID ) );
									posix_kill( $real_view, 9 );		// 録画コマンド停止
									$smph = 0;
									shmop_write_surely( $shm_id, SEM_REALVIEW, 0 );		// リアルタイム視聴tunerNo clear
								}
								if( $smph == 0 ){
									shmop_write_surely( $shm_id, $shm_name, 1 );
									while( sem_release( $sem_id[$slc_tuner] ) === FALSE )
										usleep( 100 );

									$rr = $res_obj->fetch_array( null, null, $sql_chk );
									if( count( $rr ) > 0 ){
										$motion = TRUE;
										if( $slc_tuner < TUNER_UNIT1 ){
											foreach( $rr as $rev ){
												if( $rev['tuner'] < TUNER_UNIT1 ){
													$motion = FALSE;
													break;
												}
											}
										}else{
											foreach( $rr as $rev ){
												if( $rev['tuner'] >= TUNER_UNIT1 ){
													$motion = FALSE;
													break;
												}
											}
										}
									}else
										$motion = TRUE;

									if( $motion ){
										// 停波確認と受信CH更新
										while(1){
											if( list( $ch_disk, $value ) = each( $ch_list[$key] ) ){
												if( !rest_check( $value, $sql_time ) )
													break;
											}else
												if( ++$key < $sheep_lmt ){
													/* いらんよね
													if( $rec_time[$key-1] > $rec_time[$key] )
														continue;
													else{
														shmop_write_surely( $shm_id, $shm_name, 0 );
														continue 4;
													}
													*/
												}else{
													shmop_write_surely( $shm_id, $shm_name, 0 );
													$end_flag = TRUE;
													goto GATHER_SHEEPS;		// 終了
												}
										}

										$cmdline = INSTALL_PATH.'/airwavesSheep.php '.$type[$key].' '.$slc_tuner.' '.$value.' '.$rec_time[$key].' '.$ch_disk;	// $ch_disk is dummy
/*										if( $key==2 && $usable_tuners==3 && time()-$st_time<10 )
											$cmdline .= ' 120';
										else
*/
											$cmdline .= ' 0';
										// 除外sid抽出
										$map      = $key==0 ? $BS_CHANNEL_MAP : $CS_CHANNEL_MAP;
										$cut_sids = array();
										$cnt      = 0;
										$nc_keys  = array_keys( $map, 'NC' );
										if( $nc_keys !== FALSE ){
											foreach( $nc_keys as $th_ch ){
												$tg_sid           = explode( '_', $th_ch );
												$cut_sids[$cnt++] = (string)$tg_sid[1];
											}
										}
										if( !HIDE_CH_EPG_GET ){
											$chs_obj = new DBRecord( CHANNEL_TBL );
											$cuts    = $chs_obj->fetch_array( null, null, 'skip=1 AND type="'.$type[$key].'"' );
											$hit     = count( $cuts ) + $cnt;
											if( $hit > $cnt ){
												foreach( $cuts as $cut_ch ){
													if( in_array( (string)$cut_ch['sid'], $cut_sids ) === FALSE )
														$cut_sids[$cnt++] = (string)$cut_ch['sid'];
												}
											}
										}
										if( $hit > 0 )
											$cmdline .= ' '.implode( ',', $cut_sids );

										$rec_pro = sheep_release( $cmdline );
										if( $rec_pro !== FALSE )
											$pro[] = $rec_pro;
										else{
											shmop_write_surely( $shm_id, $shm_name, 0 );
											reclog( 'collie.php::コマンドに異常がある可能性があります<br>'.$cmdline, EPGREC_WARN );
											$end_flag = TRUE;
											goto GATHER_SHEEPS;		// 終了
										}
										$use_cnt++;

										if( ++$key < $sheep_lmt )
											continue 3;
										else{
											$end_flag = TRUE;
											goto GATHER_SHEEPS;		// 終了
										}
									}else
										shmop_write_surely( $shm_id, $shm_name, 0 );
								}else
									//占有失敗
									while( sem_release( $sem_id[$slc_tuner] ) === FALSE )
										usleep( 100 );
							}
						}
						sleep(1);
					}while( time()-$lp_st < $loop_tim );
					//時間切れ
				}else{
					//空チューナー無し
					//先行録画が同ChならそこからEPGを貰うようにしたい
					if( $off_tuners >= $tuners ){
						$end_flag = TRUE;
						goto GATHER_SHEEPS;		// 終了
					}
					sleep(1);
				}
			}
			//チューナー空き確認
			$use = 0;
			for( $tune_cnt=0; $tune_cnt<$tuners; $tune_cnt++ )
				if( shmop_read_surely( $shm_id, $tune_cnt+SEM_ST_START ) )
					$use++;
			if( $use_cnt > $use )
				$use_cnt = $use;
		}else
			sleep(1);
GATHER_SHEEPS:
		//全子プロセス(EPG受信・更新)終了待ち
		$pro_cnt = count($pro);
		if( $pro_cnt ){
			$cnt = 0;
			do{
				if( $pro[$cnt] !== FALSE ){
					$st = proc_get_status( $pro[$cnt] );
					if( $st['running'] == FALSE ){
						proc_close( $pro[$cnt] );
						array_splice( $pro, $cnt, 1 );
						$pro_cnt--;
					}else
						$cnt++;
				}else{
					array_splice( $pro, $cnt, 1 );
					$pro_cnt--;
				}
			}while( $cnt < $pro_cnt );
		}
	}while( !$end_flag || $pro_cnt );
	shmop_close( $shm_id );
}
	exit();
?>
