#!/usr/bin/env php
<?php

// ВРЕМЕННО
define('TOP_SECRET_KEY', '123');

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
    exit();
}

posix_setsid();



$socket = stream_socket_server("tcp://185.118.64.163:82", $errno, $errstr);

if (!$socket) {
    die("$errstr ($errno)\n");
}



$map=new Map();



$connects = array();
while (true) {
    $read = $connects;
    $read []= $socket;
    $write = $except = null;

    if (!stream_select($read, $write, $except, null)) {
        break;
    }

    if (in_array($socket, $read)) {//есть новое соединение
        //принимаем новое соединение и производим рукопожатие:
        if (($connect = stream_socket_accept($socket, -1)) && $info = handshake($connect)) {
            $connects[] = $connect;//добавляем его в список необходимых для обработки
            onOpen($connect, $info);//вызываем пользовательский сценарий
        }
        unset($read[ array_search($socket, $read) ]);
    }

    foreach($read as $connect) {//обрабатываем все соединения
        $data = fread($connect, 100000);

        if (!$data) { //соединение было закрыто
            fclose($connect);
            unset($connects[ array_search($connect, $connects) ]);
            onClose($connect);//вызываем пользовательский сценарий
            continue;
        }

        onMessage($connect, $data);//вызываем пользовательский сценарий
    }
}

fclose($server);

