<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Smarty/Smarty.class.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/Keyword.class.php' );

$settings = Settings::factory();

// 曜日
$weekofdays = array(
					array( 'name' => '月', 'value' => 0, 'checked' => '' ),
					array( 'name' => '火', 'value' => 1, 'checked' => '' ),
					array( 'name' => '水', 'value' => 2, 'checked' => '' ),
					array( 'name' => '木', 'value' => 3, 'checked' => '' ),
					array( 'name' => '金', 'value' => 4, 'checked' => '' ),
					array( 'name' => '土', 'value' => 5, 'checked' => '' ),
					array( 'name' => '日', 'value' => 6, 'checked' => '' )
);
$week_tb = array( '日', '月', '火', '水', '木', '金', '土' );


$autorec_modes = $RECORD_MODE;
$autorec_mode  = (int)$settings->autorec_mode;
$cs_rec_flg    = (boolean)$settings->cs_rec_flg;

$kw_enable = TRUE;
$overlap   = FALSE;
$search = '';
$use_regexp = 0;
$ena_title  = FALSE;
$ena_desc   = FALSE;
$collate_ci = FALSE;
$typeGR      = TRUE;
$typeBS      = TRUE;
$typeCS      = TRUE;
$typeEX      = TRUE;
$first_genre = 1;
$category_id = 0;
$sub_genre   = 16;
$channel_id = 0;
$weekofday = 0;
$prgtime = 24;
$period        = 1;
$sft_start     = 0;
$sft_end       = 0;
$discontinuity = 0;
$priority      = 10;
$keyword_id    = 0;
$do_keyword = 0;
$filename = $settings->filename_format;
$spool    = $settings->spool.'/';
$directory = '';
$criterion_dura = 0;
$criterion_enab = CRITERION_CHECK;
$rest_alert     = REST_ALERT;
$smart_repeat   = FALSE;
$split_time     = 0;
$trans_set      = '';

try{
	$stations  = array();
	$chid_list = array();
	$stations[0]['id']       = $chid_list[0] = 0;
	$stations[0]['name']     = 'すべて';
	$stations[0]['type']     = 'ALL';
	$stations[0]['selected'] = '';
	$crecs = DBRecord::createRecords( CHANNEL_TBL, 'WHERE type=\'GR\' AND skip=0 ORDER BY id' );
	foreach( $crecs as $c ) {
		$arr = array();
		$arr['id']       = $chid_list[] = (int)$c->id;
		$arr['name']     = $c->name;
		$arr['type']     = 'GR';
		$arr['selected'] = '';
		array_push( $stations, $arr );
	}
	$crecs = DBRecord::createRecords( CHANNEL_TBL, 'WHERE type=\'BS\' AND skip=0 ORDER BY sid' );
	foreach( $crecs as $c ) {
		$arr = array();
		$arr['id']       = $chid_list[] = (int)$c->id;
		$arr['name']     = $c->name;
		$arr['type']     = 'BS';
		$arr['selected'] = '';
		array_push( $stations, $arr );
	}
	$crecs = DBRecord::createRecords( CHANNEL_TBL, 'WHERE type=\'CS\' AND skip=0 ORDER BY sid' );
	foreach( $crecs as $c ) {
		$arr = array();
		$arr['id']       = $chid_list[] = (int)$c->id;
		$arr['name']     = $c->name;
		$arr['type']     = 'CS';
		$arr['selected'] = '';
		array_push( $stations, $arr );
	}
	$crecs = DBRecord::createRecords( CHANNEL_TBL, 'WHERE type=\'EX\' AND skip=0 ORDER BY sid' );
	foreach( $crecs as $c ) {
		$arr = array();
		$arr['id']       = $chid_list[] = (int)$c->id;
		$arr['name']     = $c->name;
		$arr['type']     = 'EX';
		$arr['selected'] = '';
		array_push( $stations, $arr );
	}
//	$chid_list = array_column( $stations, 'id' );		// PHP5.5
}catch( exception $e ) {
	exit( $e->getMessage() );
}

