<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Smarty/Smarty.class.php' );
include_once( INSTALL_PATH . '/Reservation.class.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );


function view_strlen( $str )
{
	$byte_len = strlen( $str );
	$str_len  = mb_strlen( $str );
	$mc = (int)(( $byte_len - $str_len ) / 2);
	$sc = $str_len - $mc;
	return $mc*2+$sc;
}


function box_pad( $str, $width )
{
/*
	// いいかげんなので保留
	$str_wd = view_strlen( $str );
	if( ($width-$str_wd)%2 === 1 )
		$str .= ' ';
	if( ($width-$str_wd)/4 > 0 )
		$str .= str_repeat( '　', ($width-$str_wd)/4 );
	return $str;
*/
	return '['.$str.']';
}


$settings = Settings::factory();

$week_tb = array( '日', '月', '火', '水', '木', '金', '土' );


$search       = '';
$category_id  = 0;
$station      = 0;
$key_id       = FALSE;
$page         = 1;
$pager_option = '';
$full_mode    = FALSE;
$order        = 'starttime+DESC';


$options = 'starttime<\''. date('Y-m-d H:i:s').'\'';	// ながら再生は無理っぽい？

$rev_obj = new DBRecord( RESERVE_TBL );

$act_trans = array_key_exists( 'tsuffix', end($RECORD_MODE) );
if( $act_trans )
	$trans_obj = new DBRecord( TRANSCODE_TBL );

if( isset( $_REQUEST['key']) )
	$key_id = (int)$_REQUEST['key'];

$rev_opt = $key_id!==FALSE ? ' AND autorec='.$key_id : '';


if( isset($_REQUEST['search']) ){
	if( $_REQUEST['search'] !== '' ){
		$search = $_REQUEST['search'];
		foreach( explode( ' ', trim($search) ) as $key ){
			$k_len = strlen( $key );
			if( $k_len>1 && $key[0]==='-' ){
				$k_len--;
				$key      = substr( $key, 1 );
				$rev_opt .= ' AND CONCAT(title,\' \', description) NOT LIKE ';
			}else
				$rev_opt .= ' AND CONCAT(title,\' \', description) LIKE ';
			if( $key[0]==='"' && $k_len>2 && $key[$k_len-1]==='"' )
				$key = substr( $key, 1, $k_len-2 );
			$rev_opt .= '\'%'.$rev_obj->sql_escape( $key ).'%\'';
		}
	}
}
if( isset($_REQUEST['category_id']) ){
	if( $_REQUEST['category_id'] != 0 ){
		$category_id = $_REQUEST['category_id'];
		$rev_opt    .= ' AND category_id='.$_REQUEST['category_id'];
	}
}
if( isset($_REQUEST['station']) ){
	if( $_REQUEST['station'] != 0 ){
		$station  = $_REQUEST['station'];
		$rev_opt .= ' AND channel_id='.$_REQUEST['station'];
	}
}
if( isset($_REQUEST['full_mode']) )
	$full_mode = $_REQUEST['full_mode']==1;

if( isset($_REQUEST['order']) ){
	$order = str_replace( ' ', '+', $_REQUEST['order'] );
}

if( isset($_POST['do_delete']) ){
	$delete_file = isset($_POST['delrec']);
	$id_list     = $rev_obj->fetch_array( null, null, 'complete=1'.$rev_opt );
	if( isset($_POST['delall']) ){
		$del_list    = $id_list;
		$rev_opt     = '';
		$search      = '';
		$category_id = 0;
		$station     = 0;
		$key_id      = FALSE;
		$full_mode   = FALSE;
	}else{
		$del_list = array();
		foreach( $id_list as $del_id ){
			if( isset($_POST['del'.$del_id['id']]) )
				array_push( $del_list, $del_id );
		}
	}

	foreach( $del_list as $rec ){
		if( $delete_file ){
			// トラコンファイル削除
			if( $act_trans ){
				$del_trans = $trans_obj->fetch_array( null, null, 'rec_id='.$rec['id'].' ORDER BY status' );
				foreach( $del_trans as $del_file ){
					switch( $del_file['status'] ){
						case 1:		// 処理中(0は処理済)
							$ps_output = shell_exec( PS_CMD );
							$rarr      = explode( "\n", $ps_output );
							killtree( $rarr, (int)$del_file['pid'] );
							sleep(1);
							break;
						case 2:		// 正常終了
						case 3:		// 異常終了
							if( file_exists( $del_file['path'] ) )
								@unlink( $del_file['path'] );
							break;
					}
					$trans_obj->force_delete( $del_file['id'] );
				}
			}
			// ファイルを削除
			$reced = INSTALL_PATH.$settings->spool.'/'.$rec['path'];
			if( file_exists( $reced ) )
				@unlink( $reced );
		}
		// サムネイル削除
		$thumbs = INSTALL_PATH.$settings->thumbs.'/'.end(explode( '/', $rec['path'] )).'.jpg';
		if( file_exists( $thumbs ) )
			@unlink( $thumbs );

		// 予約取り消し実行
		try {
			$ret_code = Reservation::cancel( $rec['id'], 0 );
		}catch( Exception $e ){
			// 無視
		}
	}
}


try{
	// CH一覧作成
	$ch_list   = $rev_obj->distinct( 'channel_id', 'WHERE '.$options );
	$ch_opt    = count( $ch_list ) ? ' AND id IN ('.implode( ',', $ch_list ).')' : '';
	$stations  = array();
	$chid_list = array();
	$stations[0]['id']       = $chid_list[0] = 0;
	$stations[0]['name']     = 'すべて';
	$stations[0]['selected'] = (! $station) ? 'selected' : '';
	$stations[0]['count']    = 0;
	$crecs = DBRecord::createRecords( CHANNEL_TBL, 'WHERE type=\'GR\' AND skip=0'.$ch_opt.' ORDER BY id' );
	foreach( $crecs as $c ){
		$arr = array();
		$arr['id']       = $chid_list[] = (int)$c->id;
		$arr['name']     = $c->name;
		$arr['selected'] = $station == $c->id ? 'selected' : '';
		$arr['count']    = 0;
		array_push( $stations, $arr );
	}
	$crecs = DBRecord::createRecords( CHANNEL_TBL, 'WHERE type=\'BS\' AND skip=0'.$ch_opt.' ORDER BY sid' );
	foreach( $crecs as $c ){
		$arr = array();
		$arr['id']       = $chid_list[] = (int)$c->id;
		$arr['name']     = $c->name;
		$arr['selected'] = $station == $c->id ? 'selected' : '';
		$arr['count']    = 0;
		array_push( $stations, $arr );
	}
	$crecs = DBRecord::createRecords( CHANNEL_TBL, 'WHERE type=\'CS\' AND skip=0'.$ch_opt.' ORDER BY sid' );
	foreach( $crecs as $c ){
		$arr = array();
		$arr['id']       = $chid_list[] = (int)$c->id;
		$arr['name']     = $c->name;
		$arr['selected'] = $station == $c->id ? 'selected' : '';
		$arr['count']    = 0;
		array_push( $stations, $arr );
	}
	$crecs = DBRecord::createRecords( CHANNEL_TBL, 'WHERE type=\'EX\' AND skip=0'.$ch_opt.' ORDER BY sid' );
	foreach( $crecs as $c ){
		$arr = array();
		$arr['id']       = $chid_list[] = (int)$c->id;
		$arr['name']     = $c->name;
		$arr['selected'] = $station == $c->id ? 'selected' : '';
		$arr['count']    = 0;
		array_push( $stations, $arr );
	}
//	$chid_list = array_column( $stations, 'id' );		// PHP5.5

	// カテゴリー一覧作成
	$cat_list = $rev_obj->distinct( 'category_id', 'WHERE '.$options );
	$cat_opt  = count( $cat_list ) ? 'WHERE id IN ('.implode( ',', $cat_list ).')' : '';
	$crecs    = DBRecord::createRecords( CATEGORY_TBL, $cat_opt );
	$cats     = array();
	$cats[0]['id'] = 0;
	$cats[0]['name'] = 'すべて';
	$cats[0]['selected'] = $category_id == 0 ? 'selected' : '';
	$cats[0]['count']    = 0;
	$ct_len = 0;
	foreach( $crecs as $c ){
		$arr = array();
		$arr['id']       = $c->id;
		$arr['name']     = $c->name_jp;
		$tmp_len = view_strlen( $arr['name'] );
		if( $ct_len < $tmp_len )
			$ct_len = $tmp_len;
		$arr['selected'] = $c->id == $category_id ? 'selected' : '';
		$arr['count']    = 0;
		array_push( $cats, $arr );
	}

	// 自動キーワード一覧作成
	$cs_rec_flg = (boolean)$settings->cs_rec_flg;
	$key_list = $rev_obj->distinct( 'autorec', 'WHERE '.$options );
	$key_opt  = count( $key_list ) ? 'WHERE id IN ('.implode( ',', $key_list ).') ORDER BY id' : '';
	$crecs    = DBRecord::createRecords( KEYWORD_TBL, $key_opt );
	$keyid_list = array();
	$keys     = array();
	$keys[0]['id']       = $keyid_list[] = 0;
	$keys[0]['name']     = '《キーワードなし》';
	$keys[0]['selected'] = $key_id===0 ? 'selected' : '';
	$keys[0]['count']    = 0;
	$id_len = $sn_len = 0;
	foreach( $crecs as $c ){
		$arr = array();
		$arr['id'] = $keyid_list[] = $c->id;
		$tmp_len = view_strlen( $arr['id'] );
		if( $id_len < $tmp_len )
			$id_len = $tmp_len;
		if( (int)$c->channel_id ){
			$chid_key     = array_search( (int)$c->channel_id, $chid_list );
			$station_name = $chid_key!==FALSE ? $stations[$chid_key]['name'] : '';
		}else
			$station_name = '';
		if( $station_name === '' ){
			if( !$c->typeGR || ( $settings->bs_tuners>0 && ( !$c->typeBS || ( $cs_rec_flg && !$c->typeCS ) ) ) || ( EXTRA_TUNERS>0 && !$c->typeEX ) ){
				$types = array();
				if( $c->typeGR )
					$types[] = 'GR';
				if( $settings->bs_tuners > 0 ){
					if( $c->typeBS )
						$types[] = 'BS';
					if( $cs_rec_flg && $c->typeCS )
						$types[] = 'CS';
				}
				if( EXTRA_TUNERS>0 && $c->typeEX )
					$types[] = 'EX';
				$station_name = implode( '+', $types );
			}else
				$station_name = 'ALL';
		}
		$arr['station']  = $station_name;
		$tmp_len = view_strlen( $station_name );
		if( $sn_len < $tmp_len )
			$sn_len = $tmp_len;
		if( $c->keyword !== '' ){
			$keywds = array();
			foreach( explode( ' ', trim($c->keyword) ) as $key ){
				if( strlen( $key )>0 && $key[0]!=='-' ){
					$keywds[] = $key;
				}
			}
			$arr['name'] = str_replace( '%', ' ', implode( ' ', $keywds ) );
		}else
			$arr['name'] = '';
		$arr['cat']      = (int)$c->category_id;
		$arr['subgenre'] = (int)$c->sub_genre;
		$arr['selected'] = (int)$c->id===$key_id ? ' selected' : '';
		$arr['count']    = 0;
		array_push( $keys, $arr );
	}


	$rvs = $rev_obj->fetch_array( null, null, $options.$rev_opt.' ORDER BY '.str_replace( '+', ' ', $order ) );
	$stations[0]['count'] = $cats[0]['count'] = count( $rvs );

	if( ( SEPARATE_RECORDS_RECORDED===FALSE &&  SEPARATE_RECORDS<1 ) || ( SEPARATE_RECORDS_RECORDED!==FALSE && SEPARATE_RECORDS_RECORDED<1 ) )	// "<1"にしているのはフェイルセーフ
		$full_mode = TRUE;
	else{
		if( isset( $_GET['page']) ){
			if( $_GET['page'] === '-' )
				$full_mode = TRUE;
			else
				$page = (int)$_GET['page'];
		}
		$separate_records = SEPARATE_RECORDS_RECORDED!==FALSE ? SEPARATE_RECORDS_RECORDED : SEPARATE_RECORDS;
		$view_overload    = VIEW_OVERLOAD_RECORDED!==FALSE ? VIEW_OVERLOAD_RECORDED : VIEW_OVERLOAD;
		if( $stations[0]['count'] <= $separate_records+$view_overload )
			$full_mode = TRUE;
	}

	if( $full_mode ){
		$start_record  = 0;
		$end_record    = $stations[0]['count'];
		$pager_option .= 'page=-&';
	}else{
		$start_record = ( $page - 1 ) * $separate_records;
		$end_record   = $page * $separate_records;
	}
	if( $key_id !== FALSE )
		$pager_option .= 'key='.$key_id.'&';
	if( $search !== '' )
		$pager_option .= 'search='.htmlspecialchars($search,ENT_QUOTES).'&';
	if( $category_id !== 0 )
		$pager_option .= 'category_id='.$category_id.'&';
	if( $station !== 0 )
		$pager_option .= 'station='.$station.'&';

	$part_path = explode( '/', $_SERVER['PHP_SELF'] );
	array_pop( $part_path );
	$base_path = implode( '/', $part_path );
	$view_url = $base_path;
//	$host_url = explode( $base_path, isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : $_SERVER['SCRIPT_NAME'] );
//	$view_url = $host_url[0].$base_path;		// $settings->install_url
	$transcode = TRANSCODE_STREAM && $NET_AREA!==FALSE && $NET_AREA!=='H';
	$records = array();
	foreach( $rvs as $key => $r ){
		$arr = array();
		if( (int)$r['channel_id'] ){
			$chid_key = array_search( (int)$r['channel_id'], $chid_list );
			if( $chid_key !== FALSE ){
				$arr['station_name'] = $stations[$chid_key]['name'];
				$stations[$chid_key]['count']++;
			}else{
				$arr['station_name'] = 'lost';
			}
		}else
			$arr['station_name'] = 'lost';
		$arr['cat'] = (int)$r['category_id'];
		if( $arr['cat'] ){
			$cat_key = array_search( $arr['cat'], $cat_list );
			if( $cat_key !== FALSE )
				$cats[$cat_key+1]['count']++;
		}
		$arr['key_id'] = (int)$r['autorec'];
		if( $arr['key_id'] ){
			if( DBRecord::countRecords( KEYWORD_TBL, 'WHERE id='.$arr['key_id'] )==0 ){
				$wrt_set = array();
				$arr['key_id'] = $wrt_set['autorec'] = 0;
				$rev_obj->force_update( $r['id'], $wrt_set );
			}
		}
		$keys[array_search($arr['key_id'],$keyid_list)]['count']++;
		if( $start_record<=$key && $key<$end_record ){
			$arr['id']          = (int)$r['id'];
			$start_time         = toTimestamp($r['starttime']);
			$end_time           = toTimestamp($r['endtime']);
			$arr['starttime']   = date( 'Y/m/d(', $start_time ).$week_tb[date( 'w', $start_time )].')<br>'.date( 'H:i:s', $start_time );
			$arr['duration']    = date( 'H:i:s', $end_time-$start_time-9*60*60 );
			$arr['asf']         = 'viewer.php?reserve_id='.$r['id'];
			$arr['title']       = htmlspecialchars($r['title'],ENT_QUOTES);
			$arr['description'] = htmlspecialchars($r['description'],ENT_QUOTES);
			if( file_exists(INSTALL_PATH.$settings->thumbs.'/'.end(explode( '/', $r['path'] )).'.jpg') )
				$arr['thumb'] = '<img src="'.$view_url.$settings->thumbs.'/'.rawurlencode(end(explode( '/', $r['path'] ))).'.jpg" />';
			else
				$arr['thumb'] = '';
			$arr['keyword']     = putProgramHtml( $r['title'], '*', 0, $r['category_id'], 16 );
			if( $r['complete']==0 && time()>$end_time+(int)$settings->extra_time+5 ){
				if( at_clean( $r, $settings ) === 0 ){
					// 予約終了化
					$wrt_set = array();
					$wrt_set['complete'] = 1;
					$rev_obj->force_update( $r['id'], $wrt_set );
				}
			}
			if( file_exists( INSTALL_PATH.$settings->spool.'/'.$r['path'] ) ){
				$arr['view_set'] = '<a href="'.$arr['asf'].'" title="クリックすると視聴できます（ブラウザの設定でASFと視聴アプリを関連付けている必要があります）"'.
									' style="background-color: '.($r['complete']==0 ? 'greenyellow' : 'limegreen').'; color: black;"> '.
									(isset($RECORD_MODE[$r['mode']]['tsuffix']) ? 'TS' : $RECORD_MODE[$r['mode']]['name']).' </a>';
				if( $transcode )	// 録画中のトランスコードストリームも可能
					$arr['view_set'] .= ' <a href="'.$arr['asf'].'&trans=ON" title="トランスコード視聴" id="trans_url_'.($key-$start_record).
										'" style="color: white; background-color: royalblue;">▼</a>';
				if( $r['complete'] == 1 )
					$arr['view_set'] .= ' <input type="button" value="D" title="ダウンロード" onClick="javascript:PRG.downdialog(\''.$arr['id'].'\',\''.$arr['duration'].'\')" style="padding:0;">';
				// マニュアル・トランスコード
//				if( $act_trans ){
//					$arr['view_set'] .= ' <a href="manualtrans.php?reserve_id='.$r['id'].'&trans=ON" title="マニュアル・トランスコード" id="trans_url_'.($key-$start_record).
//										'" style="color: white; background-color: royalblue;">■</a>';
//				}
			}else
				$arr['view_set'] = '';
			if( $act_trans ){
				$tran_ex = $trans_obj->fetch_array( 'rec_id', $arr['id'] );
				foreach( $tran_ex as $loop => $tran_unit ){
					$element = '';
					switch( $tran_unit['status'] ){
						case 0:
							$element = '<a style="background-color: yellow;" title="変換待ち"> '.$RECORD_MODE[$tran_unit['mode']]['name'].' </a>';
							break;
						case 1:
							$element = '<a style="background-color: greenyellow;" href="'.
																						$arr['asf'].'&trans_id='.$tran_unit['id'].'" title="変換中"> '.$tran_unit['name'].' </a>';
							break;
						case 2:
							if( file_exists( $tran_unit['path'] ) ){
								$element = '<a style="background-color: limegreen; color: black" href="'.
																						$arr['asf'].'&trans_id='.$tran_unit['id'].'" title="視聴"> '.$tran_unit['name'].' </a>';
								$element .= ' <input type="button" value="D" title="ダウンロード" onClick="location.href=\'download_file.php?trans_id='.$tran_unit['id'].'\'" style="padding:0;">';
							}else
								$trans_obj->force_delete( $tran_unit['id'] );
							break;
						case 3:
							$element = '<a style="background-color: red; color: white;"'.
								( file_exists( $tran_unit['path'] ) ? ' href="'.$arr['asf'].'&trans_id='.$tran_unit['id'].'"' : '' ).' title="変換失敗"> '.$tran_unit['name'].' </a>';
							break;
					}
					if( $element !== '' ){
						if( $arr['view_set'] !== '' )
							$arr['view_set'] .= '<br>';
						$arr['view_set'] .= $element;
					}
				}
			}
			if( $arr['view_set'] === '' )
				$arr['view_set'] = '<a><del> '.$RECORD_MODE[$r['mode']]['name'].' </del></a>';
			array_push( $records, $arr );
		}
	}

	if( $key_id === FALSE )
		$keys[0]['name'] = '('.str_pad( $keys[0]['count'], 4, '0', STR_PAD_LEFT ).') '.$keys[0]['name'];
	for( $piece=1; $piece<count($keys); $piece++ ){
		$cat_key  = array_search( $keys[$piece]['cat'], $cat_list );
		$cat_name = $cat_key===FALSE ? $cats[0]['name'] : $cats[$cat_key+1]['name'].'('.$keys[$piece]['subgenre'].')';
		$keys[$piece]['name'] = ($key_id===FALSE ? '('.str_pad( $keys[$piece]['count'], 4, '0', STR_PAD_LEFT ).') ' : '')
								.'ID:'.str_pad( $keys[$piece]['id'], $id_len, '0', STR_PAD_LEFT )
								.' '.htmlspecialchars($keys[$piece]['name']."\t",ENT_QUOTES)
								.htmlspecialchars(box_pad( $keys[$piece]['station'], $sn_len ).' '.box_pad( $cat_name, $ct_len ),ENT_QUOTES)
								;
	}

	if( $transcode && !TRANS_SCRN_ADJUST ){
		for( $cnt=0; $cnt<count($TRANSSIZE_SET); $cnt++ )
			$TRANSSIZE_SET[$cnt]['selected'] = $cnt===TRANSTREAM_SIZE_DEFAULT ? ' selected' : '';
	}

	$smarty = new Smarty();
	$smarty->assign('sitetitle','録画済一覧' );
	$smarty->assign( 'menu_list', link_menu_create() );
	$smarty->assign( 'spool_freesize', spool_freesize() );
	$smarty->assign( 'pager', $full_mode ? '' : make_pager( 'recordedTable.php', $separate_records, $stations[0]['count'], $page, $pager_option.'order='.$order.'&' ) );
	$smarty->assign( 'full_mode', $full_mode );
	$smarty->assign( 'pager_option', 'recordedTable.php?'.$pager_option );
	$smarty->assign( 'order', $order );
	$smarty->assign( 'records', $records );
	$smarty->assign( 'search', $search );
	$smarty->assign( 'stations', $stations );
	$smarty->assign( 'cats', $cats );
	$smarty->assign( 'keys', $keys );
	$smarty->assign( 'key_id', $key_id );
	$smarty->assign( 'station', $station );
	$smarty->assign( 'category_id', $category_id );
	$smarty->assign( 'use_thumbs', $settings->use_thumbs );
	$smarty->assign( 'TRANSCODE_STREAM', $transcode );
	$smarty->assign( 'TRANS_SCRN_ADJUST', $transcode && TRANS_SCRN_ADJUST ? 1 : 0 );
	$smarty->assign( 'transsize_set', $TRANSSIZE_SET );
//	$smarty->assign( 'trans_mode', $act_trans ? $TRANS_MODE : FALSE );
	$smarty->display('recordedTable.html');
}
catch( exception $e ){
	exit( $e->getMessage() );
}
?>