function handshake($connect) {
    $info = array();

    $line = fgets($connect);
    $header = explode(' ', $line);
    $info['method'] = $header[0];
    $info['uri'] = $header[1];

    //считываем заголовки из соединения
    while ($line = rtrim(fgets($connect))) {
        if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
            $info[$matches[1]] = $matches[2];
        } else {
            break;
        }
    }

    $address = explode(':', stream_socket_get_name($connect, true)); //получаем адрес клиента
    $info['ip'] = $address[0];
    $info['port'] = $address[1];

    if (empty($info['Sec-WebSocket-Key'])) {
        return false;
    }

    //отправляем заголовок согласно протоколу вебсокета
    $SecWebSocketAccept = base64_encode(pack('H*', sha1($info['Sec-WebSocket-Key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    $upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "Sec-WebSocket-Accept:$SecWebSocketAccept\r\n\r\n";
    fwrite($connect, $upgrade);

    return $info;
}

function encode($payload, $type = 'text', $masked = false)
{
    $frameHead = array();
    $payloadLength = strlen($payload);

    switch ($type) {
        case 'text':
            // first byte indicates FIN, Text-Frame (10000001):
            $frameHead[0] = 129;
            break;

        case 'close':
            // first byte indicates FIN, Close Frame(10001000):
            $frameHead[0] = 136;
            break;

        case 'ping':
            // first byte indicates FIN, Ping frame (10001001):
            $frameHead[0] = 137;
            break;

        case 'pong':
            // first byte indicates FIN, Pong frame (10001010):
            $frameHead[0] = 138;
            break;
    }

    // set mask and payload length (using 1, 3 or 9 bytes)
    if ($payloadLength > 65535) {
        $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
        $frameHead[1] = ($masked === true) ? 255 : 127;
        for ($i = 0; $i < 8; $i++) {
            $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
        }
        // most significant bit MUST be 0
        if ($frameHead[2] > 127) {
            return array('type' => '', 'payload' => '', 'error' => 'frame too large (1004)');
        }
    } elseif ($payloadLength > 125) {
        $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
        $frameHead[1] = ($masked === true) ? 254 : 126;
        $frameHead[2] = bindec($payloadLengthBin[0]);
        $frameHead[3] = bindec($payloadLengthBin[1]);
    } else {
        $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
    }

    // convert frame-head to string:
    foreach (array_keys($frameHead) as $i) {
        $frameHead[$i] = chr($frameHead[$i]);
    }
    if ($masked === true) {
        // generate a random mask:
        $mask = array();
        for ($i = 0; $i < 4; $i++) {
            $mask[$i] = chr(rand(0, 255));
        }

        $frameHead = array_merge($frameHead, $mask);
    }
    $frame = implode('', $frameHead);

    // append payload to frame:
    for ($i = 0; $i < $payloadLength; $i++) {
        $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
    }

    return $frame;
}

function decode($data)
{
    $unmaskedPayload = '';
    $decodedData = array();

    // estimate frame type:
    $firstByteBinary = sprintf('%08b', ord($data[0]));
    $secondByteBinary = sprintf('%08b', ord($data[1]));
    $opcode = bindec(substr($firstByteBinary, 4, 4));
    $isMasked = ($secondByteBinary[0] == '1') ? true : false;
    $payloadLength = ord($data[1]) & 127;

    // unmasked frame is received:
    if (!$isMasked) {
        return array('type' => '', 'payload' => '', 'error' => 'protocol error (1002)');
    }

    switch ($opcode) {
        // text frame:
        case 1:
            $decodedData['type'] = 'text';
            break;

        case 2:
            $decodedData['type'] = 'binary';
            break;

        // connection close frame:
        case 8:
            $decodedData['type'] = 'close';
            break;

        // ping frame:
        case 9:
            $decodedData['type'] = 'ping';
            break;

        // pong frame:
        case 10:
            $decodedData['type'] = 'pong';
            break;

        default:
            return array('type' => '', 'payload' => '', 'error' => 'unknown opcode (1003)');
    }

    if ($payloadLength === 126) {
        $mask = substr($data, 4, 4);
        $payloadOffset = 8;
        $dataLength = bindec(sprintf('%08b', ord($data[2])) . sprintf('%08b', ord($data[3]))) + $payloadOffset;
    } elseif ($payloadLength === 127) {
        $mask = substr($data, 10, 4);
        $payloadOffset = 14;
        $tmp = '';
        for ($i = 0; $i < 8; $i++) {
            $tmp .= sprintf('%08b', ord($data[$i + 2]));
        }
        $dataLength = bindec($tmp) + $payloadOffset;
        unset($tmp);
    } else {
        $mask = substr($data, 2, 4);
        $payloadOffset = 6;
        $dataLength = $payloadLength + $payloadOffset;
    }

    /**
     * We have to check for large frames here. socket_recv cuts at 1024 bytes
     * so if websocket-frame is > 1024 bytes we have to wait until whole
     * data is transferd.
     */
    if (strlen($data) < $dataLength) {
        return false;
    }

    if ($isMasked) {
        for ($i = $payloadOffset; $i < $dataLength; $i++) {
            $j = $i - $payloadOffset;
            if (isset($data[$i])) {
                $unmaskedPayload .= $data[$i] ^ $mask[$j % 4];
            }
        }
        $decodedData['payload'] = $unmaskedPayload;
    } else {
        $payloadOffset = $payloadOffset - 4;
        $decodedData['payload'] = substr($data, $payloadOffset);
    }

    return $decodedData;
}

//пользовательские сценарии:

function onOpen($connect, $info) {
    echo "open\n";
    fwrite($connect, encode('Привет'));
}

function onClose($connect) {
	global $map;
	$map->delUser('', $connect);
    echo "close\n";
}

function onMessage($connect, $d) {

	global $map;

	
	$data=json_decode(decode($d)['payload'], true);
	
	
print_r($data);

	$result=array();

	if((!isset($data['secret_key']))&&(!$map->isUser($data['key'])))
		return false;


	switch($data['c'])
	{
		case 'addUser':
			if($data['secret_key']==TOP_SECRET_KEY)
			{
				$result=$map->addUser($data['d']['login'], $data['d']['key'], $connect);
				return $result;                                                           // может break???
			}
			break;
		case 'loadUser':
			$result=array('c'=>'load', 'd'=>$map->getUser($data['key']));
			break;
		case 'upd':
			//break;
		case 'move':
			$result=$map->moveItem($data['key'], $data['d']);
			if(!$result)
				$result=array('err'=>'move');
			//break;
		default: 
			$result['last']=$map->history->i;
			if($data['from'])
			{
				$result['h']=$map->history->get($data['from']);       // лучше переписать ->get()
				$tl=count($result['h']);
				for($tk=0;$tk<$tl;$tk++)
					if($result['h'][$tk]['key']==$result['key'])
						unset($result['h'][$tk]);

			}
			else
				$result['h']=$map->getAllItemsCoords($data['key']);
			if(!$result['h'])
			{
				unset($result['h']);
				break;
			}
			break;
	}

//print_r($result);

    fwrite($connect, encode(json_encode($result)));
}




abstract class iMap
{
	function addUser($login, $key, $socket, $param=array()){}  // добавление пользователя/объекта 
	public function delUser($key='', $socket=''){}  // добавление пользователя/объекта 
	public function isUser($key){}    // проверка существования объекта по ключу
	public function getUser($key){}	// данные для передачи клиентам
	private function setKey($key){}	// генерация публичного ключа
	private function getIDByKey($public_key){}  // реальный ключ(ид) по публичному ключу
	function moveItem($key, $d){}	// переместить объект
	public function getAllItemsCoords($key, $step=0){}	// выбор действий после $step или всех объектов с координатами
		// проверяет и возвращает объекты на расстоянии $r 
		//Если $mode=0 возвращается false при любом объекте рядом, иначе список объектов 
	private function checkCoords(&$p, $r=0, $Iam=0, $mode=0){}	

	//private function delKey();
}




class history
{
	const HISTORY_LEN=200;  
	private $d=array();
	public $i=0;

	public function add($item, $pos, $type)
	{
		if(++$this->i > self::HISTORY_LEN)
			$this->i=0;
		$this->d[$this->i]=array('c'=>$type, 'key'=>"$item", 'pos'=>$pos);


print "all_history\n";
print_r($this->d);
print "end_all_history\n";


		return $this->d[$this->i];
	}

	public function get($from=0)
	{
		if($from>$this->i)
			return array_merge(array_slice($this->d, $from), array_slice($this->d, 0, $this->i));
		else
			return array_slice($this->d, $from, $this->i - $from);
	}
}



class Map extends iMap
{

	private $sMap=array();
	private $iList=array();
	private $moveHistory=array();
	private $hStep=1;               // шаг истории
	private $keyList=array();
	public $history=null;
	
	function __construct($m='./map1.map')
	{
		//$f=file_get_contents($m);
		$this->history=new history();
	}


	function addUser($login, $key, $socket, $param=array())
	{
		if($this->isUser($key))
			return false;
		if(empty($param))                           // соединить массив "по умолчанию" и массив личных данных. В личных передаем, например, позицию, по умолчанию (грузим откуда-то) колдауны и т.д.
			$param=array(
				'login'=>$login,
				'public_key'=>$this->setKey($key),  // публичный идентификатор элемента для передачи всем клиентам
				'x'=>0,
				'y'=>0,
				'size'=>15,
				'speed'=>5,
				'cdm'=>0.5,  // кулдаун движения, сек
				'cds'=>1, // кулдаун умений
				'cdm_l'=>microtime(true),  // последнее движение
				'cds_l'=>microtime(true),
				'mtype'=>0,   // проходимый
				'vrange'=>200,   // дальность видимости
				'socket'=>$socket, 
			);
		if($this->checkCoords($param))
			$this->iList[$key]=$param;
		else
			return false;

		return $this->history->add($param['public_key'], array($param['x'], $param['y']), 'add');
	}

	public function delUser($key='', $socket='')
	{
		if((!$key)&&(!$socket))
		{
			print "Err here1 \n";
			return false;
		}
		if($key)
		{
			$p_key=$this->iList[$key]['public_key'];
			unset($this->iList[$key]);
		}
		else
			foreach($this->iList as $k=>$v)
			{
				if($v['socket']==$socket)
				{
					$p_key=$this->iList[$k]['public_key'];
					unset($this->iList[$k]);
					break;
				}
			}
		if(!$p_key)
		{
			print "Err here2 \n";
			return false;
		}
		$this->history->add($p_key, array('0', '0'), 'del');
		return true;
	}

	public function isUser($key)
	{
		if(empty($key))
			return false;
		if(isset($this->iList[$key]))
			return true;
		else
			return false;
	}

	
	public function getUser($key)
	{
		$data=array(
			'x'=>$this->iList[$key]['x'],
			'y'=>$this->iList[$key]['y'],
			'size'=>$this->iList[$key]['size'],
			'speed'=>$this->iList[$key]['speed'],
			'p_key'=>$this->iList[$key]['public_key'],
		);
		return $data;
	}

	private function setKey($key)
	{
		$nk=count($this->keyList)+1;
		$this->keyList[$nk]=$key;
		return $nk;
	}

	private function getIDByKey($public_key)
	{
		return $this->keyList[$public_key];
	}


	function moveItem($key, $d)
	{
		// колдаун движения для обеспечения правильной скорости

//print microtime(true).' / '.$this->iList[$key]['cdm_l'].' / '.$this->iList[$key]['cdm']."\n";
		if(microtime(true)-$this->iList[$key]['cdm_l']<$this->iList[$key]['cdm'])
			return false;
		$this->iList[$key]['cdm_l']=microtime(true);

		$tmp=array(
			'x'=>$this->iList[$key]['x'] + $d[0] * $this->iList[$key]['speed'],
			'y'=>$this->iList[$key]['y'] + $d[1] * $this->iList[$key]['speed'],
			'size'=>$this->iList[$key]['size'],
		);
		if($this->checkCoords($tmp, 0, $key))
		{
			$this->iList[$key]['x']=$tmp['x'];
			$this->iList[$key]['y']=$tmp['y'];
			return $this->history->add($this->iList[$key]['public_key'], $tmp, 'move');
		}
		else
			return false;
	}


	public function getAllItemsCoords($key, $step=0)
	{
		$r=array();
		if(!$step)
			foreach($this->iList as $k=>$item)
				if($k!=$key)
					$r[$item['public_key']]=array('x'=>$item['x'], 'y'=>$item['y']);
		if(count($r)<1)
			return false;
		return array('u'=>$r);
	}
	

	
	private function checkCoords(&$p, $r=0, $Iam=0, $mode=0)
	{
		if($mode)
			$result=array();
		foreach($this->iList as $k=>$item)
		{
			if($k==$Iam)
				continue;
			// если объект проходымый (mtype=0) пропускаем 
			if(($item['mtype'])||((!$item['mtype'])&&($mode)))    
				if(sqrt(pow($p['x']-$item['x'])+pow($p['y']-$item['y']))<=$item['size']+$p['size']+$r)
					if(!$mode)
					{
						return false;
					}
					else
						$result[$k]=1;

		}

		if(!$mode)
			return true;
		else
			return $result;
	}
}
