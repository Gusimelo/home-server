<?php
$url = "https://www.dgeg.gov.pt/media/mlxdcuj1/";
$file = "dgeg-mie-".DATE("Ymd").".xlsx";
$data = file_get_contents($url.$file);

echo $data;
?>