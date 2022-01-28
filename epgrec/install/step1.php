<?php

// パーミッションを返す
function getPerm( $file ) {
	
	$ss = @stat( $file );
	return sprintf('%o', ($ss['mode'] & 000777));
}

$exit_stat = FALSE;
echo '<p><b>PHPのインストール状態をチェックします</b></p>';

list( $chk_shmop, $chk_sem, $chk_pcntl_setpriority, $chk_pcntl_signal, $chk_pcntl_fork, $chk_pcntl_sigtimedwait ) = explode( ':', trim( exec( './chk_function.php' ) ) );
if( $chk_shmop==='0' || $chk_sem==='0' || $chk_pcntl_setpriority==='0' || $chk_pcntl_signal==='0' || $chk_pcntl_fork==='0' || $chk_pcntl_sigtimedwait==='0' ){
	if( $chk_shmop==='0' )
		echo 'PHP関数shmop_open()を利用できません<br>PHPからsystemVセマフォを操作できません<br>';
	if( $chk_sem==='0' )
		echo 'PHP関数sem_get()を利用できません<br>PHPから共有メモリを操作できません<br>';
	if( $chk_pcntl_setpriority==='0' )
		echo 'PHP関数pcntl_setpriority()を利用できません<br>PHPのPCNTLプロセス制御機能を利用できません<br>';
	if( $chk_pcntl_signal==='0' )
		echo 'PHP関数pcntl_signal()を利用できません<br>PHPのPCNTLプロセス制御機能を利用できません<br>';
	if( $chk_pcntl_pcntl_fork==='0' )
		echo 'PHP関数pcntl_fork()を利用できません<br>PHPのPCNTLプロセス制御機能を利用できません<br>';
	if( $chk_pcntl_sigtimedwait==='0' )
		echo 'PHP関数pcntl_sigtimedwait()を利用できません<br>PHPのPCNTLプロセス制御機能を利用できません<br>';
	echo 'これらのPHP関数が使えるようにしてください。<br>/etc/php5/cli/php.iniのdisable_functionsにこれらの関数が登録されている場合は、削除してください。<br>またモジュール追加やPHPのリビルドが必要かもしれません。<br><br>';
	$exit_stat = TRUE;
}

$php_timezone = date_default_timezone_get();
if( $php_timezone !== 'Asia/Tokyo' ){
	echo 'timezoneが"'.$php_timezone.'"に設定されています。<br>';
	echo '/etc/php5/cli/php.iniと/etc/php5/apache2/php.ini中の date.timezoneを"Asia/Tokyo"に変更してください。<br>;date.timezone =<br>↓<br>date.timezone = "Asia/Tokyo"<br><br>';
	$exit_stat = TRUE;
}

echo '<p><b>epgrecのインストール状態をチェックします</b></p>';

// config.phpの存在確認

if(! file_exists( '../config.php' ) ) {
	@copy( '../config.php.sample', '../config.php' );
	if( ! file_exists( '../config.php' ) ) {
		echo 'config.phpが存在しません<br>config.php.sampleをリネームし地上デジタルチャンネルマップを編集してください<br><br>';
		$exit_stat = TRUE;
	}
}

if( $exit_stat )
	exit( '一旦、初期起動を終了します。<br>' );

include('../config.php');
include_once(INSTALL_PATH.'/reclib.php');


$run_gid   = posix_getgid();
$run_ginfo = posix_getgrgid( $run_gid );
$usr_ginfo = posix_getgrnam( HTTPD_GROUP );
$usr_stat  = posix_getpwuid( posix_getuid() );
if( $usr_ginfo===FALSE || $run_gid!==$usr_ginfo['gid'] ){
	echo 'config.phpのHTTPD_GROUPの設定が違います。'.$run_ginfo['name'].'に変更してください。<br>';
	$exit_stat = TRUE;
}
if( HTTPD_USER !== $usr_stat['name'] ){
	echo 'config.phpのHTTPD_USERの設定が違います。'.$usr_stat['name'].'に変更してください。<br>';
	$exit_stat = TRUE;
}

// do-record.shの存在チェック
/*
if(! file_exists( DO_RECORD ) ) {
	exit('do-record.shが存在しません<br>do-record.sh.pt1やdo-record.sh.friioを参考に作成してください<br>' );
}
*/

// パーミッションチェック

$rw_dirs = array( 
	INSTALL_PATH.'/templates_c',
	INSTALL_PATH.'/video',
	INSTALL_PATH.'/thumbs',
	INSTALL_PATH.'/settings',
	INSTALL_PATH.'/cache',
);

$gen_thumbnail = INSTALL_PATH.'/gen-thumbnail.sh';
if( defined('GEN_THUMBNAIL') )
	$gen_thumbnail = GEN_THUMBNAIL;


$exec_files = array(
	COMPLETE_CMD,
	INSTALL_PATH.'/shepherd.php',
	INSTALL_PATH.'/sheepdog.php',
	INSTALL_PATH.'/collie.php',
	INSTALL_PATH.'/airwavesSheep.php',
	INSTALL_PATH.'/trans_manager.php',
	INSTALL_PATH.'/scoutEpg.php',
	INSTALL_PATH.'/repairEpg.php',
	INSTALL_PATH.'/showEXmem.php',
	INSTALL_PATH.'/resetEXmem.php',
	INSTALL_PATH.'/epgwakealarm.php',
	$gen_thumbnail,
);

echo '<p><b>ディレクトリのパーミッションチェック（777）</b></p>';
echo '<div>';
foreach($rw_dirs as $value ) {
	echo $value;
	
	$perm = getPerm( $value );
	if( $perm != '777' ) {
		echo '<font color="red">...'.$perm.'... missing</font> このディレクトリを書き込み許可にしてください（ex. chmod 777 '.$value.'）<br>';
		$exit_stat = TRUE;
	}else
		echo '...'.$perm.'...ok<br>';
}
echo '</div>';


echo '<p><b>ファイルのパーミッションチェック（755）</b></p>';
echo '<div>';
foreach($exec_files as $value ) {
	echo $value;
	
	$perm = getPerm( $value );
	if( !($perm == '755' || $perm == '775' || $perm == '777') ) {
		echo '<font color="red">...'.$perm.'... missing</font> このファイルを実行可にしてください（ex. chmod 755 '.$value.'）<br>';
		$exit_stat = TRUE;
	}else
		echo '...'.$perm.'...ok<br>';
}
echo '</div>';

if( $exit_stat )
	exit( '<br>一旦、初期起動を終了します。<br>' );

if( !file_exists( '/usr/local/bin/grscan' ) ) {

	echo '<p><b>地上デジタルチャンネルの設定確認</b></p>';

	echo '<div>現在、config.phpでは以下のチャンネルの受信が設定されています。受信不可能なチャンネルが混ざっていると番組表が表示できません。</div>';

	echo '<ul>';
	foreach( $GR_CHANNEL_MAP as $key => $value ) {
		echo '<li>物理チャンネル'.$value.'</li>';
	}
	echo '</ul>';

	echo '<p><a href="step2.php">以上を確認し次の設定に進む</a></p>';

}
else {

	echo'<p><b>地上デジタルチャンネルの設定</b><p>';
	echo '
	<form method="post" action="grscan.php" >
	<div>地上デジタルチャンネルスキャンを開始します。スキャンにはおよそ10～20分程度はかかります。ケーブルテレビをお使いの方は下のチェックボックスをオンにしてください</div>
	  <div>ケーブルテレビを使用:<input type="checkbox" name="catv" value="1" /></div>

	  <input type="submit" value="スキャンを開始する" />
	</form>';
}
?>
