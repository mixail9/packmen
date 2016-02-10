<pre>
<?
$arr = Array (
             "KEY0" => "A",
             "KEY1" => Array (
                             "key1_0" => "aa_0", 
                             "key1_1" => "bb_0",
                             "key1_2" => Array (
                                               "KeY1_2_0" => "AAA_0",
                                               "KeY1_2_1" => "BBB_0",
                                               "KeY1_2_2" => "CCC_0",
                                               "KeY1_2_3" => "DDD_0",
                                               "KeY1_2_4" => "EEE_0",
                                               "KeY1_2_5" => "FFF_0",
                                               "KeY1_2_6" => "GGG_0",
                                               ),
                             "key1_3" => Array (
                                               "KeY1_2_0" => "AAA_1",
                                               "KeY1_2_1" => "BBB_1",
                                               "KeY1_2_2" => "CCC_1",
                                               "KeY1_2_3" => "DDD_1",
                                               "KeY1_2_4" => "EEE_1",
                                               "KeY1_2_5" => "FFF_1",
                                               "KeY1_2_6" => "GGG_1",
                                               ),
                             "key1_4" => "cc_0",
                             ), 
             "KEY2" => Array (
                             "key2_0" => "aa_1", 
                             "key2_1" => "bb_1",
                             "key2_2" => Array (
                                               "KeY2_2_0" => "AAA_2",
                                               "KeY2_2_1" => "BBB_2",
                                               "KeY2_2_2" => "CCC_2",
                                               "KeY2_2_3" => "DDD_2",
                                               "KeY2_2_4" => "EEE_2",
                                               "KeY2_2_5" => "FFF_2",
                                               "KeY2_2_6" => "GGG_2",
                                               ),
                             "key2_3" => Array (
                                               "KeY2_2_0" => "AAA_3",
                                               "KeY2_2_1" => "BBB_3",
                                               "KeY2_2_2" => "CCC_3",
                                               "KeY2_2_3" => "DDD_3",
                                               "KeY2_2_4" => "EEE_3",
                                               "KeY2_2_5" => "FFF_3",
                                               "KeY2_2_6" => "GGG_3",
                                               ),
                             "key2_4" => "cc_1",
                             ), 
             "KEY3" => Array (
                             "key1_0" => "aa_2", 
                             "key1_1" => "bb_2",
                             "key1_2" => Array (
                                               "KeY3_2_0" => "AAA_4",
                                               "KeY3_2_1" => "BBB_4",
                                               "KeY3_2_2" => "CCC_4",
                                               "KeY3_2_3" => "DDD_4",
                                               "KeY3_2_4" => "EEE_4",
                                               "KeY3_2_5" => "FFF_4",
                                               "KeY3_2_6" => "GGG_4",
                                               ),
                             "key1_3" => Array (
                                               "KeY3_2_0" => "AAA_5",
                                               "KeY3_2_1" => "BBB_5",
                                               "KeY3_2_2" => "CCC_5",
                                               "KeY3_2_3" => "DDD_5",
                                               "KeY3_2_4" => "EEE_5",
                                               "KeY3_2_5" => "FFF_5",
                                               "KeY3_2_6" => "GGG_5",
                                               ),
                             "key1_4" => "cc_2",
                             ), 
             "KEY4" => "B",
             "KEY5" => "C",
             );


function get($arr, &$keys, &$new, $depth, $curDepth=1)
{
    $result=array();
    foreach($arr as $k=>$v)
	{
		if($depth==$curDepth)
		{
			if((!is_array($v))&&(in_array($k, $keys)))
				$result[]=$v;
		}
		elseif((is_array($v))&&($depth>$curDepth))
		{
			$result[]=get($v, $keys, $new, $depth, $curDepth+1);
		}
	}
	if((empty($result[1]))&&(is_array($result[0])))
		return $result[0];
	else
		return $result;

}

$new_arr=array();
$keys = array('key1_0', 'key1_1', 'key1_2', 'key1_3', 'key1_4', );
//$keys = array('KeY1_2_0', 'KeY1_2_1', 'KeY1_2_2', 'KeY1_2_3', 'KeY1_2_4', 'KeY1_2_5', 'KeY1_2_6', '', );
$keys = array('KeY2_2_0', 'KeY2_2_1', 'KeY2_2_2', 'KeY2_2_3', 'KeY2_2_4', 'KeY2_2_5', 'KeY2_2_6', '', );
print_r(get($arr, $keys, $new_arr, 3));
?>