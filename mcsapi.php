<?php
class McsCache
{
	var $fname;
	var $pdo;
	function __construct($fname="sqlite:mcs.db")
	{
		try{
			$this->pdo = new PDO($fname);
			if (!$this->pdo){
			    die('Sqlite Error:'.$sqliteerror);
			}
		}catch (PDOException $e){
		    var_dump($e->getMessage());
		}
	}
	function getStatus($host_name,$host_port)
	{	
		$sql = "select * from status where server_name=? AND server_port=?";
		$a=array($host_name,$host_port);
		$stmt = $this->pdo->prepare($sql);
		for($i=0;$i<3;$i++){
			$flag = $stmt->execute($a);
			if ($flag){
				return $stmt->fetch(PDO::FETCH_ASSOC);
			}
			sleep(1);
		}
		print_r($this->pdo->errorInfo());
		throw new Exception();
	}
	//存在しない場合のみ
	function addStatus($host_name,$host_port,$status)
	{
		if($status){
			$sql = "insert into status (enable,server_name,server_port,res_retcode,res_title,res_user_a,res_user_m,update_time) values (?,?,?,?,?,?,?,?)";
			$a=array(1,$host_name,$host_port,$status['retcode'],$status['server_name'],$status['active_user'],$status['max_user'],$status['update_time']);
			$ret=array(
				'enable'=>TRUE,
				"retcode"=>$status['retcode'],
				"server_title"=>$status['server_name'],
				"active_user"=>$status['active_user'],
				"max_user"=>$status['max_user'],
				"update_time"=>$status['update_time']);
		}else{
			$sql = "insert into status (enable,server_name,server_port,update_time) values (?,?,?,?)";
			$a=array(0,$host_name,$host_port,time());
			$ret=array(
				'enable'=>FALSE,
				"retcode"=>$status['res_retcode'],
				"update_time"=>time());
		}
		$stmt = $this->pdo->prepare($sql);

		for($i=0;$i<3;$i++){
			$flag = $stmt->execute($a);
			if ($flag){
				return $ret;
			}
			sleep(1);
		}
		print_r($this->pdo->errorInfo());
		throw new Exception();
	}
	function updateStatus($host_name,$host_port,$status)
	{
		if($status){
			$sql = "update status set enable=?,res_retcode=?,res_title=?,res_user_a=?,res_user_m=?,update_time=? where server_name=? AND server_port=?";
			$a=array(1,$status['retcode'],$status['server_name'],$status['active_user'],$status['max_user'],$status['update_time'],$host_name,$host_port);
			$ret=array(
				'enable'=>TRUE,
				"retcode"=>$status['retcode'],
				"server_title"=>$status['server_name'],
				"active_user"=>$status['active_user'],
				"max_user"=>$status['max_user'],
				"update_time"=>$status['update_time']);			
		}else{
			$sql = "update status set enable=?, update_time=? where server_name=? AND server_port=?";
			$a=array(0,time(),$host_name,$host_port);
			$ret=array(
				'enable'=>FALSE,
				"retcode"=>$status['res_retcode'],
				"update_time"=>time());
		}
		$stmt = $this->pdo->prepare($sql);
		for($i=0;$i<3;$i++){
			$flag = $stmt->execute($a);
			if ($flag){
				return $ret;
			}
			sleep(1);
		}
		print_r($this->pdo->errorInfo());
		throw new Exception();
	}
}
class NyMcsApi
{
	var $version='1.0';
	var $mchost;
	var $mcport;
	
