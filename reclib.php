<?php
//include_once( INSTALL_PATH . '/config.php');

// ライブラリ

define( 'FTOK_KEY', '/tmp/epgrec_ftok' );

// 最大値
define( 'MAX_TUNERS',    20 );					// 論理チューナー数
define( 'SEM_KW_MAX',    10 );					// キーワード予約排他処理用
define( 'SHM_SCAL_WIDE',  8 );					// 変数の桁数+1

// ftok()のID・セマフォ・共有メモリーのキー (双方で共用)
define( 'SEM_GR_START',  1 );							//  1-20:地デジ (0:未使用or録画中 1:EPG受信 2:リアルタイム視聴)
define( 'SEM_ST_START', (SEM_GR_START+MAX_TUNERS) );	// 21-40:衛星 (0:未使用or録画中 1:EPG受信 2:リアルタイム視聴)
define( 'SEM_EX_START', (SEM_ST_START+MAX_TUNERS) );	// 41-60:スカパー！プレミアム (0:未使用or録画中 1:EPG受信 2:リアルタイム視聴)
define( 'SEM_REALVIEW', (SEM_EX_START+MAX_TUNERS) );	// 61:   リアルタイム視聴(チューナー番号)
define( 'SEM_EPGDUMP',  (SEM_REALVIEW+1) );				// 62:   epgdump
define( 'SEM_EPGSTORE', (SEM_REALVIEW+2) );				// 63:   EPGのDB展開
define( 'SEM_REBOOT',   (SEM_REALVIEW+3) );				// 64:   リブート・フラグ
define( 'SEM_TRANSCODE',(SEM_REALVIEW+4) );				// 65:   トランスコードマネージャ起動確認
define( 'SEM_PW_REDUCE',(SEM_REALVIEW+5) );				// 66:   間欠運用管理資源
define( 'SEM_EPGDUMPF', (SEM_REALVIEW+6) );				// 67:   epgdump(強制)
define( 'SEM_EPGSTOREF',(SEM_REALVIEW+7) );				// 68:   EPGのDB展開(強制)
define( 'SEM_DISKCHECK',(SEM_REALVIEW+8) );				// 69:   DISK残量チェック
define( 'SEM_KW_START', (SEM_REALVIEW+10) );			// 71-80:キーワード予約排他処理用(キーワードID)
define( 'SEM_MAX',      (SEM_KW_START+SEM_KW_MAX-1) );	// 
define( 'SHM_ID',      255 );							// 共用メモリー

if( ! defined( 'EPERM'  ) ) define( 'EPERM',  1 );
if( ! defined( 'ENOENT' ) ) define( 'ENOENT', 2 );
if( ! defined( 'ESRCH'  ) ) define( 'ESRCH',  3 );
if( ! defined( 'EEXIST' ) ) define( 'EEXIST', 17 );
if( ! defined( 'EINVAL' ) ) define( 'EINVAL', 22 );
if( ! defined( 'ENOSPC' ) ) define( 'ENOSPC', 28 );

function toTimestamp( $string ) {
	sscanf( $string, '%4d-%2d-%2d %2d:%2d:%2d', $y, $mon, $day, $h, $min, $s );
	return mktime( $h, $min, $s, $mon, $day, $y );
}

function toDatetime( $timestamp ) {
	return date('Y-m-d H:i:s', $timestamp);
}


function jdialog( $message, $url = "index.php" ) {
    header( "Content-Type: text/html;charset=utf-8" );
    exit( "<script type=\"text/javascript\">\n" .
          "<!--\n".
         "alert(\"". $message . "\");\n".
         "window.open(\"".$url."\",\"_self\");".
         "// -->\n</script>" );
}

// マルチバイトstr_replace

function mb_str_replace($search, $replace, $target, $encoding = "UTF-8" ) {
	$notArray = !is_array($target) ? TRUE : FALSE;
	$target = $notArray ? array($target) : $target;
	$search_len = mb_strlen($search, $encoding);
	$replace_len = mb_strlen($replace, $encoding);
	
	foreach ($target as $i => $tar) {
		$offset = mb_strpos($tar, $search);
		while ($offset !== FALSE){
			$tar = mb_substr($tar, 0, $offset).$replace.mb_substr($tar, $offset + $search_len);
			$offset = mb_strpos($tar, $search, $offset + $replace_len);
		}
		$target[$i] = $tar;
	}
	return $notArray ? $target[0] : $target;
}


//
function operateParse( $input, $cmd, $enclosure='%' ){
	$cmd     = $enclosure.$cmd.'(';
	$cmd_len = strlen( $cmd );
	$magic_c = strpos( $input, $cmd );
	if( $magic_c !== FALSE )
		return strstr( substr( $input, $magic_c+$cmd_len ), ')'.$enclosure, TRUE );
	else
		return FALSE;
}


function extraWordCheck( $src, $enclosure='$' ){
	$src_len = strlen( $src );
	return ( $src_len>3 && $src[0]===$enclosure && $src[$src_len-1]===$enclosure );
}


