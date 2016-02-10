#!/usr/bin/php
<?

set_time_limit(0); 
ob_implicit_flush(); 
declare(ticks = 1); 

$baseDir = dirname(__FILE__);
ini_set('error_log',$baseDir.'/error.log');
fclose(STDIN);
fclose(STDOUT);
fclose(STDERR);
$STDIN = fopen('/dev/null', 'r');
$STDOUT = fopen($baseDir.'/application.log', 'w');
$STDERR = fopen($baseDir.'/daemon.log', 'w');



$child_pid = pcntl_fork();


if ($child_pid) {
    // Выходим из родительского, привязанного к консоли, процесса
    exit();
}

posix_setsid();
if(($socket=socket_create(AF_INET, SOCK_STREAM, SOL_TCP))===false)
{
	echo "Не удалось выполнить socket_create(): причина: " . socket_strerror(socket_last_error()) . "\n";
	exit();
}
if(!socket_bind($socket, "127.0.0.1", 82))
{
	echo "Не удалось выполнить socket_bind(): причина: " . socket_strerror(socket_last_error()) . "\n";
	exit();
}
if(!socket_listen($socket, 100))
{
	echo "Не удалось выполнить socket_listen(): причина: " . socket_strerror(socket_last_error()) . "\n";
	exit();
}
if(!socket_set_nonblock($socket))
{
	echo "Не удалось выполнить socket_set_nonblock(): причина: " . socket_strerror(socket_last_error()) . "\n";
	exit();
}


$clients=array($socket);

while(true)
{
	$read = $clients;
	if (socket_select($read, $write = NULL, $except = NULL, 0) < 1)
            continue;
    if (in_array($sock, $read)) 
	{
		$clients[] = $newsock = socket_accept($sock);
		socket_write($newsock, "no noobs, but ill make an exception :)\n".
				"There are ".(count($clients) - 1)." client(s) connected to the server\n");
				
		socket_getpeername($newsock, $ip);
		echo "New client connected: {$ip}\n";
		$key = array_search($sock, $read);
		unset($read[$key]);
	}
}
foreach($clients as $user)
	socket_close($user);

//socket_close($socket);  in clients

?>