<!DOCTYPE html>
<html>
<head>

<meta http-equiv="content-type" content="text/html; charset=utf-8">

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/2.2.0/jquery.min.js"></script>


<script>
$(document).ready(function(){


	function AnimateAll()
	{
		for(var i in window.cMap.itemList)
		{
			var item=window.cMap.itemList[i];
			var shift=parseInt($(item.domItem).css('background-position-x'));
			if(shift>=60)
				$(item.domItem).css('background-position-x', 0);
			else
				$(item.domItem).css('background-position-x', shift+30);
		};

		var t=new Date();
		var tmp=t.getTime().toString()+t.getMilliseconds().toString();
		if(window.param.lastT+2000<tmp)                                                // если запросов от клиента долго не было, посылаем запрос на обновление
		{ 
			window.param.lastT=tmp;
			socket.send(JSON.stringify({"c":"upd", "key":window.param.myKey, "from":window.param.lastD}));
		}
		window.timer=setTimeout(AnimateAll, 300);

	}

	function animate(id, pos)
	{
		$('#player'+id).animate({left:pos['x'], top:pos['y']}, 150);
		return true;
	}

	function map(domItem)
	{
		this.itemCount=0;
		this.domItem=$('#'+domItem);
		this.itemList=[];
	}

	map.prototype.getdBlockCount=function()
	{
		return this.itemCount;
	}
	map.prototype.increasedBlockCount=function()
	{
		return this.itemCount++;
	}

	map.prototype.addObject=function()
	{
		this.itemCount++;
		return true;
	}

	function dBlock(map, itemclass, coords, itemid, socket)
	{
		this.speed=5;
		this.direction=[0, 1];
		this.id=map.increasedBlockCount();
		this.mapLink=map;
		this.isMoving=false;
		this.key=itemid.replace(/[^0-9]*/, '');
		this.mapLink.itemList[itemid]=this;

		var item='<div id="'+itemid+'" class="'+itemclass+'"></div>';
		this.domItem=$(item).appendTo(this.mapLink.domItem);
		this.domItem.css('left', coords[0]);
		this.domItem.css('top', coords[1]);
		this.socket=socket;
	}

	dBlock.prototype.die=function()
	{
		this.domItem.remove();
		delete this.mapLink.itemList[this.key];
		delete window.itemList[this.key];
	}

	dBlock.prototype.startMovedBlock=function(direction)
	{
		if(this.isMoving)
			return false;
		this.socket.send(JSON.stringify({"c":"move", "key":window.param.myKey, "d":direction, "from":window.param.lastD}));
		this.isMoving=true;  // ушел запрос на обработку движения, пока не обработается новые запросы не слать
		return true;
	}
	dBlock.prototype.stopMovedBlock=function(direction)
	{
		return true;
	}
	dBlock.prototype.movedBlock=function()
	{
		this.domItem.css('left', parseInt(this.domItem.css('left')) + this.direction[0]*this.speed);
		this.domItem.css('top', parseInt(this.domItem.css('top')) + this.direction[1]*this.speed);
		return true;
	}



	$(document).on('keydown', function(key){
		if(key.keyCode==38)  //up
			window.player.startMovedBlock([0, -1]);
		if(key.keyCode==40)  //down
			window.player.startMovedBlock([0, 1]);
		if(key.keyCode==37)  //left
			window.player.startMovedBlock([-1, 0]);
		if(key.keyCode==39)  //right
			window.player.startMovedBlock([1, 0]);
	});

	$(document).on('keyup', function(key){
		if((key.keyCode==38)||(key.keyCode==40)||(key.keyCode==37)||(key.keyCode==39))  //up
			window.player.stopMovedBlock();
	});



window.param=new Object();



var socket = new WebSocket("ws://packmen.ddns.net:82/daemon/main.php");
socket.onopen = function() {
  console.log("Соединение установлено.");

	// ВРЕМЕННО   addUser системное добавление убрать, только loadUser
	var rnd=Math.round(Math.random()*(1000)+1000);

	window.param.myKey=rnd; 
	socket.send(JSON.stringify({"c":"addUser", "secret_key":"123", "d":{"login":"mihail", "key":rnd}}));


	//socket.send(JSON.stringify({"c":"loadUser", "key":rnd}));    // вызывается ниже, позже создание через php, а load тут

};

socket.onclose = function(event) {
  if (event.wasClean) {
    console.log('Соединение закрыто чисто');
  } else {
    console.log('Обрыв соединения'); // например, "убит" процесс сервера
  }
  console.log('Код: ' + event.code + ' причина: ' + event.reason);
};

socket.onmessage = function(event) {
	console.log("Получены данные " + event.data);

	if(event.data[0]=='{')
		var data=JSON.parse(event.data);
	else
	{                                          // какой-то не значимый ответ сервера, например "ок"
		var data={"c":event.data};										// где-то тут приходит ответ о создании пользователя
		socket.send(JSON.stringify({"c":"loadUser", "key":window.param.myKey}));
		return true;
	}

	if(typeof data.last != undefined)
		window.param.lastD=data.last;

	if(data.c=='add')
	{
		// EndLoad(data.p_key);
		window.itemList[data.d.p_key]=new dBlock(window.cMap, 'player object', [data.d.x, data.d.y], 'player'+data.d.p_key, socket);
		
	}
	if(data.c=='load')                     // window.player еще не создан, поэтому return, иначе ошибка
	{
		EndLoad(data.d.p_key);
		return true;
	}
	if(data.c=='move')
	{
		animate(data.key, data.pos);
	}


	if(data.h !== undefined)            // только обновление
	{
		if(data.h.u !== undefined)          // список всех элементов (юзеров) карты
		{
			for(var i in data.h.u)                     // зачем всем передается сокет??? ну пусть будет
			{                                          // все данные "новые", поэтому только создаем 
					window.itemList[i]=new dBlock(window.cMap, 'player object', [data.h.u[i].x, data.h.u[i].y], 'player'+data.h.u[i].key, socket);
			}
		}
		else                           // только обновление  IT'S HERE
		{
			for(var i in data.h)                     // зачем всем передается сокет??? ну пусть будет
			{
				if(data.h[i].c=='add')                 
					window.itemList[i]=new dBlock(window.cMap, 'player object', [data.h[i].x, data.h[i].y], 'player'+data.h[i].key, socket);
				if(data.h[i].c=='move')
					animate(data.h[i].key, data.h[i].pos);
				if(data.h[i].c=='del')
				{
			console.log(window.itemList);
					window.itemList[data.h[i].key].die();
			console.log(window.itemList);
				}
			}
		}
	}

	var t=new Date();
	var tmp=t.getTime().toString()+t.getMilliseconds().toString();
			
	if(window.player.isMoving)
		window.player.isMoving=false;
	return true;
};

socket.onerror = function(error) {
  console.log("Ошибка " + error.message);
};



function EndLoad(key)
{
	window.cMap=new map('map');
	
	// ВРЕМЕННО 
	window.player=new dBlock(window.cMap, 'player object', [0, 0], 'player'+key, socket);
	window.itemList=[];
	window.param.lastD=0;
	window.param.lastT=0;

	
		
	console.log(window.cMap);
	console.log(window.player);
}

	$('#start').on('click', function(){
		window.timer=setTimeout(AnimateAll, 200);
	});
	$('#stop').on('click', function(){
		clearTimeout(window.timer);
	});


});
</script>


<style>
	body { margin:0; }
	.container { background: #cfc; width:100%; height:100%; }
	#map {
		position:relative;
		border:2px black solid;
		background: url('/img/78.jpg');
		width:800px;
		height:600px;
		margin: auto;
	}
	.player { 
		position:absolute; 
		top:0;
		left:0;
		background: url('/img/pm2.png');
		width:30px;
		height:30px;
	}
</style>


<title><?='php_test'?></title>

</head>
<body>
<??>
<div class="container">
	<button id="start">Старт!</button>
	<button id="stop">Стоп!</button>
	<div id="map">
	</div>

</div>
</body>
</html>