// パラメータの処理
if(isset( $_POST['do_search'] )) {
	if( isset($_POST['search']) ){
		$search = trim($_POST['search']);
		if( $search != '' ){
			$use_regexp = isset($_POST['use_regexp']);
			if( !$use_regexp )
				$collate_ci = isset($_POST['collate_ci']);
			$ena_title  = isset($_POST['ena_title']);
			$ena_desc   = isset($_POST['ena_desc']);
		}
	}
	if( isset($_POST['station']) )
		$channel_id = (int)($_POST['station']);
	if( $channel_id ){
		switch( $stations[array_search( $channel_id, $chid_list )]['type'] ){
			case 'GR':
				$typeBS = $typeCS = $typeEX = FALSE;
				break;
			case 'BS':
				$typeGR = $typeCS = $typeEX = FALSE;
				break;
			case 'CS':
				$typeGR = $typeBS = $typeEX = FALSE;
				break;
			case 'EX':
				$typeGR = $typeBS = $typeCS = FALSE;
				break;
		}
	}else{
		$typeGR = isset($_POST['typeGR']);
		$typeBS = isset($_POST['typeBS']);
		$typeCS = isset($_POST['typeCS']);
		$typeEX = isset($_POST['typeEX']);
	}
	if( isset($_POST['category_id']) ){
		$category_id = (int)($_POST['category_id']);
		$first_genre = !isset($_POST['first_genre']);
		if( isset($_POST['sub_genre']) )
			$sub_genre = (int)($_POST['sub_genre']);
	}
	if( isset($_POST['week0']) )
		$weekofday += 0x1;
	if( isset($_POST['week1']) )
		$weekofday += 0x2;
	if( isset($_POST['week2']) )
		$weekofday += 0x4;
	if( isset($_POST['week3']) )
		$weekofday += 0x8;
	if( isset($_POST['week4']) )
		$weekofday += 0x10;
	if( isset($_POST['week5']) )
		$weekofday += 0x20;
	if( isset($_POST['week6']) )
		$weekofday += 0x40;
	if( isset($_POST['prgtime']) )
		$prgtime = (int)($_POST['prgtime']);
	if( isset($_POST['period']) )
		$period = (int)($_POST['period']);
	if( isset($_POST['keyword_id']) ){
		$keyword_id = (int)($_POST['keyword_id']);
		if( $keyword_id ){
			if( isset($_POST['kw_enable']) )
				$kw_enable = (boolean)$_POST['kw_enable'];
			if( isset($_POST['sft_start']) )
				$sft_start = transTime( parse_time( $_POST['sft_start'] ) );
			if( isset($_POST['sft_end']) ){
				if( $_POST['sft_end'][0] === '@' )
					$sft_end = '@'.transTime( parse_time(  substr( $_POST['sft_end'], 1 ) ) );
				else
					$sft_end = transTime( parse_time( $_POST['sft_end'] ) );
			}
			if( isset($_POST['discontinuity']) )
				$discontinuity = (boolean)$_POST['discontinuity'];
			if( isset($_POST['priority']) )
				$priority = (int)($_POST['priority']);
			if( isset($_POST['overlap']) )
				$overlap = (boolean)$_POST['overlap'];
			if( isset($_POST['filename']) )
				$filename = $_POST['filename'];
			if( isset($_POST['directory']) )
				$directory = $_POST['directory'];
			if( isset($_POST['autorec_mode']) )
				$autorec_mode = (int)($_POST['autorec_mode']);
			if( isset($_POST['rest_alert']) )
				$rest_alert = (boolean)$_POST['rest_alert'];
			if( isset($_POST['criterion_enab']) )
				$criterion_enab = (boolean)$_POST['criterion_enab'];
			if( isset($_POST['smart_repeat']) )
				$smart_repeat = (boolean)$_POST['smart_repeat'];
			if( isset($_POST['split_time']) )
				$split_time = parse_time( $_POST['split_time'] );
		}
	}
	$do_keyword = 1;
}else{
	if( isset($_GET['keyword_id']) ) {
		$keyword_id    = (int)($_GET['keyword_id']);
		if( DBRecord::countRecords( KEYWORD_TBL, 'WHERE id='.$keyword_id ) == 0 ){
//			echo '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body onLoad="history.back()"></body></html>';
			echo '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body onLoad="var ref = document.referrer;var key = \'keywordTable.php\';if( ref.indexOf(key) > -1 ){location.href = key;}else{if( ref.indexOf(\'programTable.php\') > -1 ){location.href = key;}else{location.href = ref;}}"></body></html>';
			exit( 1 );
		}
		$keyc          = new DBRecord( KEYWORD_TBL, 'id', $keyword_id );
		$search        = $keyc->keyword;
		$use_regexp    = (int)($keyc->use_regexp);
		$ena_title     = (boolean)$keyc->ena_title;
		$ena_desc      = (boolean)$keyc->ena_desc;
		$collate_ci    = (boolean)$keyc->collate_ci;
		$kw_enable     = (boolean)$keyc->kw_enable;
		$typeGR        = (boolean)$keyc->typeGR;
		$typeBS        = (boolean)$keyc->typeBS;
		$typeCS        = (boolean)$keyc->typeCS;
		$typeEX        = (boolean)$keyc->typeEX;
		$channel_id    = (int)($keyc->channel_id);
		$category_id   = (int)($keyc->category_id);
		$first_genre   = (boolean)($keyc->first_genre);
		$sub_genre     = (int)($keyc->sub_genre);
		$weekofday     = (int)($keyc->weekofdays);
		$prgtime       = (int)($keyc->prgtime);
		$period        = (int)$keyc->period;
		$sft_start     = transTime( $keyc->sft_start );
		$sft_end       = ((boolean)$keyc->duration_chg ? '@':'').transTime( $keyc->sft_end );
		$discontinuity = (int)($keyc->discontinuity);
		$priority      = (int)($keyc->priority);
		$overlap       = (boolean)$keyc->overlap;
		$filename      = $keyc->filename_format;
		$directory     = $keyc->directory;
		$criterion_dura = (int)$keyc->criterion_dura;
		$criterion_enab = $criterion_dura ? TRUE : FALSE;
		$rest_alert    = (int)$keyc->rest_alert==0 ? FALSE : TRUE;
		$smart_repeat  = (boolean)$keyc->smart_repeat;
		$split_time    = (int)$keyc->split_time;
		$autorec_mode  = (int)$keyc->autorec_mode;
		$do_keyword = 1;
	}else{
		if( isset($_GET['search'])){
			$search = trim($_GET['search']);
			if( $search != '' ){
				if( isset($_GET['use_regexp']) && ($_GET['use_regexp']) ) {
					$use_regexp = (int)($_GET['use_regexp']);
				}
				if( !$use_regexp && isset($_GET['collate_ci']) )
					$collate_ci = (boolean)$_GET['collate_ci'];
				if( isset($_GET['ena_title'])){
					$ena_title = (boolean)$_GET['ena_title'];
				}else
					$ena_title = TRUE;
				if( isset($_GET['ena_desc'])){
					$ena_desc = (boolean)$_GET['ena_desc'];
				}else
					$ena_desc = FALSE;
				$do_keyword = 1;
			}
		}
		if( isset($_GET['station'])) {
			$channel_id = (int)($_GET['station']);
			if( $channel_id ){
				switch( $stations[array_search( $channel_id, $chid_list )]['type'] ){
					case 'GR':
						$typeBS = $typeCS = $typeEX = FALSE;
						break;
					case 'BS':
						$typeGR = $typeCS = $typeEX = FALSE;
						break;
					case 'CS':
						$typeGR = $typeBS = $typeEX = FALSE;
						break;
					case 'EX':
						$typeGR = $typeBS = $typeCS = FALSE;
						break;
				}
				$do_keyword = 1;
			}
		}
		if( !$channel_id && isset($_GET['type'])) {
			switch( $_GET['type'] ){
				case 'GR';
					$typeBS = FALSE;
					$typeCS = FALSE;
					$typeEX = FALSE;
					break;
				case 'BS';
					$typeGR = FALSE;
					$typeCS = FALSE;
					$typeEX = FALSE;
					break;
				case 'CS';
					$typeGR = FALSE;
					$typeBS = FALSE;
					$typeEX = FALSE;
					break;
				case 'EX';
					$typeGR = FALSE;
					$typeBS = FALSE;
					$typeCS = FALSE;
					break;
			}
			$do_keyword = 1;
		}
		if( isset($_GET['category_id'])) {
			$category_id = (int)($_GET['category_id']);
			if( isset($_GET['sub_genre'])) {
				$sub_genre = (int)($_GET['sub_genre']);
			}
			$do_keyword = 1;
		}
	}
}

