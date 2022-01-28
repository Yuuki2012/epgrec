#!/usr/bin/php
<?php
	exit( function_exists( 'shmop_open' ).':'
			.function_exists( 'sem_get' ).':'
			.function_exists( 'pcntl_setpriority' ).':'
			.function_exists( 'pcntl_signal(' ).':'
			.function_exists( 'pcntl_fork' ).':'
			.function_exists( 'pcntl_sigtimedwait' )
		);
?>
