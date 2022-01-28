<?php

// settings/gr_channel.phpが作成された場合、
// config.php内の$GR_CHANNEL_MAPは無視されます


// 首都圏用地上デジタルチャンネルマップ
// 識別子 => チャンネル番号
$GR_CHANNEL_MAP = array(
	'GR27' => '27',		// NHK
	'GR26' => '26',		// 教育
	'GR25' => '25',		// 日テレ
	'GR22' => '22',		// 東京
	'GR21' => '21',		// フジ
	'GR24' => '24',		// テレ朝
	'GR23' => '23',		// テレ東
//	'GR16' => '16',		// MX TV(スカイツリー)
//	'GR20' => '20',		// MX TV(東京タワー)
//	'GR18' => '18',		// テレ神
	'GR30' => '30',		// 千葉
//	'GR32' => '32',		// テレ玉
	'GR28' => '28',		// 大学
);


/*
// 大阪地区デジタルチャンネルマップ（参考）
$GR_CHANNEL_MAP = array(
	'GR24' => '24',		// NHK
	'GR13' => '13',		// 教育
	'GR16' => '16',		// 毎日
	'GR15' => '15',		// 朝日
	'GR17' => '17',		// 関西
	'GR14' => '14',		// 読売
	'GR18' => '18',		// テレビ大阪
);
*/

/*
// 名古屋地区デジタルチャンネルマップ（参考）
$GR_CHANNEL_MAP = array(
	'GR23' => '23', // TV愛知
	'GR18' => '18', // CBC
	'GR19' => '19', // 中京TV
//	'GR27' => '27', // 三重TV
	'GR21' => '21', // 東海TV
	'GR22' => '22', // 名古屋TV (メ～テレ)
	'GR13' => '13', // NHK Educational
	'GR20' => '20', // NHK Gemeral
);
*/

// 録画モード（option）

$RECORD_MODE = array(
	// ※ 0は必須で、変更不可です。
	0 => array(
		'name' => 'Full TS',	// モードの表示名
		'suffix' => '_fl.ts',	// ファイル名のサフィックス
	),
	// ※ 1は必須で、変更不可です。
	1 => array(
		'name' => 'HD TS',	// 最小構成のTS
		'suffix' => '.ts',
	),
	// ファイル名のサフィックス用
	2 => array(
		'name' => 'SD TS',	// CSのSD用
		'suffix' => 'SD.ts',
	),
);


// 第一チューナー設定(主にPTn)
define( 'TUNER_UNIT1', 0 );							// 各放送波の論理チューナ数(地上波･衛星波で共用 ex.PT1が1枚なら2)
define( 'PT1_CMD_NUM', 0 );							// 録画コマンド指定 $rec_cmds中のどれを使うか選択 DVBドライバーで地デジだけの場合はrecdvbでOKなはず


// 録画コマンド(必ず内容を確認すること)
$rec_cmds = array(
	// PTn(recpt1)
	0 => array(
		'cmd'      => '/usr/local/bin/recpt1',		// コマンドフルパス
		'b25'      => ' --b25 --strip',				// B25オプション
		'sidEXT'   => '',							// 録画時--sid追加オプション
		'falldely' => 0,							// 録画コマンド失敗時のwait(秒)
		'epgTs'    => TRUE,							// EPG用TS出力パッチ使用時はTRUE
		'cntrl'    => TRUE,							// recpt1ctl対応パッチ使用時はTRUE
		'httpS'    => FALSE,						// httpサーバー機能対応時はTRUE
	),
	// DVB(recdvb)
	1 => array(
		'cmd'      => '/usr/local/bin/recdvb',
		'b25'      => ' --b25 --strip',
		'sidEXT'   => '',
		'falldely' => 0,
		'epgTs'    => TRUE,
		'cntrl'    => TRUE,
		'httpS'    => TRUE,
	),
	// recfsusb2n
	2 => array(
		'cmd'      => '/usr/local/bin/recfsusb2n',
		'b25'      => ' --b25',
		'sidEXT'   => '',
		'falldely' => 10,
		'epgTs'    => FALSE,
		'cntrl'    => FALSE,
		'httpS'    => FALSE,
	),
	// recfriio
	3 => array(
		'cmd'      => '/usr/local/bin/recfriio',
		'b25'      => ' --b25',
		'sidEXT'   => '',
		'falldely' => 0,
		'epgTs'    => FALSE,
		'cntrl'    => FALSE,
		'httpS'    => FALSE,
	),
);
define( 'USE_DORECORD', FALSE );			// do-recored.shを使用する場合はTRUE(非推奨)