$id_selected                        = array_search( $channel_id, $chid_list );
$stations[$id_selected]['selected'] = 'selected';

if( !$typeGR && !$typeBS && !$typeCS && !$typeEX ){
	$typeGR = TRUE;
	$typeBS = TRUE;
	if( $cs_rec_flg )
		$typeCS = TRUE;
	if( EXTRA_TUNERS > 0 )
		$typeEX = TRUE;
}

if( $search!=NULL && !$ena_title && !$ena_desc ){
	$ena_title  = TRUE;
	$ena_desc   = TRUE;
}

if( $weekofday == 0 )
	$weekofday = 0x7f;

try{
	$programs = array();
if( $do_keyword ){
	$precs = Keyword::search( $search, $use_regexp, $collate_ci, $ena_title, $ena_desc, $typeGR, $typeBS, $typeCS, $typeEX, $category_id, $channel_id, $weekofday, $prgtime, $period, $sub_genre, $first_genre );
	
	foreach( $precs as $p ){
	try{
		$arr = array();
		$arr['type'] = $p->type;
		$arr['station_name'] = $stations[array_search( (int)$p->channel_id, $chid_list )]['name'];
		$start_time = toTimestamp($p->starttime);
		$end_time = toTimestamp($p->endtime);
		$duration = $end_time - $start_time;
		if( $duration > $criterion_dura )
			$criterion_dura = $duration;
		$arr['date'] = date( 'm/d(', $start_time ).$week_tb[date( 'w', $start_time )].')';
		$arr['starttime'] = date( 'H:i:s-', $start_time );
		$arr['endtime'] = date( 'H:i:s', $end_time );
		$arr['duration'] = date( 'H:i:s', $duration-9*60*60 );
		$arr['prg_top'] = date( 'YmdH', $start_time-60*60*1 );
		$arr['title'] = $p->title;
		$arr['description'] = $p->description;
		$arr['id']  = $p->id;
		$arr['cat'] = $p->category_id;
		$rev        = DBRecord::createRecords(RESERVE_TBL, 'WHERE program_id='.$p->id.' AND complete=0 ORDER BY starttime ASC');
		$rec_cnt    =  count( $rev );
		if( $rec_cnt ){
			$arr['excl'] = $rec_cnt;
			if( $keyword_id ){
				foreach( $rev as $r ){
					if( (int)$r->autorec == $keyword_id ){
						$arr['rev_id'] = $r->id;
						$arr['rec']    = $r->tuner + 1;
						$arr['key_id'] = $keyword_id;
						goto EXIT_REV;
					}
				}
				unset( $r );
			}
			foreach( $rev as $r ){
				// 複数の場合はどうする？排他のみはID付きが1つだけなので判別可能
				if( (int)$r->autorec ){
					$arr['rev_id'] = $r->id;
					$arr['rec']    = $r->tuner + 1;
					$arr['key_id'] = (int)$r->autorec;
					goto EXIT_REV;
				}
			}
			$arr['rev_id'] = $rev[0]->id;
			$arr['rec']    = $rev[0]->tuner + 1;
			$arr['key_id'] = 0;
		}else{
			$arr['excl']   = 0;
			$arr['rev_id'] = 0;
			$arr['rec']    = 0;
			$arr['key_id'] = 0;
		}
EXIT_REV:;
		$arr['autorec'] = $p->autorec;
		$arr['keyword'] = putProgramHtml( $arr['title'], $p->type, $p->channel_id, $p->category_id, $p->sub_genre );
		array_push( $programs, $arr );
	}catch( exception $e ){}
	}
}
	if( $criterion_dura===0 && $criterion_enab )
		$criterion_dura = 1;

	$k_category_name = '';
	$crecs = DBRecord::createRecords(CATEGORY_TBL);
	$cats = array();
	$cats[0]['id'] = 0;
	$cats[0]['name'] = 'すべて';
	$cats[0]['selected'] = $category_id == 0 ? 'selected' : '';
	foreach( $crecs as $c ) {
		$arr = array();
		$arr['id'] = $c->id;
		$arr['name'] = $c->name_jp;
		$arr['selected'] = $c->id == $category_id ? 'selected' : '';
		if( $c->id == $category_id ) $k_category_name = $c->name_jp;
		array_push( $cats, $arr );
	}
	
	$types = array();
	$type_names = '';
	if( $settings->gr_tuners != 0 ) {
		$arr = array();
		$arr['name'] = 'GR';
		$arr['value'] = 'GR';
		if( $typeGR ){
			$arr['checked'] = 'checked="checked"';
			$type_names     = 'GR';
		}else
			$arr['checked'] =  '';
		array_push( $types, $arr );
	}
	if( $settings->bs_tuners != 0 ) {
		$arr = array();
		$arr['name'] = 'BS';
		$arr['value'] = 'BS';
		if( $typeBS ){
			$arr['checked'] = 'checked="checked"';
			if( $type_names != '' )
				$type_names .= '+';
			$type_names    .= 'BS';
		}else
			$arr['checked'] =  '';
		array_push( $types, $arr );

		// CS
		if( $cs_rec_flg ){
			$arr = array();
			$arr['name'] = 'CS';
			$arr['value'] = 'CS';
			if( $typeCS ){
				$arr['checked'] = 'checked="checked"';
				if( $type_names != '' )
					$type_names .= '+';
				$type_names    .= 'CS';
			}else
				$arr['checked'] =  '';
			array_push( $types, $arr );
		}
	}
	if( EXTRA_TUNERS > 0 ){
		$arr = array();
		$arr['name'] = 'EX';
		$arr['value'] = 'EX';
		if( $typeEX ){
			$arr['checked'] = 'checked="checked"';
			if( $type_names != '' )
				$type_names .= '+';
			$type_names    .= 'EX';
		}else
			$arr['checked'] =  '';
		array_push( $types, $arr );
	}

	$wds_name = '';
	for( $b_cnt=0; $b_cnt<7; $b_cnt++ ){
		if( $weekofday & ( 0x01 << $b_cnt ) ){
			$weekofdays[$b_cnt]['checked'] = 'checked="checked"' ;
			$wds_name                     .= $weekofdays[$b_cnt]['name'];
		}
	}
	// 時間帯
	$prgtimes = array();
	for( $i=0; $i < 25; $i++ ) {
		array_push( $prgtimes, 
			array(  'name' => ( $i == 24  ? 'なし' : sprintf('%d時',$i) ),
					'value' => $i,
					'selected' =>  ( $i == $prgtime ? 'selected' : '' ) )
		);
	}
	// 時間幅
	$periods = array();
	for( $i=1; $i < 24; $i++ ) {
		array_push( $periods, 
			array(  'name' => sprintf('%d時間',$i),
					'value' => $i,
					'selected' =>  ( $i===$period ? 'selected' : '' ) )
		);
	}

	// ディレクトリ
	$dir_collection = get_directrys( INSTALL_PATH.$settings->spool );

	// トランスコード設定
	if( array_key_exists( 'tsuffix', end($autorec_modes) ) ){
		if( $keyword_id ){
			$trans_obj = new DBRecord( TRANSEXPAND_TBL );
			$trans_ex  = $trans_obj->fetch_array( null, null, 'key_id='.$keyword_id.' ORDER BY type_no' );
		}else
			$trans_ex  = array();
		for( $loop=count($trans_ex); $loop<TRANS_SET_KEYWD; $loop++ ){
			$arr = array();
			$arr['mode']   = 0;
			$arr['ts_del'] = FALSE;
			$arr['dir']    = '';
			$trans_ex[]    = $arr;
		}

		$trans_path = str_replace( '%VIDEO%', INSTALL_PATH.'/video', TRANS_ROOT );
		$path_html  = htmlspecialchars( $trans_path, ENT_QUOTES );
		$tsdel      = FALSE;
		$trans_set  = '<fieldset><legend><b>トランスコード設定</b></legend>';
		foreach( $trans_ex as $key => $trans_unit ){
			$trans_set .= '<b>設定'.($key+1).':</b> <b>モード</b><select name="k_trans_mode'.$key.'" >';
			$trans_set .= '<option value="0"'.( $trans_unit['mode']===0 ? ' selected ':'' ).'>未指定</option>';
			foreach( $autorec_modes as $loop => $mode ){
				if( isset($mode['tsuffix']) )
					$trans_set .= '<option value="'.$loop.'"'.( (int)$trans_unit['mode']===$loop ? ' selected ':'' ).'>'.$mode['name'].'</option>';
			}
			$trans_set .= '</select> <b>保存ディレクトリー </b>'.$path_html.
					'/<input type="text" name="k_transdir'.$key.'" value="'.htmlspecialchars($trans_unit['dir'],ENT_QUOTES).'" size="80" class="required" list="trans_ex"><br>';
			if( $trans_unit['ts_del'] )
				$tsdel = TRUE;
		}
		$trans_set .= '<datalist id="trans_ex">'.get_directrys( $trans_path ).'</datalist>';
		$trans_set .= '<input type="checkbox" name="k_auto_del" value="1" '.($tsdel ? 'checked="checked"' : '').'><b>元ファイルの自動削除</b></fieldset>';
	}

	// 録画設定一覧からトランスコード設定を削除
	foreach( $autorec_modes as $loop => $mode ){
		if( isset($mode['tsuffix']) && $autorec_mode<$loop ){
			array_splice( $autorec_modes, $loop );
			break;
		}
	}
	$autorec_modes[$autorec_mode]['selected'] = 'selected';


	$smarty = new Smarty();
	$smarty->assign('sitetitle', !$keyword_id ? '番組検索' : '自動録画キーワード編集 №'.$keyword_id );
	$smarty->assign( 'menu_list', link_menu_create() );
	$smarty->assign( 'spool_freesize', spool_freesize() );
	$smarty->assign( 'do_keyword', $do_keyword );
	$smarty->assign( 'programs', $programs );
	$smarty->assign( 'cats', $cats );
	$smarty->assign( 'k_category', $category_id );
	$smarty->assign( 'k_category_name', $k_category_name );
	$smarty->assign( 'k_sub_genre', $sub_genre );
	$smarty->assign( 'first_genre', $first_genre );
	$smarty->assign( 'types', $types );
	$smarty->assign( 'kw_enable', $kw_enable );
	$smarty->assign( 'overlap', $overlap );
	$smarty->assign( 'k_typeGR', $typeGR );
	$smarty->assign( 'k_typeBS', $typeBS );
	$smarty->assign( 'k_typeCS', $typeCS );
	$smarty->assign( 'k_typeEX', $typeEX );
	$smarty->assign( 'type_names', $type_names );
	$smarty->assign( 'search' , $search );
	$smarty->assign( 'use_regexp', $use_regexp );
	$smarty->assign( 'ena_title', $ena_title );
	$smarty->assign( 'ena_desc', $ena_desc );
	$smarty->assign( 'collate_ci', $collate_ci );
	$smarty->assign( 'stations', $stations );
	$smarty->assign( 'k_station', $channel_id );
	$smarty->assign( 'k_station_name', $stations[$id_selected]['name'] );
	$smarty->assign( 'weekofday', $weekofday );
	$smarty->assign( 'wds_name', $wds_name );
	$smarty->assign( 'weekofdays', $weekofdays );
	$smarty->assign( 'autorec_modes', $autorec_modes );
	$smarty->assign( 'autorec_mode', $autorec_mode );
	$smarty->assign( 'prgtimes', $prgtimes );
	$smarty->assign( 'prgtime', $prgtime );
	$smarty->assign( 'periods', $periods );
	$smarty->assign( 'period', $period );
	$smarty->assign( 'keyword_id', $keyword_id );
	$smarty->assign( 'sft_start', $sft_start );
	$smarty->assign( 'sft_end', $sft_end );
	$smarty->assign( 'discontinuity', $discontinuity );
	$smarty->assign( 'priority', $priority );
	$smarty->assign( 'filename', $filename );
	$smarty->assign( 'spool', $spool );
	$smarty->assign( 'directory', $directory );
	$smarty->assign( 'dir_collection', $dir_collection );
	$smarty->assign( 'criterion_dura', $criterion_dura );
	$smarty->assign( 'criterion_enab', $criterion_enab );
	$smarty->assign( 'rest_alert', $rest_alert );
	$smarty->assign( 'smart_repeat', $smart_repeat );
	$smarty->assign( 'split_time', transTime($split_time) );
	$smarty->assign( 'trans_set', $trans_set );
	$smarty->display('programTable.html');
}
catch( exception $e ) {
	exit( $e->getMessage() );
}
?>
