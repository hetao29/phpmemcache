<?php
/**
 * http://code.google.com/p/phpmemcache/
 */
final class CacheObject{
	var $v; //$value
	var $t; //$timestamp
	function __construct($value){
		$this->v = $value;
		$this->t = time();
	}
}
class MemServer{
	static private $_connects	=	array();
	static private $_data		=	array();
	static private $_servers	=	array();

	/**
	 * var $localCache
	 */
	static $localCache=true;

	/**
	 * int $mode
	 */
	static $mode = 1;

	/**
	 * @param int $mode
	 */
	static function setMode($mode){
		self::$mode=$mode;
	}
	/**
	 * @param bool $cache
	 */
	static function setLocalCache($cache){
		self::$localCache=$cache;
	}

	/**
	 * @param string $key
	 * @param string|array  $depKeys
	 * @return mixed
	 */
	static function get($key,$depKeys=null){
			$keys = array($key);
		if(!empty($depKeys)){
			if(is_string($depKeys))$depKeys=array($depKeys);
			if(is_array($depKeys)) $keys =array_merge($keys,$depKeys);
		}
		$values = self::_get($keys);
		if(!isset($values[$key]) || !($values[$key] instanceof CacheObject))return false;
		$value = $values[$key];unset($values[$key]);
		if(!empty($depKeys)){
			if(self::$mode==1){
				foreach($depKeys as $depKey){
					if(	!isset($values[$depKey]) || 
						!($values[$depKey] instanceof CacheObject) || 
						$values[$depKey]->t>$value->t
					) return false;
				}
			}else{
				foreach($values as $k=>$v){
					if(($v instanceof CacheObject) && $v->t > $value->t)return false;
				}
			};
		}
		return $value->v;

	}
	/**
	 * @param string|array $key
	 * @return bool
	 */
	static function delete($key){
		if(is_array($key))foreach($key as $k)return self::delete($k);
		if(self::$localCache && isset(self::$_data[$key]))unset(self::$_data[$key]);
		$id = self::getServer($key);
		$host=self::$_servers[$id]['host'];
		$port=self::$_servers[$id]['port'];
		$memcache_obj = self::_connect($host, $port);
		return memcache_delete($memcache_obj,$key);
	}
	/**
	 * @param string $key
	 * @param mixed $value
	 * @param int $exp
	 */
	static function set($key,$value,$exp){
		$v = new CacheObject($value);
		if(self::$localCache)self::$_data[$key]=$v;
		$id = self::getServer($key);
		$host=self::$_servers[$id]['host'];
		$port=self::$_servers[$id]['port'];
		$memcache_obj = self::_connect($host, $port);
		return memcache_set($memcache_obj,$key,$v,MEMCACHE_COMPRESSED,$exp);
	}

	/**
	 * @param string $host
	 * @param int $port
	 */
	static function addServer($host,$port=11211){
		self::$_servers[]=array("host"=>$host,"port"=>$port);
	}
	static private function _connect($host,$port){
		$index = $host.":".$port;
		if(!isset(self::$_connects[$index])){
			$memcache_obj = memcache_connect($host, $port);
			if($memcache_obj)self::$_connects[$index]=$memcache_obj;
		}
		return self::$_connects[$index];

	}
	static private function _get($keys){
		$servs  = array();
		$values = array();
		foreach($keys as $key){
			if(self::$localCache && isset(self::$_data[$key]) && (self::$_data[$key] instanceof CacheObject)){
				$values[$key]=self::$_data[$key];
			}else{
				$id = self::getServer($key);
				$servs[$id][]=$key;
			}
		}
		foreach($servs as $serid=>$k){
			$host=self::$_servers[$serid]['host'];
			$port=self::$_servers[$serid]['port'];
			$memcache_obj = self::_connect($host, $port);
			$vars = memcache_get($memcache_obj, $k);
			$values = array_merge($values,$vars);
			if(self::$localCache)self::$_data = array_merge(self::$_data,$vars);
		}
		return $values;
	}
	private static function getServer($key){
		$key_md5 = md5($key);
		$ordvalue =hexdec( substr($key_md5,0,3 ))%1000;
		$memcache_id = floor($ordvalue/(1000/count(self::$_servers)));
		return $memcache_id;
	}
}
/*test case*/
/*
MemServer::addServer("10.10.221.12",10006);
MemServer::addServer("10.10.221.12",11211);
MemServer::addServer("10.10.221.12",10001);
MemServer::set("key1","value1",10000);
MemServer::set("key2","value2",10000);
MemServer::set("key3","value3",10000);
MemServer::set("key4","value4",10000);
MemServer::set("key5","value5",10000);
MemServer::set("key6","value6",10000);
MemServer::set("key7","value7",10000);
echo "key1:".(MemServer::get("key1"))."\n";
echo "key2:".(MemServer::get("key2"))."\n";
echo "delete key3\n";
MemServer::delete("key3");
echo "key3:".(MemServer::get("key3"))."\n";
echo "key1:".(MemServer::get("key1","key2")).",depend by key2\n";
echo "key1:".(MemServer::get("key1","key3")).",depend by key3\n";
echo "set localCache false\n";
MemServer::setLocalCache(false);
echo "key1:".(MemServer::get("key1",array("key2","key3"))).",depend by key2,key3\n";
echo "set mode 2\n";
MemServer::setMode(2);
echo "key1:".(MemServer::get("key1",array("key2","key3"))).",depend by key2,key3\n";
*/