// 対象文字列の指定バイト位置がマルチバイト文字(UTF-8)か否か判定しマルチバイト文字なら文字先頭への退避数を返す
function check_char_type( $src, $point ){
	$ret = 0;
	if( ord($src[$point]) & 0x80 ){
		while( ( ord($src[$point]) & 0xC0) !== 0xC0 ){
			$point--;
			$ret++;
		}
	}
	return $ret;
}


// UTF-8対応strncpy
function mb_strncpy( $src, $len ){
	return substr( $src, 0, $len-check_char_type( $src, $len ) );		// mb_strcut() -> substr()
}


// 部分取得
// $cp_len 取得byte数 負数の場合は後方から数える。0の場合は指定領域の全体を対象とする。
// $delim  区切り文字列(省略化、その場合はn2も省略すること)
// $offset 文字列Aで区切られた区画の対象位置(省略化) 省略した場合は先頭区画を対象とする。個数を超える場合は最後尾が対象となる。
function fn_substr( $dest_sorce, $cp_len=0, $delim='', $offset=0 ){
	if( $dest_sorce === '' )
		return '';
	if( $delim !== '' ){
		//分割
		$desc_pieces = explode( $delim, $dest_sorce );
		$pie_cnt     = count( $desc_pieces );
		if( $offset > $pie_cnt-1 )
			$offset = $pie_cnt - 1;
		$dest_sorce = $desc_pieces[$offset];
	}
	if( $cp_len !== 0 ){
		if( $cp_len > 0 )
			$dire = TRUE;
		else{
			$dire    = FALSE;
			$cp_len *= -1;
		}
		// 部分取得
		$desc_len = strlen( $dest_sorce );
		if( $cp_len < $desc_len ){
			if( $dire )
				$dest_sorce = mb_strncpy( $dest_sorce, $cp_len );
			else{
				// 後方カウント
				$point = $desc_len - $cp_len;
				//UTF-8文字の途中byteの場合は次の文字へ
				while(1){
					$temp = ord($dest_sorce[$point]);
					if( $temp & 0x80 ){
						if( $temp & 0x40 )
							break;		//UTF-8先頭
						if( ++$point>=$desc_len )
							return '';		//終端まで逝った
					}else
						break;		// 1byte文字
				}
				$dest_sorce = substr( $dest_sorce, $point );
			}
		}
	}
	return $dest_sorce;
}


function textLimitReplace( &$filename, $cmd, $desc_sorce ){
	$csv_word = operateParse( $filename, $cmd );
	if( $csv_word !== FALSE ){
		$desc_sorce = trim($desc_sorce);
		$parts      = str_getcsv( $csv_word );
		$d_cnt      = count($parts);
		if( $d_cnt ){
			if( is_numeric($parts[0]) )
				$cp_len = (int)$parts[0];
			else
				return;		// 不正書式
			if( $d_cnt >= 2 ){
				$delim  = $parts[1];
				$offset = $d_cnt>=3 && is_numeric($parts[2]) ? (int)$parts[2] : 0;
			}else{
				$delim  = '';
				$offset = 0;
			}
			$desc_sorce = fn_substr( $desc_sorce, $cp_len, $delim, $offset );
		}
		$filename = str_replace( '%'.$cmd.'('.$csv_word.')%', $desc_sorce, $filename );
	}
	return;
}


// psのレコードからトークン切り出し
function ps_tok( $src ){
	$ps_tk = new stdClass;
	$ps_tk->uid   = strtok( $src, " \t" );
	$ps_tk->pid   = strtok( " \t" );
	$ps_tk->ppid  = strtok( " \t" );
	$ps_tk->tok   = strtok( " \t" );
	$ps_tk->stime = strtok( " \t" );
	return $ps_tk;
}


function killtree( $rarr, $pid, $force=TRUE, $safe_pid=0 )
{
	posix_kill( $pid, 19 );		// 一時停止
	foreach( $rarr as $cc ){
		$ps = ps_tok( $cc );
		if( (int)$ps->ppid===$pid && (int)$ps->pid!==$safe_pid )
			killtree( $rarr, (int)$ps->pid, $force, $safe_pid );
	}
	if( $force )
		posix_kill( $pid, 9 );		// 強制終了
	else{
		posix_kill( $pid, 15 );		// 終了
		posix_kill( $pid, 18 );		// 再開
		usleep( 100*1000 );
		if( posix_kill( $pid, 0 ) === FALSE ){
			$errno = posix_get_last_error();
			if( $errno != ESRCH )
				posix_kill( $pid, 9 );		// 強制終了
		}
	}
	return TRUE;
}

