<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Smarty/Smarty.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );

$week_tb = array( '日', '月', '火', '水', '木', '金', '土' );


$page      = 1;
$full_mode = FALSE;

try{
	$res_obj = new DBRecord( RESERVE_TBL );
	$rvs     = $res_obj->fetch_array( null, null, 'complete=0 ORDER BY starttime ASC' );
	$res_cnt = count( $rvs );

	if( ( SEPARATE_RECORDS_RESERVE===FALSE && SEPARATE_RECORDS<1 ) || ( SEPARATE_RECORDS_RESERVE!==FALSE && SEPARATE_RECORDS_RESERVE<1 ) )	// "<1"にしているのはフェイルセーフ
		$full_mode = TRUE;
	else{
		if( isset( $_GET['page']) ){
			if( $_GET['page'] === '-' )
				$full_mode = TRUE;
			else
				$page = (int)$_GET['page'];
		}
		$separate_records = SEPARATE_RECORDS_RESERVE!==FALSE ? SEPARATE_RECORDS_RESERVE : SEPARATE_RECORDS;
		$view_overload    = VIEW_OVERLOAD_RESERVE!==FALSE ? VIEW_OVERLOAD_RESERVE : VIEW_OVERLOAD;
		if( $res_cnt <= $separate_records+$view_overload )
			$full_mode = TRUE;
	}

	if( $full_mode ){
		$start_record = 0;
		$end_record   = $res_cnt;
	}else{
		$start_record = ( $page - 1 ) * $separate_records;
		$end_record   = $page * $separate_records;
	}

	$settings     = Settings::factory();
	$reservations = array();
	$ch_name      = array();
	$ch_disc      = array();
	foreach( $rvs as $key => $r ){
		$arr = array();
		$end_time_chk = $end_time = toTimestamp($r['endtime']);
		if( !(boolean)$r['shortened'] )
			$end_time_chk += $settings->extra_time + 5;	// 誤判定防止のため多目にした方が良いかな
		if( $end_time_chk < time() ){
			switch( at_clean( $r, $settings ) ){
				case 0:
					// 予約終了化(録画済一覧に終了状態を出すようにしたいね)
					$wrt_set['complete'] = 1;
					$res_obj->force_update( $r['id'], $wrt_set );
					continue;
				case 1:	// トランスコード中
					$arr['status'] = 1;
					break;
				case 2:	// 別ユーザーでAT登録
					$arr['status'] = 2;
					break;
			}
		}else
			$arr['status'] = 0;
		if( $start_record<=$key && $key<$end_record ){
			if( $r['program_id'] ){
				try{
					$prg = new DBRecord( PROGRAM_TBL, 'id', $r['program_id'] );
					$sub_genre = $prg->sub_genre;
				}catch( exception $e ) {
					reclog( 'reservationTable.php::予約ID:'.$r['id'].'  '.$e->getMessage(), EPGREC_ERROR );
					$sub_genre = 16;
				}
			}else
				$sub_genre = 16;
			$arr['id']      = $r['id'];
			$arr['type']    = $r['type'];
			$arr['tuner']   = $r['tuner'];
			$arr['channel'] = $r['channel'];
			if( !isset( $ch_name[$r['channel_id']] ) ){
				$ch                        = new DBRecord( CHANNEL_TBL, 'id', $r['channel_id'] );
				$ch_name[$r['channel_id']] = $ch->name;
				$ch_disc[$r['channel_id']] = $ch->channel_disc;
			}
			$start_time          = toTimestamp($r['starttime']);
			$arr['date']         = date( 'm/d(', $start_time ).$week_tb[date( 'w', $start_time )].')';
			$arr['starttime']    = date( 'H:i:s-', $start_time );
			$arr['endtime']      = !$r['shortened'] ? date( 'H:i:s', $end_time ) : '<font color="#0000ff">'.date( 'H:i:s', $end_time ).'</font>';
			$arr['duration']     = date( 'H:i:s', $end_time-$start_time-9*60*60 );
			$arr['prg_top']      = date( 'YmdH', ((int)$start_time/60)%60 ? $start_time : $start_time-60*60*1 );
			$arr['channel_name'] = '<a href="index.php?ch='.$ch_disc[$r['channel_id']].'&time='.$arr['prg_top'].'" title="単局EPG番組表へジャンプ">'.$ch_name[$r['channel_id']].'</a>';
			$arr['mode']         = $RECORD_MODE[$r['mode']]['name'];
			$arr['title']        = $r['title'];
			$arr['description']  = $r['description'];
			$arr['cat']          = $r['category_id'];
			$arr['autorec']      = $r['autorec'] ? $r['autorec'] : '□';
			$arr['keyword']      = putProgramHtml( $arr['title'], $r['type'], $r['channel_id'], $r['category_id'], $sub_genre );
			array_push( $reservations, $arr );
		}
	}


	$smarty = new Smarty();
	$smarty->assign( 'sitetitle','録画予約一覧');
	$smarty->assign( 'reservations', $reservations );
	$smarty->assign( 'spool_freesize', spool_freesize() );
	$smarty->assign( 'pager', $full_mode ? '' : make_pager( 'reservationTable.php', $separate_records, $res_cnt, $page ) );
	$smarty->assign( 'menu_list', link_menu_create() );
	$smarty->display('reservationTable.html');
}
catch( exception $e ) {
	exit( $e->getMessage() );
}
?>
