<?php
#$tender = get_tender('3.13.31.81', '3500500072');
if(isset($argv[1]))
	$start = $argv[1];
else
	$start = date('Ymd');//"20160101";
if(isset($argv[2]))
	$range = $argv[2];
else 
	$range = 4;
for($i=0;$i < $range;$i++) {
	$diff = $range - $i - 1;
	$date = date('Ymd', strtotime("{$start} -{$diff} days"));
	echo $date, "\n";
	pcc($date);
}
#$mongo = new MongoClient();
#$award = $mongo->selectCollection('pcc', 'award');

function pcc($date) {
	$result = get_list($date);

	if(empty($result))
		return null;
	$types = array_unique(array_map(function($row) {
		return $row['brief']['type'];
	}, $result));
	$tenders = array_filter($result, function($row) {
		return in_array($row['brief']['type'], array('公開招標更正公告', '公開招標公告', '公開取得報價單或企劃書更正公告', '限制性招標(經公開評選或公開徵求)公告'));
	});

	$tenders = array_map(function($row) use($date) {
		preg_match('/^\w+?-(?:\d+-)?(\d+)$/', $row['filename'], $match);
		$id = $match[1];
		preg_match('/([^\d]+?)\d+-([^\d]+)/', $row['brief']['category'], $match);
		$category = $match[1];
		$sub_category = $match[2];
		$tender = get_tender($row['unit_id'], $row['job_number'], $date);
		
		if(empty($tender['detail']['採購資料:預算金額']))
			$price = null;
		else
			$price = intval(str_replace(",", "", $tender['detail']['採購資料:預算金額']));

		return array(
			'_id' => $row['filename'], 
			'unit' =>  $row['unit_name'],
			'key' => $row['filename'], 
			'filename' => $row['filename'],
			'unit_id' => $row['unit_id'],
			'id' => $row['job_number'],
			'job_number' => $row['job_number'],
			'name' => $row['brief']['title'],
			'type' => $row['brief']['type'],
			'category' => $category,
			'sub_category' => $sub_category,
			'price' => $price ? new MongoInt64($price): null,
			'publish' => new MongoDate(strtotime($date))
		);
	}, $tenders);

	$mc = new MongoClient();
	$collection = $mc->selectCollection('pcc', 'pcc');
	$batch = new MongoUpdateBatch($collection);
	foreach($tenders as $tender) {
		$update = array(
			'q' => array('_id' => $tender['_id']),
			'u' => array('$set' => $tender),
			'multi' => true,
			'upsert' => true,
		);
		$batch->add((object) $update);
	}
	try {
		$r = $batch->execute();
	} catch(Exception $e) {
		$r = null;
	}
	echo $date . ' tender upserted ' . $r['nUpserted'], ' nModified ', $r['nModified'], "\n";
	$awards = array_filter($result, function($row) {
		return in_array($row['brief']['type'], array('決標公告', '更正決標公告'));
	});
	
	$awards = array_filter(array_map(function($row) use($date) {
		
		$award = get_award($row['unit_id'], $row['job_number'], $date);
		$tender = get_tender($row['unit_id'], $row['job_number'], $date);
		
		if(empty($tender) || empty($award))
			return NULL;
		#preg_match('/^\w+?-\d-(\d+)$/', $tender['filename'], $match);
		preg_match('/^\w+?-(?:\d+-)?(\d+)$/', $tender['filename'], $match);
		$id = $match[1];
		if(empty($tender['detail']['採購資料:預算金額']))
			$price = null;
		else
			$price = intval(str_replace(",", "", $tender['detail']['採購資料:預算金額']));

		$award['end_date'] = null;
		if(isset($award['detail']['決標資料:決標公告日期'])) {
			$award['end_date'] = new MongoDate(strtotime(preg_replace_callback('/(\d+)\/(\d+)\/(\d+)/', function($row) {
				return ($row[1] + 1911) . '-' . $row[2] . '-' . $row[3];
			}, $award['detail']['決標資料:決標公告日期'])));
		}
		
		
		preg_match('/([^\d]+?)\d+-([^\d]+)/', $tender['brief']['category'], $match);
		
		$candidates = array_map(function($row) {
			
			preg_match('/^(\w+)/u', @$row['廠商業別'], $match_industry);
			return array(
				'_id' => @$row['廠商代碼']?:$row['廠商名稱'],
				'name' => $row['廠商名稱'],
				'awarding' => new MongoInt32($row['是否得標'] =='是' ? 1: 0),
				'org' => @$row['組織型態'],
				'industry'=> @$match_industry[1],
				"address" => @$row['廠商地址'],
				"phone" => @$row['廠商電話'],
				"country" => @$row['得標廠商國別'],
				"amount" => isset($row['決標金額']) ? new MongoInt32(intval(str_replace(",", "", @$row['決標金額']))): null
			);
		}, isset($award['data']['投標廠商']) && is_array($award['data']['投標廠商']['投標廠商']) ? $award['data']['投標廠商']['投標廠商'] : array());
		

		$category = $match[1];
		$sub_category = $match[2];
	
		return array( 
			'_id' => $row['filename'], 
			'unit' =>  $row['unit_name'],
			'key' => $id, 
			'id' => $row['job_number'],
			'job_number' => $row['job_number'],
			'filename' => $row['filename'],
			'unit_id' => $row['unit_id'],
			'name' => $row['brief']['title'],
			'type' => $row['brief']['type'],
			'category' => $category,
			'sub_category' => $sub_category,
			'url' => $award['data']['url'],
			'candidates' => array_values($candidates),
			'merchants' => array_values(array_filter($candidates, function($row) {
				return intval($row['awarding'] . '');
			})),
			//'publish' => new MongoDate(strtotime($date)),
			'end_date' => $award['end_date']
		);
	}, $awards));
	$mc = new MongoClient();
	$collection = $mc->selectCollection('pcc', 'award');
	$batch = new MongoUpdateBatch($collection);
	foreach($awards as $award) {
		$update = array(
			'q' => array('_id' => $award['_id']),
			'u' => array('$set' => $award),
			'multi' => true,
			'upsert' => true,
		);
		$batch->add((object) $update);
	}
	try {
	$r = $batch->execute();
	} catch(Exception $e) {
		$r = null;
	}
	echo $date . ' award upserted ' . $r['nUpserted'], "\n";
	$collection = $mc->selectCollection('pcc', 'pcc');
	$batch = new MongoUpdateBatch($collection);
	foreach($awards as $award) {
		if(empty($award['end_date']))
			continue;
		$update = array(
			'q' => array('job_number' => $award['job_number'], 'unit_id' => $award['unit_id']),
			'u' => array('$set' => array('end_date' => $award['end_date'], 'award' => $award)),
			'multi' => true
		);
		$batch->add((object) $update);
	}
	try {
		$r = $batch->execute();
	}
	catch(Exception $e) {
		$r = null;
	}
	echo $date . ' tender end_date upserted ' . $r['nUpserted'], "\n";
}
function get_list($date) {
	$base = "https://pcc.g0v.ronny.tw/api/listbydate?date=";
	$api = $base . $date;
	if(file_exists("list/{$date}.json")) {
		$result = json_decode(file_get_contents("list/{$date}.json"), TRUE);
	}
	else {
		$result = json_decode(file_get_contents($api), TRUE);
		file_put_contents("list/{$date}.json", json_encode($result));
	}
	if($result)
		return $result['records'];
	else
		return array();
}
function get_tender_api($unit_id, $job_number, $date = FALSE)
{
	if(file_exists("tender/{$unit_id}/{$job_number}.json") && filesize("tender/{$unit_id}/{$job_number}.json") > 0) {
		$json = file_get_contents("tender/{$unit_id}/{$job_number}.json");
		$res = json_decode($json, TRUE);
		
		$max_date = max(array_map(function($r) {
			return $r['date'];
		}, $res['records']));
		if($date && strtotime($max_date) < strtotime($date))
			return get_tender_api_real($unit_id, $job_number);
		else 
			return $res;
	}
	else {
		return get_tender_api_real($unit_id, $job_number);
	}
}
function get_tender_api_real($unit_id, $job_number) {
	$url = "https://pcc.g0v.ronny.tw/api/tender?unit_id={$unit_id}&job_number={$job_number}";
	
	$json = file_get_contents($url);
	echo $url, " downloaded\n";
	$result = json_decode($json, TRUE);
	if(!file_exists("tender/{$unit_id}"))
		mkdir("tender/{$unit_id}");
	file_put_contents("tender/{$unit_id}/{$job_number}.json", $json);
	return $result;
} 
function get_tender($unit_id, $job_number, $date = FALSE) {
	$result = get_tender_api($unit_id, $job_number, $date);
	$record = array_pop(array_filter($result['records'], function($row) {
		return in_array($row['brief']['type'], array('公開招標更正公告', '公開招標公告', '公開取得報價單或企劃書更正公告', '限制性招標(經公開評選或公開徵求)公告'));
	}));
	return $record;
}

function get_award($unit_id, $job_number, $date = FALSE) {
	$result = get_tender_api($unit_id, $job_number, $date);

	$record = array_pop(array_filter($result['records'], function($row) {
		return in_array($row['brief']['type'], array('決標公告', '無法決標公告'));
	}));
	$data = [];
	if(!is_array($record['detail']))
		return NULL;
	foreach($record['detail'] as $key => $value) {
		if(preg_match('/remind/', $key) || preg_match('/:理由$/', $key) || preg_match('/目的事業主管機關核准文號/', $key))
			continue;
		$split = explode(":", preg_replace('/(\d):/', ':$1:', $key));
		$cursor = &$data;
		foreach($split as $i => $s) {
			if(count($split) == $i +1 && $value && !is_string($cursor)) 
				$cursor[$s] = $value;
			else if(count($split) != $i +1 && empty($cursor[$s])) {
				$cursor[$s] = array();
			}
			else if(count($split) == $i +1){
				#echo $key, " ", $s, " [", $value, "]\n";
				continue;
			}
			$cursor = &$cursor[$s];
		}
	}
	$record['data'] = $data;
	return $record;
}
