<?php
include_once( '../config.php');
include_once( INSTALL_PATH.'/Settings.class.php' );

$settings = Settings::factory();

if( isset( $_GET['time'] ) )
	$rec_time = $_GET['time'];
else
	exit();

echo 'EPGの初回受信を行います。'.$rec_time.'分程度後に<a href="'.$settings->install_url.'">epgrecのトップページ</a>を開いてください。';

if( isset( $_GET['script'] ) ){
	$host_name = isset( $_SERVER['REMOTE_HOST'] ) ? $_SERVER['REMOTE_HOST'] : 'NONAME';
	$alert_msg = '不法侵入者による攻撃を受けました。['.$host_name.'('.$_SERVER['REMOTE_ADDR'].")]\nSCRIPT::[".$_GET['script'].']';
	reclog( $alert_msg, EPGREC_WARN );
	file_put_contents( INSTALL_PATH.$settings->spool.'/alert.log', date('Y-m-d H:i:s').' '.$alert_msg."\n", FILE_APPEND );
	syslog( LOG_WARNING, $alert_msg );
}else
	@exec( INSTALL_PATH.'/shepherd.php >/dev/null 2>&1 &' );
exit();

?>