	function __construct($mchost,$mcport=25565)
	{
		$this->mchost=$mchost;
		$this->mcport=$mcport;
	}
	function getServerStatus()
	{
		$socket=socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if(!$socket){
			return FALSE;
		}
		socket_set_block($socket);
		if(!socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 3, 'usec' => 0))){
			return FALSE;
		}
		if(!@socket_connect($socket, $this->mchost, $this->mcport)){
			return FALSE;
		}
		$req=pack('C',0xfe);
		if(socket_send($socket ,$req,strlen($req),MSG_EOR)!=strlen($req)){
			return FALSE;
		}
		if(socket_recv($socket ,$buf,2048,MSG_WAITALL)===FALSE){
			return FALSE;
		}
		if($buf===NULL){
			return FALSE;
		}
		socket_close($socket);
		//[0]にコード?1に
		//解析[0]にステータスコード?[1]以降に文字列
		$tmp=unpack("Ccode",$buf);
		$retcode=$tmp['code'];
		mb_regex_encoding("UTF-8");
		$msg=mb_split ("\xC2\xA7",mb_convert_encoding(substr($buf,1),"UTF-8","UTF-16BE"));
		return array("retcode"=>intval($retcode),"server_name"=>$msg[0],"active_user"=>intval($msg[1]),"max_user"=>intval($msg[2]),'update_time'=>time());
	}
}
//main
function main()
{
//入力チェック
switch($_GET["cmd"]){
case 'si':
	if(isset($_GET["s"]) && isset($_GET["p"])){
		$db=new McsCache();
		//ステータスを得てみる。
		$st=$db->getStatus($_GET["s"],$_GET["p"]);
		if(!$st){
			//ステータスキャッシュ無い。
			$inst=new NyMcsApi($_GET["s"],$_GET["p"]);
			$info=$db->addStatus($_GET["s"],$_GET["p"],$inst->getServerStatus());
		}else{
			//ステータスキャッシュある。
			//最終更新時刻から3分経った？
			if(time()-$st['update_time']>60*3){
				//ステータス更新する。
				$inst=new NyMcsApi($_GET["s"],$_GET["p"]);
				$info=$db->updateStatus($_GET["s"],$_GET["p"],$inst->getServerStatus());
			}else{
			//ステータスをキャッシュから作り直す。
			$info=array(
				'enable'=>($st['enable']==1)?TRUE:FALSE,
				"retcode"=>$st['res_retcode'],
				"server_title"=>$st['res_title'],
				"active_user"=>$st['res_user_a'],
				"max_user"=>$st['res_user_m'],
				"update_time"=>$st['update_time']);
			}
		}
		switch($_GET["f"]){
		case 'json':
			header("Access-Control-Allow-Origin: *");
			header("Content-Type: application/json; charset=utf-8");	
			if($info['enable']==TRUE){
				print(
						'{online:true,'.
						'server:{name:"'.$_GET['s'].'",port:'.$_GET['p'].'},'.
						'result:{'.
							'retcode:'.$info['retcode'].','.
							'title:"'.$info['server_title'].'",'.
							'user:{active:'.$info['active_user'].',max:'.$info['max_user'].
							'}},'.
							'update_time:'.$info['update_time'].'}');
			}else{
				print(	'{online:false,server:{name:"'.$_GET['s'].'",port:'.$_GET['p'].'},'.
						'update_time:'.$info['update_time'].'}');

			}
			return;
		default:
			print(
				'<!DOCTYPE html>'.
				'<html>'.
				'<head><meta charset=\"utf-8\"><title>Minecraft server infomation</title></head>'.
				'<body>'.
				'<h1>Minecraft Server status</h1>'.
				'<hr/>'.
				'<h2>Address</h2>'.
				$_GET['s'].':'.$_GET['p']);
			if($info['enable']==TRUE){
				print(
				'<h2>Status</h2>'.
				'ONLINE - '.date( "Y/m/d (D) H:i:s", $info['update_time'] ).'<br/>'.
				'Retcode : '.$info['retcode'].'<br/>'.
				'Title  :'.$info['server_title'].'<br/>'.
				'User : '.$info['active_user'].'/'.$info['max_user'].'<br/>');
			}else{
				print(
				'<h2>Status</h2>'.
				'OFFLINE - '.date( "Y/m/d (D) H:i:s", $info['update_time'] ));
			}
			print('</body>');
		}
		return;
	}
	break;
default:
	break;
}
print("<html>Error</html>");
}

main();

?>