// プレ起動中の予約の録画コマンドかdo_record.shのpsレコード取得
function search_reccmd( $rec_id ){
	global $rec_cmds,$OTHER_TUNERS_CHARA,$EX_TUNERS_CHARA;

	$ps_output = shell_exec( PS_CMD );		// .'ww'
	$rarr = explode( "\n", $ps_output );
	if( !USE_DORECORD ){
		$rev_obj   = new DBRecord( RESERVE_TBL );
		$prev_recs = $rev_obj->fetch_array( 'id', $rec_id );

		$type      = $prev_recs[0]['type'];
		$smf_type  = $type!=='CS' ? $type : 'BS';
		$tuner     = (int)$prev_recs[0]['tuner'];
		if( $smf_type === 'EX' ){
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
		$slc_cmd = $rec_cmds[$cmd_num];
		if( $prev_recs[0]['mode'] !== '0' ){
			$ch_para = new DBRecord( CHANNEL_TBL, 'id', $prev_recs[0]['channel_id'] );
			$pr_sid  = $ch_para->sid;
			$sid     = $slc_cmd['sidEXT']!=='' ? ' --sid '.$slc_cmd['sidEXT'].','.$pr_sid : ' --sid '.$pr_sid;
		}else
			$sid = '';
		$catch_cmd  = $slc_cmd['cmd'].$slc_cmd['b25'].( $device!=='' ? $device : $sid.' '.$prev_recs[0]['channel'].' ' );	// $deviceが長いと途切れる可能性があるので
		$catch_path = ' '.$prev_recs[0]['path'];		// 途中から電子の藻屑となる場合が多いので特定材料にならない

		$atjob_pid = (int)trim( file_get_contents( '/tmp/tuner_'.$rec_id ) );
		foreach( $rarr as $cc ){
			$ps = ps_tok( $cc );
//			if( strpos( $cc, 'apache2' )===FALSE )
//				reclog( 'search_reccmd()::LINE['.$cc.']', EPGREC_WARN );
			if( (int)$ps->ppid===$atjob_pid && strpos( $cc, $catch_cmd )!==FALSE )
				return $ps;
		}
		reclog( 'search_reccmd()::録画コマンド探索に失敗しました。rev_id['.$rec_id.'] cmd['.$catch_cmd.'[time]'.$catch_path.']', EPGREC_WARN );
	}else{
		$catch_cmd = DO_RECORD.' '.$rec_id;
		for( $cc=0; $cc<count($rarr); $cc++ ){
			if( strpos( $rarr[$cc], $catch_cmd ) !== FALSE ){
				$ps = ps_tok( $rarr[$cc] );
				do{
					$cc++;
					$c_ps = ps_tok( $rarr[$cc] );
					if( $ps->pid == $c_ps->ppid ){
						return $c_ps;
					}
				}while( $cc < count($rarr) );
			}
		}
	}
	return FALSE;
}

function get_ipckey( $id ){
/*
	if( !file_exists( FTOK_KEY ) ){
		$handle = fopen( FTOK_KEY, 'w' );
		fwrite( $handle, 'a' );
		fclose( $handle );
//		exec( 'sync' );
	}
	return ftok( FTOK_KEY, $id );	// ftok()は、仕様上で唯一性を担保できないバグあり
*/
	return $id;
}

function run_user_regulate(){
	$usr_stat = posix_getpwuid( posix_getuid() );
	switch( $usr_stat['name'] ){
		case 'root':
			$groupinfo = posix_getgrnam( HTTPD_GROUP );
			if( $groupinfo === FALSE ){
				echo 'setting group name is invalid.('.HTTPD_GROUP.")\n";
				exit -1;
			}
			if( !posix_setgid( $groupinfo['gid'] ) ){
				echo "can not change the group.\n";
				exit -1;
			}
			$userinfo  = posix_getpwnam( HTTPD_USER );
			if( $userinfo === FALSE ){
				echo 'setting user name is invalid.('.HTTPD_USER.")\n";
				exit -1;
			}
			if( !posix_setuid( $userinfo['uid'] ) ){
				echo "can not change the user.\n";
				exit -1;
			}
			break;
		case HTTPD_USER:
			break;
		default:
			echo $usr_stat['name']." can not run this script.\n";
			exit -1;
	}
}

function sem_log( $fnc_name, $errno, $php_err ){
	$message = $errno!=0 ? 'posix error['.$errno.']::'.posix_strerror( $errno )."\n" : '';
	if( !empty( $php_err ) )
		$message .= 'type['.$php_err['type'].']::'.$php_err['message'].'('.$php_err['file'].':L'.$php_err['line'].")\n";
	if( $message !== '' )
		reclog( $fnc_name.'() fault　'.$message, EPGREC_WARN );
}

function sem_get_surely( $id, $max_acquire=1 ){
	$key       = get_ipckey( $id );
	$cnt       = 0;
	$pre_err   = array();
	$pre_errno = 0;
	while(1){
		$sem_id = sem_get( $key, $max_acquire, 0644 );
		if( $sem_id !== FALSE )
			return $sem_id;
		else{
			$php_err = error_get_last();
			$errno   = posix_get_last_error();
			if( ++$cnt < 1000 ){
				if( ( $errno && $errno!=$pre_errno ) || ( !empty( $php_err ) && $php_err!==$pre_err ) ){
					if( $pre_errno || !empty( $pre_err ) )
						sem_log( 'sem_get_surely', $pre_errno, $pre_err );
					$pre_errno = $errno;
					$pre_err   = $php_err;
				}
				usleep( 1000 );
			}else{
				sem_log( 'sem_get_surely', $errno, $php_err );
				return FALSE;
			}
		}
	}
}

function shmop_open_surely( $ret_mode=FALSE ){
	$key       = get_ipckey( SHM_ID );
	$cnt       = 0;
	$pre_err   = array();
	$pre_errno = 0;
	while(1){
		$shm_id = @shmop_open( $key, 'w', 0, 0 );
		if( $shm_id !== FALSE )
			return $shm_id;
		else{
			$shm_id = shmop_open( $key, 'n', 0644, SEM_MAX*SHM_SCAL_WIDE );
			if( $shm_id !== FALSE ){
				// 初期化
				for( $cnt=1; $cnt<=SEM_MAX; $cnt++ ){
					if( !shmop_write_surely( $shm_id, $cnt, 0 ) ){
						reclog( '共有メモリの初期化に失敗しました。', EPGREC_WARN );
						shmop_delete( $shm_id );
						shmop_close( $shm_id );
						if( $ret_mode )
							return FALSE;
						else
							exit;
					}
				}
				return $shm_id;
			}else{
				$php_err = error_get_last();
				$errno   = posix_get_last_error();
				if( ++$cnt < 1000 ){
					if( ( $errno && $errno!=$pre_errno ) || ( !empty( $php_err ) && $php_err!==$pre_err ) ){
						if( $pre_errno || !empty( $pre_err ) )
							sem_log( '共有メモリセグメントの取得に失敗しました。shmop_open', $pre_errno, $pre_err );
						$pre_errno = $errno;
						$pre_err   = $php_err;
					}
					switch( $errno ){
						case EINVAL:	// 指定したサイズが、既存セグメントのサイズより大きいです。指定したサイズが、システムの最低値より小さいか、最大値より大きいです。
						case ENOENT:	// key と一致する共有メモリセグメントがなく、IPC_CREAT が指定されていません。
						case ENOSPC:	// 要求を満たす十分なメモリを、カーネルが割り当てられません。 
//						case EEXIST:	// IPC_CREAT と IPC_EXCL が指定され、 key に対応する共有メモリセグメントがすでに存在します。
							sem_log( '共有メモリセグメントの取得に失敗しました。shmop_open', $errno, $php_err );
							if( $ret_mode )
								return FALSE;
							else
								exit;
							break;
					}
					usleep( 1000 );
				}else{
					sem_log( '共有メモリセグメントの取得に失敗しました。shmop_open', $errno, $php_err );
					if( $ret_mode )
						return FALSE;
					else
						exit;
				}
			}
		}
	}
}

// test code
// 共有メモリセグメントを再取得 (注:共有変数が化けている可能性あり)
function shm_restore( &$shm_id ){
	$restore_box = array();
	for( $cnt=0; $cnt<SEM_MAX; $cnt++ ){
		$read_tmp = shmop_read( $shm_id, $cnt*SHM_SCAL_WIDE, SHM_SCAL_WIDE );
		if( $read_tmp !== FALSE ){
			array_push( $restore_box, $read_tmp );
		}else{
			reclog( '共有メモリの読込みに失敗しました。', EPGREC_WARN );
			while( !shmop_delete( $shm_id ) )	// クローズ時に削除
				usleep( 1000 );
			return FALSE;
		}
	}
	// ここまでくるなら正常だが･･･
	while( !shmop_delete( $shm_id ) )
		usleep( 1000 );
	shmop_close( $shm_id );
	$shm_id = shmop_open_surely( TRUE );
	if( $shm_id !== FALSE ){
		foreach( $restore_box as $cnt => $piece ){
			$sorce   = trim( $piece );
			$src_str = ctype_digit( $sorce ) ? sprintf( '%-'.(SHM_SCAL_WIDE-1).'d', (int)$sorce ) : $piece;
			if( !shmop_write_surely( $shm_id, $cnt+1, $src_str ) ){
				reclog( '共有メモリのリストアに失敗しました。', EPGREC_WARN );
				return FALSE;
			}
		}
		return TRUE;
	}else
		return FALSE;
}

function shmop_read_surely( &$shm_id, $shm_name ){
	$put_cnt   = 0;
	$offset    = ( $shm_name - 1 ) * SHM_SCAL_WIDE;
	$pre_err   = array();
	$pre_errno = 0;
	while(1){
		$read_tmp = shmop_read( $shm_id, $offset, SHM_SCAL_WIDE );
		if( $read_tmp !== FALSE ){
			$sorce = trim( $read_tmp );
			if( ctype_digit( $sorce ) )
				return (int)$sorce;
			else
				return $sorce;
		}else{
			$php_err = error_get_last();
			$errno   = posix_get_last_error();
			if( ( $errno && $errno!=$pre_errno ) || ( !empty( $php_err ) && $php_err!==$pre_err ) ){
				if( $pre_errno || !empty( $pre_err ) )
					sem_log( 'shmop_read', $pre_errno, $pre_err );
				$pre_errno = $errno;
				$pre_err   = $php_err;
			}
			if( ++$put_cnt < 1000 ){
				usleep( 1000 );
			}else{
				if( shm_restore( $shm_id ) ){
					$put_cnt = 0;
					continue;
				}else
					return FALSE;
			}
		}
	}
}

function shmop_write_surely( &$shm_id, $shm_name, $sorce ){
	$put_cnt   = 0;
	$src_str   = sprintf( '%-'.(SHM_SCAL_WIDE-1).'d', $sorce );
	$offset    = ( $shm_name - 1 ) * SHM_SCAL_WIDE;
	$pre_err   = array();
	$pre_errno = 0;
	while(1){
		if( shmop_write( $shm_id, $src_str, $offset ) === FALSE ){
			$php_err = error_get_last();
			$errno   = posix_get_last_error();
			if( ( $errno && $errno!=$pre_errno ) || ( !empty( $php_err ) && $php_err!==$pre_err ) ){
				if( $pre_errno || !empty( $pre_err ) )
					sem_log( 'shmop_write', $pre_errno, $pre_err );
				$pre_errno = $errno;
				$pre_err   = $php_err;
			}
			if( ++$put_cnt < 1000 ){
				usleep( 1000 );
			}else{
				if( shm_restore( $shm_id ) ){
					$put_cnt = 0;
					continue;
				}else
					return FALSE;
			}
		}else{
			$get_cnt   = 0;
			$pre_err   = array();
			$pre_errno = 0;
			while(1){
				usleep( 1000 );
				$read_tmp = shmop_read( $shm_id, $offset, SHM_SCAL_WIDE );
				if( $read_tmp!==FALSE && (int)$read_tmp==$sorce )
					return TRUE;
				else{
					$php_err = error_get_last();
					$errno   = posix_get_last_error();
					if( ( $errno && $errno!=$pre_errno ) || ( !empty( $php_err ) && $php_err!==$pre_err ) ){
						if( $pre_errno || !empty( $pre_err ) )
							sem_log( 'comp loop('.$get_cnt.'):'.$shm_name.'['.$src_str.'='.$read_tmp.'('.strlen($read_tmp).')] shmop_write', $pre_errno, $pre_err );
						$pre_errno = $errno;
						$pre_err   = $php_err;
					}
					if( ++$get_cnt >= 1000 ){
						if( shm_restore( $shm_id ) ){
							$put_cnt = 0;
							continue 2;
						}else
							return FALSE;
					}
				}
			}
		}
	}
}

function putProgramHtml( $src, $type, $channel_id, $genre, $sub_genre ){
	if( $src !== "" ){
		$out_title = trim($src);
		if( strpos( $out_title, ' #' ) === FALSE ){
			$delimiter = strpos( $out_title, '「' )===FALSE ? '' : '「';
		}else
			$delimiter = ' #';
		if( $delimiter !== '' ){
			$keyword = explode( $delimiter, $out_title );
			if( $keyword[0] === '' )
				$keyword[0] = $out_title;
		}else
			$keyword[0] = $out_title;
		return 'programTable.php?search='.rawurlencode(str_replace( ' ', '%', $keyword[0] )).'&type='.$type.'&station='.$channel_id.'&category_id='.$genre.'&sub_genre='.$sub_genre;
	}else
		return '';
}

function parse_time( $time_char )
{
	$time_stk = $cnt = 0;
	if( strncmp( $time_char, '-', 1 ) == 0 ){
		$flag = -1;
		$time_char = substr( $time_char, 1 );
	}else
		$flag = 1;
	$times = explode( ':', $time_char );
	switch( count( $times ) ){
		case 1:
			$time_stk = (int)($times[0] * 60);
			break;
		case 3:
			$time_stk = (int)$times[$cnt++] * 60;
		case 2:
			$time_stk += (int)$times[$cnt++];
			$time_stk *= 60;
			$time_stk += (int)$times[$cnt];
			break;
	}
	return $time_stk * $flag;
}

function transTime( $second, $view=FALSE )
{
	if( $second < 0 ){
		$second *= -1;
		$flag = '-';
	}else
		$flag = '';
	if( $second % 60 || $view )
		return $flag.sprintf( '%02d:%02d:%02d', $second/3600, (int)($second/60)%60, $second%60 );
	else
		return $flag.($second/60);
}

function get_device_name( $dvnum )
{
	$drtype = $dvnum >> 8;
	$drnum  = $dvnum & 0x0ff;
	if( $drtype ){
		// 環境依存かも・・・
		$rd_arr = file( '/sys/dev/block/'.$drtype.':'.$drnum.'/uevent', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if( $rd_arr !== FALSE ){
			foreach( $rd_arr as $rd_tg ){
				if( strncmp( $rd_tg, 'DEVNAME=', 8 ) == 0 )
					return '/dev/'.substr( $rd_tg, 8 );
			}
		}
		return $drtype==8 ? '/dev/sd'.chr(0x61+($drnum>>4)).($drnum&0x0f) : $drtype.':'.$drnum;
	}else
		return 'tmpfs(0:'.$drnum.')';
}

function get_directrys( $spool_path )
{
	$dir_collection = '';
	$files          = scandir( $spool_path );
	if( $files !== FALSE ){
		foreach( $files as $entry ){
			if( $entry[0] !== '.' ){
				$entry_path = $spool_path.'/'.$entry;
				$stat       = stat( $entry_path );
				if( is_dir( $entry_path ) &&
						(( ($stat['mode']&0300)===0300 && $stat['uid']===posix_getuid() ) ||
						 ( ($stat['mode']&0030)===0030 && $stat['gid']===posix_getgid() ) ||
						   ($stat['mode']&0003)===0003 ) )
					$dir_collection .= '<option value="'.htmlspecialchars($entry,ENT_QUOTES).'"></option>';
			}
		}
	}
	return $dir_collection;
}

function make_pager( $link, $separate_records, $total, $page, $option='' )
{
	if( $total > $separate_records ){
		$page_limit = (int)($total / $separate_records);
		if( $total % $separate_records )
			$page_limit++;
		$cnt        = $page<=4 ? 1 : $page-4;
		$loop_limit = $cnt + 9;
		if( $loop_limit >= $page_limit ){
			$loop_limit = $page_limit;
			$cnt = $loop_limit-9>1 ? $loop_limit-9 : 1;
		}
		$link  = ' href="'.$link.'?'.$option.'page=';
		$pager = '<div style="text-align: right;">| <a';
		if( $page > 1 )
			$pager .= $link.'1"';
		$pager .= '>1</a> | <a';
		if( $page_limit > 10 ){
			if( $page > 10 )
				$pager .= $link.($page-10).'"';
			$pager .= '>-10</a> | <a';
		}
		if( $page > 1 )
			$pager .= $link.($page-1).'"';
		$pager .= '>&lt;</a> |';
		do{
			$pager .= '<a'.($cnt!==$page ? $link.$cnt.'"' : ' style="color: white; background-color: royalblue;"').'> '.$cnt.' </a>|';
		}while( ++$cnt <= $loop_limit );
		$pager .= ' <a';
		if( $page < $page_limit )
			$pager .= $link.($page+1).'"';
		$pager .= '>&gt;</a> | <a';
		if( $page_limit > 10 ){
			if( $page+9  < $page_limit )
				$pager .= $link.($page+10).'"';
			$pager .= '>+10</a> | <a';
		}
		if( $page < $page_limit )
			$pager .= $link.$page_limit.'"';
		$pager .= '>'.$page_limit.'</a> || <a'.$link.'-">全表示</a> |</div>';
		return $pager;
	}else
		return '';
}

function at_clean( $r, $settings, $resv_cancel=FALSE )
{
	global $RECORD_MODE;

	if( $resv_cancel || strpos( $RECORD_MODE[$r['mode']]['suffix'], '.ts' )!==FALSE ){
		// 残留AT削除
		while(1){
			$ret_cd     = system( $settings->atrm . ' ' . $r['job'], $var_ret );
			$search_job = $r['job'].' ';
			$search_own = posix_getlogin();
			$rarr       = explode( "\n", str_replace( "\t", ' ', shell_exec( $settings->at.'q' ) ) );
			foreach( $rarr as $str_var ){
				if( strncmp( $str_var, $search_job, strlen( $search_job ) ) == 0 ){
					if( strpos( $str_var, $search_own ) !== FALSE )
						continue 2;
					else{
						reclog( '[予約ID:'.$r['id'].($resv_cancel ? ' 削除中止(' : ' 終了化中止(予約開始失敗・').'AT['.$r['job'].']削除失敗)] ('.
								$search_own.')以外でJOBが登録されている['.$str_var.']', EPGREC_ERROR );
						return 2;
					}
				}
			}
			if( $ret_cd!==FALSE && $var_ret==0 ){
				if( $resv_cancel )
					reclog( '[予約ID:'.$r['id'].' 削除] '.
						$r['channel_disc'].'(T'.$r['tuner'].'-'.$r['channel'].') '.$r['starttime'].' 『'.$r['title'].'』' );
				else
					reclog( '[予約ID:'.$r['id'].' 終了化(予約開始失敗・AT['.$r['job'].']残留)] '.
						$r['channel_disc'].'(T'.$r['tuner'].'-'.$r['channel'].') '.$r['starttime'].' 『'.$r['title'].'』', EPGREC_ERROR );
			}else
				reclog( '[予約ID:'.$r['id'].' 終了化(予約開始失敗・AT['.$r['job'].']無残留)] '.
					$r['channel_disc'].'(T'.$r['tuner'].'-'.$r['channel'].') '.$r['starttime'].' 『'.$r['title'].'』', EPGREC_ERROR );
			break;
		}
		return 0;
	}else
		// トランスコード中には手をつけない(将来的にはdo-record.shから分離するので今はこれだけ)
		return 1;
}

function link_menu_create( $mode = 'none' )
{
	global $settings,$NET_AREA,$SELECTED_CHANNEL_MAP;

	include( INSTALL_PATH . '/settings/menu_list.php' );

	if( $mode !== 'INDEX' ){
		$link_add = array();
		if( (int)$settings->gr_tuners > 0 )
			$link_add[] = array( 'name' => '地上デジタル番組表', 'url' => 'index.php' );
		if( (int)$settings->bs_tuners > 0 ){
			$link_add[] = array( 'name' => 'BSデジタル番組表', 'url' => 'index.php?type=BS' );
			if( (boolean)$settings->cs_rec_flg )
				$link_add[] = array( 'name' => 'CSデジタル番組表', 'url' => 'index.php?type=CS' );
		}
		if( EXTRA_TUNERS )
			$link_add[] = array( 'name' => EXTRA_NAME.'番組表', 'url' => 'index.php?type=EX' );
		if( isset($SELECTED_CHANNEL_MAP) )
			$link_add[] = array( 'name' => '選別番組表', 'url' => 'index.php?type=SELECT' );
		$MENU_LIST = array_merge( $link_add, $MENU_LIST );
	}
	// 間欠運用
	if( (int)$settings->use_power_reduce != 0 ){
		include( INSTALL_PATH . '/powerReduce.inc.php' );

		if( $NET_AREA === 'H' ){
			$wakeupvars = power_settings();
			if( $wakeupvars->reason !== OTHER )
				power_reduce( STAY );	// 間欠運用の一時停止
		}
		$wakeupvars = power_settings();
		switch( $wakeupvars->reason ){
			case GETEPG:
				$power_stat = '間欠運用一時停止(現:EPG更新中)';
				$power_cmd  = STAY;
				break;
			case RESERVE:
				$power_stat = '間欠運用一時停止(現:録画中)';
				$power_cmd  = STAY;
				break;
			case REPAIREPG:
				$power_stat = '間欠運用強制再開(現:EPG修正中)';
				$power_cmd  = FORCE;
				break;
			case OTHER:
				$power_stat = '間欠運用再開(>電源停止)';
				$power_cmd  = RESUME;
				break;
		}
		$MENU_LIST[] = array( 'url' => 'epgwakealarm.php?mode='.$power_cmd, 'name' => $power_stat );
	}
	return $MENU_LIST;
}

function storage_free_space( $path )
{
	$piece = explode( '/', $path );
	array_pop( $piece );
	$dir_path  = implode( '/', $piece );
	$disk_size = disk_free_space( $dir_path );

	$mail_settings = INSTALL_PATH.'/settings/mail_config.php';
	if( file_exists( $mail_settings ) ){
		$sem_id = sem_get_surely( SEM_DISKCHECK );
		while(1){
			if( sem_acquire( $sem_id ) === TRUE ){
				include_once( $mail_settings );

				if( DATA_UNIT_RADIX_BINARY ){
					$unit_radix = 1024;
					$byte_unit  = 'GiB';
				}else{
					$unit_radix = 1000;
					$byte_unit  = 'GB';
				}
				$stat           = stat( $dir_path );
				$dvnum          = (int)$stat['dev'];
				$dname          = get_device_name( $dvnum );
				$disk_nm        = str_replace( '/dev/', '', $dname );
				$disk_stat_path = INSTALL_PATH.'/settings/disk_'.$disk_nm;
				$warning_limit  = array_key_exists( $disk_nm, $DISK_LIMIT_SET ) ? $DISK_LIMIT_SET[$disk_nm] : $DISK_LIMIT_SET['default'];
				$space_lmt      = $warning_limit * $unit_radix * $unit_radix * $unit_radix;
				if( $disk_size < $space_lmt ){
					$now_date = date( 'Ymd' );
					if( file_exists( $disk_stat_path ) && $now_date===trim( file_get_contents( $disk_stat_path ) ) ){
						while( sem_release( $sem_id ) === FALSE )
							usleep( 100 );
						return $disk_size;
					}
					$trans = array( '%DISKNAME%'  => $dname,
									'%DISKLIMIT%' => $warning_limit.$byte_unit,
									'%DISKSIZE%'  => number_format( ((int)$disk_size/($unit_radix*$unit_radix))/$unit_radix, 1 ).$byte_unit,
							);
					$gen_message = strtr( DISK_LIMIT_MASSAGE, $trans );
					$gen_from    = FROM_NAME!=='' ? 'From: '.FROM_NAME : null;
					if( mb_send_mail( SEND_TO, DISK_LIMIT_SUBJECT, $gen_message, $gen_from ) ){
						$handle = fopen( $disk_stat_path, 'w' );
						fwrite( $handle, $now_date );
						fclose( $handle );
					}else
						$gen_message .= '<br>メール送信に失敗しました。';
					$gen_message = str_replace( "\n", '<br>', str_replace( "\r", '', $gen_message ) );
					reclog( $gen_message, EPGREC_WARN );
				}else
					if( file_exists( $disk_stat_path ) )
						unlink( $disk_stat_path );
				while( sem_release( $sem_id ) === FALSE )
					usleep( 100 );
				break;
			}
			usleep( 100 );
		}
	}
	return $disk_size;
}

function rate_time( $minute )
{
	$minute /= TS_STREAM_RATE;
	return sprintf( '%dh%02dm', $minute/60, $minute%60 );
}

function spool_freesize(){
	global $settings;

	if( VIEW_DISK_FREE_SIZE ){
		if( DATA_UNIT_RADIX_BINARY ){
			$unit_radix = 1024;
			$byte_unit  = 'iB';
		}else{
			$unit_radix = 1000;
			$byte_unit  = 'B';
		}
		$spool_path = INSTALL_PATH.$settings->spool;
		// スプール･ルート･ストレージの空き容量保存
		$root_mega = $free_mega = (int)( disk_free_space( $spool_path ) / ( $unit_radix * $unit_radix ) );
		$stat  = stat( $spool_path );
		$dvnum = (int)$stat['dev'];
		$devs  = array( $dvnum );
		// スプール･ルート上にある全ストレージの空き容量取得
		$files = scandir( $spool_path );
		if( $files !== FALSE ){
			array_splice( $files, 0, 2 );
			foreach( $files as $entry ){
				$entry_path = $spool_path.'/'.$entry;
				if( is_link( $entry_path ) && is_dir( $entry_path ) ){
					$stat  = stat( $entry_path );
					$dvnum = (int)$stat['dev'];
					if( !in_array( $dvnum, $devs ) ){
						$entry_mega   = (int)( disk_free_space( $entry_path ) / ( $unit_radix * $unit_radix ) );
						$free_mega   += $entry_mega;
						array_push( $devs, array( $dvnum ) );
					}
				}
			}
		}
		return '<a style=" font-size:120%;font-weight: bold;">'.number_format( $free_mega/$unit_radix, 1 ).'G'.$byte_unit.'</a>('.rate_time( $free_mega ).')';
	}else
		return '';
}

// return	0:成功 1:実行失敗 2:タイムアウト
function exe_start( $cmd, $wait_lp, $start_wt=0, $rst_view=TRUE ){
	$descspec = array(
					0 => array( 'file','/dev/null','r' ),
					1 => array( 'pipe','w' ),
					2 => array( 'pipe','w' ),
	);
	$pro = proc_open( $cmd, $descspec, $pipes );
	if( is_resource( $pro ) ){
		$wait_lp += $start_wt;
		$wait_cnt = 0;
		while(1){
			$st = proc_get_status( $pro );
			if( $st['running'] == FALSE ){
				$rststk = '';
				$rstring = trim( stream_get_contents( $pipes[1] ) );
				if( $rstring !== '' )
					$rststk = '<br>'.str_replace( "\n", '<br>', $rstring );
				fclose( $pipes[1] );
				$rstring = trim( stream_get_contents( $pipes[2] ) );
				if( $rstring !== '' )
					$rststk .= '<br>'.str_replace( "\n", '<br>', $rstring );
				fclose( $pipes[2] );
				proc_close( $pro );
				if( $st['exitcode'] !== 0 ){
					reclog( 'command error['.$st['exitcode'].'] '.$cmd.$rststk, EPGREC_WARN );
					return 2;
				}else
					if( $rst_view && $rststk!=='' )
						reclog( 'command rst['.$cmd.']'.$rststk, EPGREC_WARN );
				return 0;
			}else
				if( $wait_cnt < $wait_lp )
					sleep( 1 );
				else{
					//タイムアウト
					$rststk = '';
					$rstring = trim( stream_get_contents( $pipes[1] ) );
					if( $rstring !== '' )
						$rststk = '<br>'.str_replace( "\n", '<br>', $rstring );
					fclose( $pipes[1] );
					$rstring = trim( stream_get_contents( $pipes[2] ) );
					if( $rstring !== '' )
						$rststk .= '<br>'.str_replace( "\n", '<br>', $rstring );
					fclose( $pipes[2] );
					proc_terminate( $pro, 9 );
					reclog( 'コマンドがスタックしてる可能性があります['.$cmd.']<br>'.$rststk, EPGREC_WARN );
					return 2;
				}
			$wait_cnt++;
		}
	}else{
		reclog( 'コマンドに異常がある可能性があります<br>'.$cmd, EPGREC_WARN );
		return 1;
	}
}

?>
