<?php
// This file contains no error or security checking. Use only for internal purposes or in trusted environments.
// You will need to customise lines 12 and 18 (the two starting "$csv[]=array") to your desired output.
// Optionally change the output filename in line 22.
// The example included is for converting a futures trading record from xlsx to csv for import to a different website.

require_once 'SimpleXLSX.php';

$TODAY     =  DATE("Ymd");

$firstrow  = 0;
$url 	   = "https://www.dgeg.gov.pt/";
$webpage   = $url."pt/estatistica/energia/mecanismo-iberico/";
$hass_url  = "https://hass.cogumelo.cc";


$html = new DOMDocument();
@$html->loadHtmlFile($webpage);
$xpath = new DOMXPath( $html );
$nodelist = $xpath->query( '//a[@title="DGEG MIE '.DATE("Ymd").'"]/@href' );
//print_r($nodelist);

$link = false;

foreach ($nodelist as $n){
	$link = $n->nodeValue;
}

if($link) {
	$downloadlink = $url.$link;
	$filename 	  = basename($downloadlink);

	if( !file_exists($filename) ) {
		echo PHP_EOL."Downloading ".$downloadlink;	
		file_put_contents($filename, file_get_contents($downloadlink));
	}
	
}
else {

	foreach(glob("./*.xlsx") as $filename) {
    	
		$files[] = pathinfo(str_replace("dgeg-mie-","",$filename), PATHINFO_FILENAME);
		
	}
	
	$filename =  "dgeg-mie-".max($files).".xlsx";
}

$row 	   = 0;
$data      = array();

if ( $xlsx = SimpleXLSX::parse($filename) ) {

	foreach( $xlsx->rows(1) as $r ) {
		if ($firstrow==0){
// Set the column titles of your output csv file here. If there are no column titles, set "$firstrow=1" in line 7.
			$csv[]=array("Dia","Spot","mibel");
			$firstrow=1;
		}
		else {
			$row++;
			if($row < 6) continue;
			if(DATE("Y", strtotime($r[1])) < 2023) continue;
			
// Edit the column data from the input file as needed to make your output.
// For reference, "$r[0]" corresponds to column A on the first sheet of the xlsx file. "$r[1]" is column B and so on.
			
			$csv[]  	 = array($r[1],$r[2],$r[3]);
			
			if(!isset($data[DATE("M Y", strtotime($r[1]))])) {
				$data[DATE("M Y", strtotime($r[1]))] 		= new stdClass();
				$data[DATE("M Y", strtotime($r[1]))]->days  = 0;
				$data[DATE("M Y", strtotime($r[1]))]->spot  = 0;
				$data[DATE("M Y", strtotime($r[1]))]->mibel = 0;
			}
			
			$data[DATE("M Y", strtotime($r[1]))]->days++;
			$data[DATE("M Y", strtotime($r[1]))]->spot  	+= $r[2];
			$data[DATE("M Y", strtotime($r[1]))]->mibel 	+= $r[3];
		}
	}

// Optionally change the output filename. Current format is "output-2020-09-15.csv"
	$filename = "output-" . date('Y-m-d') . ".csv";
	$out = fopen($filename, 'w');
        	foreach( $csv as $row ) {
			fputcsv($out, $row);
		}
	fseek($out, 0);

	fpassthru($out);
	fclose($out);
}

//print_r($data);die();


//$AR_vazio  = -0.1185;
//$AR_fvazio = -0.0842;
//$desvio	   = 0.004;

// a partir de 01/07/2023 
$AR_vazio  = -0.0349;
$AR_fvazio = -0.0005;
$desvio	   = 0.0065;
$gestao	   = 0.005;
$perdas    = 1.1507*1.02;


$sensors = array();


//echo PHP_EOL.$days;

foreach($data AS $date => $v) {



	$spot   	   = number_format( ($v->spot / $v->days), 2);
	
	$currentspot   = number_format( (($v->spot / $v->days)/1000), 4);
	$currentvazio  = number_format( 1 * ((($currentspot+$desvio) * $perdas) + $gestao + $AR_vazio), 4);
	$currentfvazio = number_format( 1 * ((($currentspot+$desvio) * $perdas) + $gestao + $AR_fvazio), 4);
	$currentmibel  = number_format( (($v->mibel/$v->days)/1000), 4);
	
	$sensors['omie_vazio_'.str_replace(' ', '', strtolower($date))] 	   = new stdClass();
	$sensors['omie_vazio_'.str_replace(' ', '', strtolower($date))]->fname = "Omie ".$date." Vazio";
	$sensors['omie_vazio_'.str_replace(' ', '', strtolower($date))]->price = $currentvazio;

	$sensors['omie_fora_de_vazio_'.str_replace(' ', '', strtolower($date))] 		= new stdClass();
	$sensors['omie_fora_de_vazio_'.str_replace(' ', '', strtolower($date))]->fname  = "Omie ".$date." Fora de Vazio";
	$sensors['omie_fora_de_vazio_'.str_replace(' ', '', strtolower($date))]->price 	= $currentfvazio;
	
	$sensors['mibel_'.str_replace(' ', '', strtolower($date))] 		  = new stdClass();
	$sensors['mibel_'.str_replace(' ', '', strtolower($date))]->fname = "Mibel ".$date;
	$sensors['mibel_'.str_replace(' ', '', strtolower($date))]->price = $currentmibel;

	$sensors['spot_'.str_replace(' ', '', strtolower($date))] 		 = new stdClass();
	$sensors['spot_'.str_replace(' ', '', strtolower($date))]->fname = "Omie Spot ".$date;
	$sensors['spot_'.str_replace(' ', '', strtolower($date))]->price = $currentspot;
}

//print_r($sensors);die();

foreach($sensors AS $s => $n) {

	$url = $hass_url."/api/states/sensor.".$s;
	
	$curl = curl_init($url);

	$post = json_encode( 
				array ( 
					"state" 	 => $n->price,
					"attributes" => array(
										"unit_of_measurement" => "€",
										"friendly_name" 	  => $n->fname
									)
				) 
			);

	//var_dump($post);
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_POSTFIELDS, $post); 

	$headers = array(
	"Accept: application/json",
	"Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiI4ZmQ3MjE5NTgwNGY0OTdjOWI0M2IwNjQwNTM0ZDQzYSIsImlhdCI6MTY3NTE2MjU3OCwiZXhwIjoxOTkwNTIyNTc4fQ.MwUVis5_V8i9R_aqzHxcaJGMhPyYP-sAQ56ELYtEr-g",
	);
	curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

	curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

	$resp = curl_exec($curl);
	curl_close($curl);
	var_dump($resp);
}



?>

