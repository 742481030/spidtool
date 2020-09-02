<?php
if($_GET["code"]==""){
    echo "不保存任何数据只是为了获取tokne接口<br>";
    echo' <a href="https://login.partner.microsoftonline.cn/common/oauth2/v2.0/authorize?client_id=3447f073-eef3-4c60-bb68-113a86f2c39a&scope=offline_access+files.readwrite.all+Sites.ReadWrite.All&response_type=code&redirect_uri=https://coding.mxin.ltd&state=https://coding.mxin.ltd/authorize.php">授权登陆世纪互联</a><br>
    <br>
    <br>
    <a href="https://login.microsoftonline.com/common/oauth2/v2.0/authorize?client_id=108d697a-13e1-46b8-9761-b3005a022d5d&scope=offline_access+files.readwrite.all+Sites.ReadWrite.All&response_type=code&redirect_uri=https://coding.mxin.ltd&state=https://coding.mxin.ltd/authorize-us.php">授权登陆国际版</a>
    
    ';
    
}else{


$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => "https://login.partner.microsoftonline.cn/common/oauth2/v2.0/token",
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "GET",
  CURLOPT_POSTFIELDS => "grant_type=authorization_code&client_id=3447f073-eef3-4c60-bb68-113a86f2c39a&client_secret=v4%5BNq%3A4%3DrmFS78BwYi%5B@x3sGk-iY.U%3AS&code=".$_GET["code"]."&%20redirect_uri=https%3A//coding.mxin.ltd",
  CURLOPT_HTTPHEADER => array(
    "Content-Type: application/x-www-form-urlencoded"
    
  ),
));

$response = curl_exec($curl);

curl_close($curl);

    $response =json_decode($response );
    
    header('Content-type:application/json'); 
   echo json_encode($response,JSON_PRETTY_PRINT);

    
 
    
    
    
    
    
    
}