// PTシリーズ以外のチューナーの個別設定(チューナー数に応じて増やすこと)
$OTHER_TUNERS_CHARA = array(
	// 地デジ
	'GR' => array(
		0 => array(
			'reccmd'   => 0,				// 録画コマンド指定 $rec_cmds中からどれを使うか選択
			'device'   => '',				// デバイス指定する場合にコマンドのオプションも含めて記述
		),
		1 => array(
			'reccmd'   => 0,
			'device'   => '',
		),
	),
	// 衛星(BS/CS)
	'BS' => array(
		0 => array(
			'reccmd'   => 0,
			'device'   => '',
		),
		1 => array(
			'reccmd'   => 0,
			'device'   => '',
		),
	)
);

// スカパー！プレミアム（対応中、ただしハードが無いのでこれ以上の作業不能）
define( 'EXTRA_TUNERS', 0 );					// チューナー数
define( 'EXTRA_NAME', 'スカパー！プレミアム' );	// 放送波名
define( 'EX_EPG_TIME', 240 );					// EPG受信時間
define( 'EX_EPG_CHANNEL',  'CS15_0'  );			// EPG受信Ch
$EX_TUNERS_CHARA = array(
	0 => array(
		'reccmd'   => 0,				// 録画コマンド指定 $rec_cmds中からどれを使うか選択
		'device'   => '',				// デバイス指定する場合にコマンドのオプションも含めて記述
	),
	1 => array(
		'reccmd'   => 0,
		'device'   => '',
	),
);


// リアルタイム視聴
define( 'REALVIEW', FALSE );						// リアルタイム視聴を有効にするときはtrueに(新方式で録画コマンドの標準出力対応が必須・トランスコード対応)
define( 'REALVIEW_HTTP', FALSE );					// HTTPサーバーでリアルタイム視聴をするときはtrueに(旧方式で録画コマンドにHTTPサーバー機能が必須・トランスコード非対応)
define( 'REALVIEW_HTTP_PORT', '8888' ); 			// HTTPサーバーポート番号を入力する
define( 'REALVIEW_PID', '/tmp/realview' );			// リアルタイム視聴チューナーPID保存テンポラリ

// EPG取得関連
define( 'HIDE_CH_EPG_GET', FALSE );					// 非表示チャンネルのEPGを取得するならTRUE
define( 'EXTINCT_CH_AUTO_DELETE', FALSE );			// 廃止チャンネルを自動削除するならTRUE(HIDE_CH_EPG_GET=TRUE時のみに有効・メンテナンス画面あり)

// 自動キーワ－ド予約の警告設定初期値(登録キーワード毎に変更可能・状態発生時に警告をログ出力する)
define( 'CRITERION_CHECK', FALSE );					// 収録時間変動
define( 'REST_ALERT', FALSE );						// 番組がヒットしない場合

// 表示関連設定
define( 'SEPARATE_RECORDS', 50 );					// 1ページ中の表示レコード数・0指定でページ化無効(共通)
define( 'VIEW_OVERLOAD', 0 );						// 1ページ表示での上限を指定数上乗せする(共通)
define( 'SEPARATE_RECORDS_RESERVE', FALSE );		// 1ページ中の表示レコード数・0指定でページ化無効・FALSEは共通を使用(予約一覧用)
define( 'VIEW_OVERLOAD_RESERVE', FALSE );			// 1ページ表示での上限を指定数上乗せする・FALSEは共通を使用(予約一覧用)
define( 'SEPARATE_RECORDS_RECORDED', FALSE );		// 1ページ中の表示レコード数・0指定でページ化無効・FALSEは共通を使用(録画済一覧用)
define( 'VIEW_OVERLOAD_RECORDED', FALSE );			// 1ページ表示での上限を指定数上乗せする・FALSEは共通を使用(録画済一覧用)
define( 'SEPARATE_RECORDS_LOGVIEW', 3000 );			// 1ページ中の表示レコード数・0指定でページ化無効・FALSEは共通を使用(ログ一覧用)

