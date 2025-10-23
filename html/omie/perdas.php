<?php

 $servername = "localhost";
 $username = "hass";
 $password = "fcfheetl";
 $dbname = "energy";
 
 // Create connection
 $conn = new mysqli($servername, $username, $password, $dbname);
 // Check connection
 if ($conn->connect_error) {
   die("Connection failed: " . $conn->connect_error);
 }
 
 $ano_atual = date('Y');
 $sql = "SELECT * from perdas_erse_{$ano_atual} WHERE data_hora = (SELECT cast(NOW() as date) + interval hour(now()) hour + interval (floor(minute(now()) / 15) * 15) minute)";
 $result = $conn->query($sql);
 
 $data = new stdClass();

 if ($result->num_rows > 0) {
   // output data of each row
   while($row = $result->fetch_assoc()) {
     $data->date_time =  $row["data_hora"]; 
     $data->valor     =  $row["BT"];
   }
 } else {
    $data->error = "error";
 }
 $conn->close();

 echo json_encode($data);

?>
