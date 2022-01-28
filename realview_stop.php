<?php
  $script_path = dirname( __FILE__ );
  chdir( $script_path );
  include_once( $script_path . '/config.php');
  include_once( INSTALL_PATH . '/reclib.php' );

function searchProces( $pid )
{
// posix_kill( $pid, 0 )でのプロセス判定で不具合が発生
//	posix_kill( $pid, 0 );
//	return = posix_get_last_error();
	$ps_output = shell_exec( PS_CMD );
	$rarr      = explode( "\n", $ps_output );
	for( $cc=0; $cc<count($rarr); $cc++ ){
		if( strpos( $rarr[$cc], (string)$pid ) !== FALSE ){
			$ps = ps_tok( $rarr[$cc] );
			if( (int)$ps->pid == $pid )
				return 0;
		}
	}
	return ESRCH;
}

// $shm_id_rcvがFALSEでない場合、セマフォを立てたままセマフォIDを返す
function realview_stop( $shm_id_rcv = FALSE )
{
	$shm_id = $shm_id_rcv===FALSE ? shmop_open_surely() : $shm_id_rcv;
	$rv_sem = sem_get_surely( SEM_REALVIEW );
	if( $rv_sem === FALSE )
		return FALSE;
	while(1){
		if( sem_acquire( $rv_sem ) === TRUE ){
			// リアルタイム視聴中確認
			$rv_smph = shmop_read_surely( $shm_id, SEM_REALVIEW );
			if( $rv_smph > 0 ){
				if( file_exists( REALVIEW_PID ) ){
					// リアルタイム視聴終了
					$real_view = (int)trim( file_get_contents( REALVIEW_PID ) );
					unlink( REALVIEW_PID );
					while( searchProces( $real_view ) === 0 ){
						posix_kill( $real_view, 9 );		// 録画コマンド停止
						usleep( 100*1000 );
					}
				}
				$sem_id = sem_get_surely( $rv_smph );
				while( sem_acquire( $sem_id ) === FALSE )
					usleep( 100 );
				shmop_write_surely( $shm_id, $rv_smph, 0 );
				while( sem_release( $sem_id ) === FALSE )
					usleep( 100 );
				shmop_write_surely( $shm_id, SEM_REALVIEW, 0 );
			}
			if( $shm_id_rcv === FALSE ){
				while( sem_release( $rv_sem ) === FALSE )
					usleep( 100 );
				shmop_close( $shm_id );
				return TRUE;
			}else
				return $rv_sem;
		}
	}
}
?>