// セキュリティ関連
define( 'SETTING_CHANGE_GIP', FALSE );				// グローバルIPからの設定変更を許可する場合はTRUE
define( 'STREAMURL_INC_PW', FALSE );					// ストリーミングURLにユーザー名とパスワードを含めるか(basic認証でのみ有効・平文のためセキュリティ上、問題あり)
//////////////////////////////////////////////////////////////////////////////
// 以降の変数・定数はほとんどの場合、変更する必要はありません


define( 'INSTALL_PATH', dirname(__FILE__) );		// インストールパス

// 以降は必要に応じて変更する
define( 'MANUAL_REV_PRIORITY', 10 );				// 手動予約の優先度
define( 'HTTPD_USER', 'www-data' );					// HTTPD(apache)アカウント
define( 'HTTPD_GROUP', 'www-data' );					// HTTPD(apache)アカウント
define( 'PADDING_TIME', 180 );						// 詰め物時間(変更禁止)
define( 'DO_RECORD', INSTALL_PATH . '/do-record.sh' );		// レコードスクリプト
define( 'COMPLETE_CMD', INSTALL_PATH . '/recomplete.php' );	// 録画終了コマンド
define( 'GEN_THUMBNAIL', INSTALL_PATH . '/gen-thumbnail.sh' );	// サムネール生成スクリプト
define( 'PS_CMD', 'ps -u '.HTTPD_USER.' -f' );			// HTTPD(apache)アカウントで実行中のコマンドPID取得に使用
define( 'RECPT1_CTL', '/usr/local/bin/recpt1ctl' );		// recpt1のコントロールコマンド
define( 'FIRST_REC', 80 );							// EPG[schedule]受信時間
define( 'SHORT_REC', 6 );							// EPG[p/f]受信時間
define( 'REC_RETRY_LIMIT', 60 );					// 録画再試行時間
define( 'GR_PT1_EPG_SIZE', (int)(1.1*1024*1024) );	// GR EPG TSファイルサイズ(PT1)
define( 'BS_PT1_EPG_SIZE', (int)(5.5*1024*1024) );	// BS EPG TSファイルサイズ(PT1)
define( 'CS_PT1_EPG_SIZE', (int)(4*1024*1024) );	// CS EPG TSファイルサイズ(PT1)
define( 'GR_OTH_EPG_SIZE', (int)(170*1024*1024) );	// GR EPG TSファイルサイズ
define( 'BS_OTH_EPG_SIZE', (int)(170*3*1024*1024) );	// BS EPG TSファイルサイズ
define( 'CS_OTH_EPG_SIZE', (int)(170*2*1024*1024) );	// CS EPG TSファイルサイズ
define( 'GR_XML_SIZE', (int)(300*1024) );	// GR EPG XMLファイルサイズ
define( 'BS_XML_SIZE', (int)(4*1024*1024) );	// BS EPG XMLファイルサイズ
define( 'TS_STREAM_RATE', 110 );					// １分あたりのTSサイズ(MB・ストレージ残り時間計算用)
define( 'DATA_UNIT_RADIX_BINARY', FALSE );			// 基数を1000から1024にする場合にTRUE
define( 'VIEW_DISK_FREE_SIZE', TRUE );				// ヘッダーの録画ストレージ残り残量表示

// PT1_REBOOTをTRUEにする場合は、root権限で visudoコマンドを実行して
// www-data ALL = (ALL) NOPASSWD: /sbin/shutdown
// の一行を追加してください。詳しくは visudoを調べてください。

