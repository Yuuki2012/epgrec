<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Smarty/Smarty.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );

$settings = Settings::factory();


// チャンネル選別抽出
function get_channels( $type )
{
	global $BS_CHANNEL_MAP;
	global $CS_CHANNEL_MAP;
	global $EX_CHANNEL_MAP;

	switch( $type ){
		case 'BS':
			$map = $BS_CHANNEL_MAP;
			break;
		case 'CS':
			$map = $CS_CHANNEL_MAP;
			break;
		case 'EX':
			$map = $EX_CHANNEL_MAP;
			break;
	}
	$ext_pac = array();
	$cer_pac = array();
	try{
		$channel = DBRecord::createRecords( CHANNEL_TBL, 'WHERE type=\''.$type.'\' ORDER BY sid' );
		foreach( $channel as $ch ){
			$arr = array();
			$arr['id']           = (int)$ch->id;
			$arr['type']         = $type;
			$arr['sid']          = (int)$ch->sid;
			$arr['channel_disc'] = $ch->channel_disc;
			$arr['channel']      = $ch->channel;
			$arr['name']         = $ch->name;
			$arr['skip']         = (boolean)$ch->skip;
			if( $map[$arr['channel_disc']] !== 'NC' ){
				if( DBRecord::countRecords( PROGRAM_TBL, 'WHERE channel_id='.$arr['id'] ) == 0 ){
					// 廃止チャンネル
					$arr['rec'] = DBRecord::countRecords( RESERVE_TBL, 'WHERE channel_id='.$arr['id'].' AND complete=1' );
					array_push( $ext_pac, $arr );
				}else
					array_push( $cer_pac, $arr );
			}else{
				$arr['rec'] = DBRecord::countRecords( RESERVE_TBL, 'WHERE channel_id='.$arr['id'].' AND complete=1' );
				array_push( $ext_pac, $arr );
			}
		}
	}catch( Exception $e ){
	}
	return array( $ext_pac, $cer_pac );
}

	// 廃止チャンネル管理
	$ext_chs = array();
	$cer_chs = array();
	if( (int)$settings->bs_tuners != 0 ){
		$bs_pac = get_channels( 'BS' );
		if( (boolean)$settings->cs_rec_flg ){
			$cs_pac  = get_channels( 'CS' );
			$ext_chs = array_merge( $bs_pac[0], $cs_pac[0] );
			$cer_chs = array_merge( $bs_pac[1], $cs_pac[1] );
		}else{
			$ext_chs = $bs_pac[0];
			$cer_chs = $bs_pac[1];
		}
	}
	if( EXTRA_TUNERS ){
		$ex_pac = get_channels( 'EX' );
		if( (int)$settings->bs_tuners != 0 ){
			$ext_chs = array_merge( $ext_chs, $ex_pac[0] );
			$cer_chs = array_merge( $cer_chs, $ex_pac[1] );
		}else{
			$ext_chs = $ex_pac[0];
			$cer_chs = $ex_pac[1];
		}
	}

	// ストレージ空き容量取得
	$ts_stream_rate = TS_STREAM_RATE;
	$spool_path = INSTALL_PATH.$settings->spool;
	$files = scandir( $spool_path );
	if( DATA_UNIT_RADIX_BINARY ){
		$unit_radix = 1024;
		$byte_unit  = 'iB';
	}else{
		$unit_radix = 1000;
		$byte_unit  = 'B';
	}
	if( $files !== FALSE ){
		// 全ストレージ空き容量仮取得
		$root_mega = $free_mega = (int)( disk_free_space( $spool_path ) / ( $unit_radix * $unit_radix ) );
		// スプール･ルート･ストレージの空き容量保存
		$stat  = stat( $spool_path );
		$dvnum = (int)$stat['dev'];
		$spool_disks = array();
		$arr = array();
		$arr['dev']   = $dvnum;
		$arr['dname'] = get_device_name( $dvnum );
		$arr['path']  = $settings->spool;
		$usr_stat = posix_getpwuid( $stat['uid']);
		$own_chk  = $stat['uid']===posix_getuid() || $usr_stat['name']==='root';
		$arr['owner'] = $own_chk ? $usr_stat['name'] : '****';
		$grp_stat = posix_getgrgid( $stat['gid']);
		$arr['grupe'] = $own_chk ? $grp_stat['name'] : '****';
		$arr['perm']  = sprintf("0%o", $stat['mode'] );
		$arr['wrtbl'] = ( $stat['uid']===posix_getuid() && ($stat['mode']&0300)===0300 ) || ( posix_getgid()===$stat['gid'] && ($stat['mode']&0030)===0030 ) || ($stat['mode']&0003)===0003 ? '1' :'0';
//		$arr['link']  = 'spool root';
		$arr['size']  = number_format( $root_mega/$unit_radix, 1 );
		$arr['time']  = rate_time( $root_mega );
		array_push( $spool_disks, $arr );
		$devs = array( $dvnum );

		// スプール･ルート上にある全ストレージの空き容量取得
		array_splice( $files, 0, 2 );
		foreach( $files as $entry ){
			$entry_path = $spool_path.'/'.$entry;
			if( is_link( $entry_path ) && is_dir( $entry_path ) ){
				$stat  = stat( $entry_path );
				$dvnum = (int)$stat['dev'];
				if( !in_array( $dvnum, $devs ) ){
					$entry_mega   = (int)( disk_free_space( $entry_path ) / ( $unit_radix * $unit_radix ) );
					$free_mega   += $entry_mega;
					$arr = array();
					$arr['dev']   = $dvnum;
					$arr['dname'] = get_device_name( $dvnum );
					$arr['path']  = $settings->spool.'/'.$entry;
					$usr_stat = posix_getpwuid( $stat['uid']);
					$own_chk  = $stat['uid']===posix_getuid() || $usr_stat['name']==='root';
					$arr['owner'] = $own_chk ? $usr_stat['name'] : '****';
					$grp_stat = posix_getgrgid( $stat['gid']);
					$arr['grupe'] = $own_chk ? $grp_stat['name'] : '****';
					$arr['perm']  = sprintf("0%o", $stat['mode'] );
					$arr['wrtbl'] = ( $stat['uid']===posix_getuid() && ($stat['mode']&0300)===0300 ) || ( posix_getgid()===$stat['gid'] && ($stat['mode']&0030)===0030 ) || ($stat['mode']&0003)===0003 ? '1' :'0';
//					$arr['link']  = readlink( $entry_path );
					$arr['size']  = number_format( $entry_mega/$unit_radix, 1 );
					$arr['time']  = rate_time( $entry_mega );
					array_push( $spool_disks, $arr );
					array_push( $devs, array( $dvnum ) );
				}
			}
		}
	}else{
		// SPOOL不在
		$free_mega = 0;
		$spool_disks = array();
		$arr = array();
		$arr['dev']   = 0;
		$arr['dname'] = 'none';
		$arr['path']  = '---';
		$arr['owner'] = '----';
		$arr['grupe'] = '----';
		$arr['perm']  = '------';
		$arr['wrtbl'] = '0';
//		$arr['link']  = 'spool root';
		$arr['size']  = '----';
		$arr['time']  = '----';
		array_push( $spool_disks, $arr );
	}

	if( file_exists( INSTALL_PATH.'/setting_upload.php' ) ){
		$file_upload = '<br><div style="font-weight: bold;">設定ファイルの同期</div>'.
						'<form enctype="multipart/form-data" action="setting_upload.php" method="POST">'.
						'<input type="hidden" name="MAX_FILE_SIZE" value="3000000">'.
						'<input name="userfile" type="file"><br>'.	// name 属性の値が、$_FILES 配列のキーになります
						'<input type="submit" value="ファイル同期">'.
						'</form>';
	}else
		$file_upload = '';

	$smarty = new Smarty();
	$smarty->assign( 'menu_list',   link_menu_create() );
	$smarty->assign( 'free_size',   number_format( $free_mega/$unit_radix, 1 ) );
	$smarty->assign( 'free_time',   rate_time( $free_mega ) );
	$smarty->assign( 'ts_rate',     $ts_stream_rate );
	$smarty->assign( 'byte_unit',   $byte_unit );
	$smarty->assign( 'spool_disks', $spool_disks );
	$smarty->assign( 'ext_chs',     $ext_chs );
	$smarty->assign( 'cer_chs',     $cer_chs );
	$smarty->assign( 'epg_get',     HIDE_CH_EPG_GET  );
	$smarty->assign( 'auto_del',    EXTINCT_CH_AUTO_DELETE );
	$smarty->assign( 'file_upload', $file_upload );
	$smarty->display('maintenanceTable.html');
?>