define( 'PT1_REBOOT', FALSE );							// PT1が不安定なときにリブートするかどうか
define( 'REBOOT_CMD', 'sudo /sbin/shutdown -r now' );	// リブートコマンド
//define( 'REBOOT_CMD', INSTALL_PATH.'/driver_reset.sh' );	// pt1ドライバー再読込み こっちにする場合は、modprobeをHTTPDから使えるようにして
define( 'REBOOT_COMMENT', 'PT2 is out of order: SYSTEM REBOOT ' );

// BS/CSでEPGを取得するチャンネル
// 通常は変える必要はありません
// BSでepgdumpが頻繁に落ちる場合は、受信状態のいいチャンネルに変えることで
// 改善するかもしれません

define( 'BS_EPG_CHANNEL',  'BS15_0'  );	// BS

define( 'CS1_EPG_CHANNEL', 'CS2' );	// CS1 2,8,10
define( 'CS2_EPG_CHANNEL', 'CS4' );	// CS2 4,6,12,14,16,18,20,22,24


// DBテーブル情報　以下は変更しないでください
define( 'RESERVE_TBL',  'reserveTbl' );						// 予約テーブル
define( 'PROGRAM_TBL',  'programTbl' );						// 番組表
define( 'CHANNEL_TBL',  'channelTbl' );						// チャンネルテーブル
define( 'CATEGORY_TBL', 'categoryTbl' );					// カテゴリテーブル
define( 'KEYWORD_TBL', 'keywordTbl' );						// キーワードテーブル
define( 'LOG_TBL', 'logTbl' );								// ログテーブル
define( 'TRANSCODE_TBL', 'transcodeTbl' );					// トランスコードテーブル
define( 'TRANSEXPAND_TBL', 'transexpandTbl' );					// トランスコード拡張設定テーブル

// 全国用BSデジタルチャンネルマップ
check_ch_map( 'bs_channel.php' );
include_once( INSTALL_PATH.'/settings/bs_channel.php' );

// 全国用CSデジタルチャンネルマップ
check_ch_map( 'cs_channel.php' );
include_once( INSTALL_PATH.'/settings/cs_channel.php' );

// スカパー！プレミアム・チャンネルマップ
if( EXTRA_TUNERS ){
	check_ch_map( 'ex_channel.php' );
	include_once( INSTALL_PATH.'/settings/ex_channel.php' );
}

// 地上デジタルチャンネルテーブルsettings/gr_channel.phpが存在するならそれを
// 優先する
if( check_ch_map( 'gr_channel.php', isset( $GR_CHANNEL_MAP ) ) ){
	unset($GR_CHANNEL_MAP);
	include_once( INSTALL_PATH.'/settings/gr_channel.php' );
}

// 選別チャンネルテーブル
if( check_ch_map( 'selected_channel.php', TRUE ) ){
	include( INSTALL_PATH.'/settings/selected_channel.php' );
	if( !count($SELECTED_CHANNEL_MAP) )
		unset($SELECTED_CHANNEL_MAP);
}

// トランスコード設定
if( file_exists( INSTALL_PATH.'/settings/trans_config.php' ) ){
	include_once( INSTALL_PATH.'/settings/trans_config.php' );

	$RECORD_MODE = array_merge( $RECORD_MODE, $TRANS_MODE );
}else{
	define( 'TRANSCODE_STREAM', FALSE );
	$TRANSSIZE_SET = array();
}


// セキュリティ強化
function get_net_area( $src_ip )
{
	$check_addr = strtolower( $src_ip );
	if( strpos( $check_addr, ':' ) !== FALSE ){
		$check_addr = trim( $check_addr, '[]' );
		if( strpos( $check_addr, '.' ) !== FALSE ){		// IPv4射影アドレス/IPv4互換アドレス チェック
			$check_addr = str_replace( (!strncmp( $check_addr, '::ffff:', 7 ) ? '::ffff:' : '::'), '', $check_addr );
			$ipv4 = TRUE;
		}else
			$ipv4 = FALSE;
	}else
		$ipv4 = TRUE;
	if( $ipv4 ){
		// IPv4
		$adrs = explode( '.', $check_addr );
		if( count( $adrs ) !== 4 )
			return 'T';
		foreach( $adrs as $adr ){
			if( !is_numeric($adr) )
				return 'T';
		}
		if( $check_addr === '127.0.0.1' ){
			return 'H';			// local host(loop back)
		}else
		if( strncmp( $check_addr, '192.168.', 8 ) === 0 ){
			return 'C';			// class C
		}else
		if( strncmp($check_addr, '10.', 3 ) === 0 ){
			return 'A';			// class A
		}else{
			if( $adrs[0]==='172' && ((int)$adrs[1]&0xf0)==0x10 )
				return 'B';			// class B
			else
				return 'G';			// global
		}
	}else{
		// IPv6
		if( $check_addr === '::1' ){
			return 'H';			// local host(loop back)
		}else{
			$adrs = explode( ':', $check_addr );
			if( count( $adrs ) === 1 )
				return 'T';
			foreach( $adrs as $adr ){
				if( $adr!=='' && filter_var( '0x'.$adr, FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_HEX )===FALSE )
					return 'T';
			}
			$ip6_top = hexdec( $adrs[0] );
			if( ($ip6_top&0xFE00)===0xFC00 || ($ip6_top&0xFFC0)===0xFE80 )
				return 'P';			// private(ユニークローカルユニキャストアドレス/リンクローカルユニキャストアドレス)
			else
				return 'G';			// global
		}
	}
}
$NET_AREA   = isset( $_SERVER['REMOTE_ADDR'] ) ? get_net_area( $_SERVER['REMOTE_ADDR'] ) : FALSE;
$AUTHORIZED = isset($_SERVER['REMOTE_USER']);

// グローバルIPからのアクセスにHTTP認証を強要
if( $NET_AREA==='G' && !$AUTHORIZED && ( !defined('HTTP_AUTH_GIP') || HTTP_AUTH_GIP ) ){
/*
	echo "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">\n";
	echo "<html><head>\n";
	echo "<title>404 Not Found</title>\n";
	echo "</head><body>\n";
	echo "<h1>Not Found</h1>\n";
	echo "<p>The requested URL ".$_SERVER['PHP_SELF']." was not found on this server.</p>\n";
	echo "<hr>\n";
	echo "<address>".$_SERVER['SERVER_SOFTWARE']." Server at ".$_SERVER['SERVER_ADDR']." Port 80</address>;\n";
	echo "</body></html>\n";
*/
	$host_name = isset( $_SERVER['REMOTE_HOST'] ) ? $_SERVER['REMOTE_HOST'] : 'NONAME';
	$alert_msg = 'グローバルIPからのアクセスにHTTP認証が設定されていません。IP::['.$_SERVER['REMOTE_ADDR'].'('.$host_name.')] SCRIPT::['.$_SERVER['PHP_SELF'].']';
	include_once( INSTALL_PATH . '/DBRecord.class.php' );
	include_once( INSTALL_PATH . '/recLog.inc.php' );
	reclog( $alert_msg, EPGREC_WARN );
	exit;
}

// チャンネルMAPファイルを操作された場合(削除･不正コード挿入など)を想定
// epgrecUNA以外からの操作が可能なため対応
function check_ch_map( $ch_file, $gr_safe=FALSE )
{
	$inc_file = INSTALL_PATH.'/settings/'.$ch_file;
	if( file_exists( $inc_file ) ){
		if( filesize( $inc_file ) > 0 ){
			$rd_data       = file_get_contents( $inc_file );
			list( $type, ) = explode( '_', $ch_file );
			$search        = '$'.strtoupper( $type ).'_CHANNEL_MAP';
			if( strpos( $rd_data, $search )!==FALSE && strpos( $rd_data, ");\n?>" )!==FALSE ){
				if( substr_count( $rd_data, ';' ) == 1 ){
					return TRUE;
				}
			}
		}
	}
	if( $gr_safe )
		return FALSE;
	else{
		include_once( INSTALL_PATH . '/DBRecord.class.php' );
		include_once( INSTALL_PATH . '/recLog.inc.php' );
		reclog( $inc_file.' が壊れているか不正コードが挿入されている可能性があります。ファイルを確認してください。', EPGREC_ERROR );
		exit;
	}
}
?>
